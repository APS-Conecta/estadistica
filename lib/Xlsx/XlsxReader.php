<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Xlsx;

use Generator;
use OCA\Estadistica\Exception\RefusedException;
use XMLReader;
use ZipArchive;

/**
 * The cell values of an Excel workbook (.xlsx or .xlsm), read with PHP's own
 * ZipArchive and XMLReader. The two workbooks this app reads are DEIS's: each
 * year's DICCIONARIO CODIGOS (DictionaryParser) and the Base de
 * Establecimientos (RegisterParser).
 *
 * A sheet is streamed row by row, never parsed whole. Shared strings are held,
 * because any cell may point at any of them (the 2025 register: 543 KB of
 * them, beside a 5.5 MB sheet).
 *
 * ponytail: values only. A number is its stored text ('200658',
 * '-20.21915098'), a date is Excel's day count, and styles, formulas and number
 * formats are not read. Merged ranges are, because the dictionaries' headers
 * are merged blocks. The upgrade, when M2's staff files need dates and styles,
 * is OpenSpout (docs/adr, M2 contract notes).
 */
final class XlsxReader {
	private const NOT_A_WORKBOOK = 'El archivo no es una planilla Excel (.xlsx).';

	/**
	 * What a workbook may inflate to. The largest DEIS workbook measured
	 * inflates to 6 MB (the 2025 register), so this refuses a zip bomb, not a
	 * workbook.
	 */
	private const MAX_BYTES = 256 * 1024 * 1024;

	/**
	 * @param array<string, string> $sheets sheet name → its part inside the zip, in workbook order
	 * @param list<string> $strings the shared strings
	 */
	private function __construct(
		private string $path,
		private array $sheets,
		private array $strings,
	) {
	}

	/** @throws RefusedException */
	public static function open(string $path): self {
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			throw new RefusedException(self::NOT_A_WORKBOOK);
		}

		try {
			$inflated = 0;
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$inflated += (int)($zip->statIndex($i)['size'] ?? 0);
			}
			if ($inflated > self::MAX_BYTES) {
				throw new RefusedException('La planilla es demasiado grande para leerla (más de 256 MB descomprimida).');
			}

			$workbook = $zip->getFromName('xl/workbook.xml');
			$relations = $zip->getFromName('xl/_rels/workbook.xml.rels');
			$hasStrings = $zip->locateName('xl/sharedStrings.xml') !== false;
		} finally {
			$zip->close();
		}

		if (!is_string($workbook) || !is_string($relations)) {
			throw new RefusedException(self::NOT_A_WORKBOOK);
		}

		return new self(
			$path,
			self::sheetsOf($workbook, $relations),
			$hasStrings ? self::sharedStrings($path) : [],
		);
	}

	/** @return list<string> the sheet names, in workbook order */
	public function sheets(): array {
		return array_keys($this->sheets);
	}

	/**
	 * The sheet's non-empty cells, row by row: row number → [column number
	 * (A = 1) → value]. An error cell (#VALUE!, #REF!…) is left out, as if it
	 * were empty.
	 *
	 * @return Generator<int, array<int, string>>
	 * @throws RefusedException
	 */
	public function rows(string $sheet): Generator {
		$reader = $this->part($sheet);
		try {
			$row = 0;
			$cells = [];
			$column = 0;
			$type = '';
			$value = null;

			while ($reader->read()) {
				if ($reader->nodeType === XMLReader::ELEMENT) {
					switch ($reader->localName) {
						case 'row':
							$row = (int)($reader->getAttribute('r') ?? $row + 1);
							$cells = [];
							$column = 0;
							break;
						case 'c':
							$reference = $reader->getAttribute('r');
							$column = $reference === null ? $column + 1 : self::column($reference);
							$type = $reader->getAttribute('t') ?? 'n';
							$value = null;
							break;
						case 'v':
						case 'is':
							// <is> is an inline string: its text is all of its runs.
							$value = $reader->readString();
							break;
					}
				} elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
					if ($reader->localName === 'c' && $value !== null && $type !== 'e') {
						$cells[$column] = $type === 's' ? ($this->strings[(int)$value] ?? '') : $value;
					} elseif ($reader->localName === 'row' && $cells !== []) {
						yield $row => $cells;
					} elseif ($reader->localName === 'sheetData') {
						return;
					}
				}
			}
		} finally {
			$reader->close();
		}
	}

	/**
	 * The sheet's merged ranges. Only the first cell of a range holds its value.
	 *
	 * @return list<array{int, int, int, int}> [first row, first column, last row, last column]
	 * @throws RefusedException
	 */
	public function merges(string $sheet): array {
		$reader = $this->part($sheet);
		$merges = [];
		try {
			while ($reader->read()) {
				if ($reader->nodeType !== XMLReader::ELEMENT) {
					continue;
				}
				if ($reader->localName === 'sheetData') {
					// The cells are rows() business: step over them whole.
					$reader->next();
					continue;
				}
				if ($reader->localName === 'mergeCell') {
					$range = explode(':', (string)$reader->getAttribute('ref'));
					if (count($range) === 2) {
						$merges[] = [self::row($range[0]), self::column($range[0]), self::row($range[1]), self::column($range[1])];
					}
				}
			}
		} finally {
			$reader->close();
		}

		return $merges;
	}

	/** 'AB12' → 28. */
	private static function column(string $reference): int {
		$column = 0;
		foreach (str_split(strtoupper((string)preg_replace('/\d+$/', '', $reference))) as $letter) {
			$column = $column * 26 + ord($letter) - 64;
		}

		return $column;
	}

	/** 'AB12' → 12. */
	private static function row(string $reference): int {
		return (int)preg_replace('/^[A-Za-z]+/', '', $reference);
	}

	/**
	 * @return array<string, string>
	 * @throws RefusedException
	 */
	private static function sheetsOf(string $workbook, string $relations): array {
		$targets = [];
		foreach (self::document($relations)->getElementsByTagNameNS('*', 'Relationship') as $relation) {
			$target = $relation->getAttribute('Target');
			// A target is relative to xl/ unless it says otherwise.
			$targets[$relation->getAttribute('Id')] = str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target;
		}

		$sheets = [];
		foreach (self::document($workbook)->getElementsByTagNameNS('*', 'sheet') as $sheet) {
			// Transitional OOXML, which is what DEIS writes, or Strict.
			$id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
				?: $sheet->getAttributeNS('http://purl.oclc.org/ooxml/officeDocument/relationships', 'id');
			if (isset($targets[$id])) {
				$sheets[$sheet->getAttribute('name')] = $targets[$id];
			}
		}
		if ($sheets === []) {
			throw new RefusedException('La planilla no trae hojas.');
		}

		return $sheets;
	}

	/**
	 * Each <si> is one string: its text, or the text of its runs. Phonetic
	 * guides (<rPh>) are not part of the text.
	 *
	 * @return list<string>
	 * @throws RefusedException
	 */
	private static function sharedStrings(string $path): array {
		$reader = self::xml($path, 'xl/sharedStrings.xml');
		$strings = [];
		$current = null;
		$phonetic = 0;
		try {
			while ($reader->read()) {
				if ($reader->nodeType === XMLReader::ELEMENT) {
					if ($reader->localName === 'si') {
						if ($reader->isEmptyElement) {
							$strings[] = '';
						} else {
							$current = '';
						}
					} elseif ($reader->localName === 'rPh' && !$reader->isEmptyElement) {
						$phonetic++;
					} elseif ($reader->localName === 't' && $current !== null && $phonetic === 0) {
						$current .= $reader->readString();
					}
				} elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
					if ($reader->localName === 'si') {
						$strings[] = (string)$current;
						$current = null;
					} elseif ($reader->localName === 'rPh') {
						$phonetic--;
					}
				}
			}
		} finally {
			$reader->close();
		}

		return $strings;
	}

	/** @throws RefusedException */
	private function part(string $sheet): XMLReader {
		if (!isset($this->sheets[$sheet])) {
			throw new RefusedException(sprintf('La planilla no trae la hoja «%s».', $sheet));
		}

		return self::xml($this->path, $this->sheets[$sheet]);
	}

	/**
	 * A part of the zip, streamed. No network, and entities are left as they
	 * are (no LIBXML_NOENT), so a hostile workbook cannot reach anything.
	 *
	 * @throws RefusedException
	 */
	private static function xml(string $path, string $part): XMLReader {
		$reader = new XMLReader();
		if (@$reader->open('zip://' . $path . '#' . $part, null, LIBXML_NONET | LIBXML_COMPACT) !== true) {
			throw new RefusedException(self::NOT_A_WORKBOOK);
		}

		return $reader;
	}

	/** @throws RefusedException */
	private static function document(string $xml): \DOMDocument {
		$document = new \DOMDocument();
		if (@$document->loadXML($xml, LIBXML_NONET) !== true) {
			throw new RefusedException(self::NOT_A_WORKBOOK);
		}

		return $document;
	}
}
