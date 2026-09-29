<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Catalog;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Xlsx\XlsxReader;

/**
 * A year's DICCIONARIO CODIGOS, read into what each código and column means.
 *
 * The dictionary is the REM planilla itself, one sheet per hoja (A01…A34,
 * P1…P13), with the código de prestación in column A of every row that
 * reports figures, and a literal «COL01», «COL02»… in the cell where each
 * figure goes. Measured on the 2017, 2020, 2023, 2025 and 2026 dictionaries
 * (design, 2026-09-28), and so these rules:
 *
 * - **A section** starts at a row whose first text reads «SECCIÓN …»
 *   (or «SECCION …»).
 * - **Its header** is the rows between that title and its first data row. The
 *   upper levels are merged blocks («RANGO ETARIO Y SEXO» over the age
 *   columns). It holds for the whole section, unless text appears again above
 *   the markers' own columns: that is a new header for the rows below it
 *   (2020 SP, P6 «B. …»).
 * - **A data row** carries a código in column A and at least one «COLnn»
 *   marker. The prestación's label is the text to the left of the first
 *   marker, and a label merged down over several rows belongs to each
 *   («Reevaluación» beside «Normal (desde riesgo)»).
 * - **A group row** has text only to the left of the markers, between data
 *   rows or just above the first. It names the rows that follow, so
 *   04021101 and 04021102 read «Horario continuado › Médico/a» and «Sábado,
 *   Domingo o festivo › Médico/a». A «Total» row closes the group, and text to
 *   the right of the markers is a note.
 * - **A column's label** is the header text above its marker, one level per
 *   header row: «RANGO ETARIO Y SEXO › Menos de 7 meses › Hombres».
 * - **A código typed as a number** lost its leading zero in Excel (2026 SA:
 *   6900300 for 06900300), and REM códigos have at least eight characters, so
 *   a numeric one is padded back to eight.
 * - **A código listed twice** keeps its first row (2026 SA repeats two in A08).
 *   A row with a código and no marker is one DEIS lists as eliminated, and a
 *   row with markers and no código reports nothing: neither is a label.
 *
 * Measured with these rules: no two códigos of a year share a label (2 in 2023
 * SA), at most 10 of ≈ 87,000 column labels are empty because the planilla
 * leaves the header cell blank (2025–2026 A04 §H.1), and 0–3 of the códigos
 * the REM rows carry have no label (2017, 2023, 2025, 2026 Serie A; 2026
 * Serie P: 0). The catalogue counts those as «sin descripción».
 *
 * @psalm-type Entry = array{codigo: string, hoja: string, seccion: string, prestacion: string, columnas: array<int, string>}
 * @psalm-type Parsed = array{hojas: array<string, string>, entries: list<Entry>, duplicates: int}
 */
final class DictionaryParser {
	/**
	 * A Serie A or P dictionary DEIS ships inside the year's zip, as a workbook
	 * this app can read (2017 onward): «Diccionarios/DICCIONARIO CODIGOS
	 * SA_26_V1.2.xlsm», «DICCIONARIOS/SA-17_V1.5_CODIGOS.xlsm», «SP_20_V1.0
	 * CODIGOS.xlsm»… The letters after the S must be A or P and a two-digit
	 * year must follow, which SBS, SBM, SD and BM never do. 2009–2016 ship
	 * BIFF .xls, which is not read: those years borrow their labels
	 * (Catalog).
	 */
	public const MEMBER = '~(?:^|/)diccionarios/(?:[^/]*[\s_-])?s([ap])[-_ ]?\d{2}[^/]*\.xls[xm]$~i';

	/** A hoja of Serie A or P: A01, A11a, A19b, A30AR, P1, P13. NOMBRE, Control and MACROS are not. */
	private const SHEET = '/^[AP]\d/i';

	private const MARKER = '/^COL(\d{2})$/';

	private const SECTION = '/^SECCI[OÓ]N\b/iu';

	/** The sheet's own title, above its first section: «REM-A03. APLICACIÓN Y RESULTADOS…». */
	private const TITLE = '/^REM[\s.-]/i';

	private const CODIGO = '/^[0-9A-Z]{6,16}$/i';

	private const TOTAL = '/^(sub)?total\b/i';

	/** Where one level of a label ends and the next begins. */
	public const SEPARATOR = ' › ';

	/**
	 * The dictionary of Serie $serie in the zip, or null when the zip has none
	 * this app can read.
	 *
	 * @return ?array{name: string, flags: int, method: int, crc: int, compressedSize: int, size: int, offset: int}
	 * @throws RefusedException the zip has two, and which one holds is not guessed
	 */
	public static function member(ZipDirectory $zip, string $serie): ?array {
		$matches = array_values(array_filter(
			$zip->members(),
			static fn (array $member): bool => preg_match(self::MEMBER, $member['name'], $match) === 1
				&& strtoupper($match[1]) === $serie,
		));

		if (count($matches) > 1) {
			throw new RefusedException(sprintf(
				'El ZIP trae más de un diccionario de la Serie %s (%s): no se sabe cuál usar.',
				$serie,
				implode(', ', array_map(static fn (array $member): string => self::fileName($member['name']), $matches)),
			));
		}

		return $matches[0] ?? null;
	}

	/**
	 * A member's file name, printable. Zip names are CP437 bytes and not
	 * flagged UTF-8 (research: «CαDIGOS»), and this one is shown to people.
	 */
	public static function fileName(string $member): string {
		return (string)preg_replace('/[^\x20-\x7E]/', '?', basename($member));
	}

	/**
	 * @return Parsed
	 * @throws RefusedException
	 */
	public static function parse(XlsxReader $xlsx): array {
		$hojas = [];
		$entries = [];
		$seen = [];
		$duplicates = 0;

		foreach ($xlsx->sheets() as $hoja) {
			if (preg_match(self::SHEET, $hoja) !== 1) {
				continue;
			}

			$cells = iterator_to_array($xlsx->rows($hoja));
			$origins = self::origins($xlsx->merges($hoja));
			$hojas[$hoja] = '';
			$section = '';
			$header = [];
			$collecting = true;
			$group = '';
			/** @var array<int, true> $markerColumns the columns this section's markers sit in */
			$markerColumns = [];

			foreach ($cells as $row => $values) {
				[$markers, $texts] = self::split($values);

				if ($markers === []) {
					$first = reset($texts) ?: '';
					if ($section === '' && $hojas[$hoja] === '' && preg_match(self::TITLE, $first) === 1) {
						// What came above the title is the planilla's own form
						// (SERVICIO DE SALUD, COMUNA, MES…), not a header.
						$hojas[$hoja] = $first;
						$header = [];
					} elseif (preg_match(self::SECTION, $first) === 1) {
						$section = $first;
						$header = [];
						$collecting = true;
						$group = '';
						$markerColumns = [];
					} elseif ($texts === []) {
						continue;
					} elseif ($collecting) {
						$header[] = $row;
					} elseif (array_intersect_key($texts, $markerColumns) !== []) {
						// Text above the markers' own columns: a new header,
						// for the rows that follow (SP P6 §A.3 «B. …»).
						$header = [$row];
						$collecting = true;
						$group = '';
					} else {
						// Text only to the left of the markers names the rows
						// that follow («Sábado, Domingo o festivo»); a «Total»
						// row closes the group. Text to the right is a note.
						$group = self::group(array_filter(
							$texts,
							static fn (int $column): bool => $column < min(array_keys($markerColumns)),
							ARRAY_FILTER_USE_KEY,
						));
					}
					continue;
				}

				if ($collecting) {
					// The header's last rows, when their text sits only to the
					// left of the markers, name the first rows' group rather
					// than any column («Horario continuado»).
					$firstMarker = min(array_keys($markers));
					while ($header !== []) {
						$above = self::split($cells[$header[array_key_last($header)]])[1];
						if ($above === [] || max(array_keys($above)) >= $firstMarker) {
							break;
						}
						$group = $group === '' ? self::group($above) : $group;
						array_pop($header);
					}
					$collecting = false;
				}
				$markerColumns += array_fill_keys(array_keys($markers), true);

				$codigo = self::codigo($values[1] ?? '');
				if ($codigo === null) {
					continue;
				}
				if (isset($seen[$codigo])) {
					$duplicates++;
					continue;
				}
				$seen[$codigo] = true;

				$columnas = [];
				foreach ($markers as $column => $number) {
					$columnas[$number] = self::label(array_map(
						static fn (int $headerRow): string => self::at($cells, $origins, $headerRow, $column),
						$header,
					));
				}
				ksort($columnas);

				$entries[] = [
					'codigo' => $codigo,
					'hoja' => $hoja,
					'seccion' => $section,
					'prestacion' => self::label([$group, ...array_map(
						static fn (int $column): string => self::at($cells, $origins, $row, $column),
						range(2, max(2, min(array_keys($markers)) - 1)),
					)]),
					'columnas' => $columnas,
				];
			}
		}

		if ($entries === []) {
			throw new RefusedException('El diccionario no trae ningún código con sus columnas: no tiene la forma de un DICCIONARIO CODIGOS del DEIS.');
		}

		return ['hojas' => $hojas, 'entries' => $entries, 'duplicates' => $duplicates];
	}

	/**
	 * A row's «COLnn» markers (column → figure number) and its text (column →
	 * text, column A left out: that is the código's).
	 *
	 * @param array<int, string> $values
	 * @return array{array<int, int>, array<int, string>}
	 */
	private static function split(array $values): array {
		$markers = [];
		$texts = [];
		foreach ($values as $column => $value) {
			$value = self::clean($value);
			if (preg_match(self::MARKER, $value, $match) === 1) {
				$markers[$column] = (int)$match[1];
			} elseif ($column > 1 && self::isText($value)) {
				$texts[$column] = $value;
			}
		}
		ksort($texts);

		return [$markers, $texts];
	}

	/**
	 * A group's name, or none for a «Total» row: that closes a group.
	 *
	 * @param array<int, string> $texts
	 */
	private static function group(array $texts): string {
		$label = self::label(array_values($texts));

		return preg_match(self::TOTAL, $label) === 1 ? '' : $label;
	}

	/**
	 * Every cell a merged range covers, pointing at the range's first cell,
	 * which is the only one that holds the value.
	 *
	 * @param list<array{int, int, int, int}> $merges
	 * @return array<string, array{int, int}> "row:column" → [row, column]
	 */
	private static function origins(array $merges): array {
		$origins = [];
		foreach ($merges as [$firstRow, $firstColumn, $lastRow, $lastColumn]) {
			for ($row = $firstRow; $row <= $lastRow; $row++) {
				for ($column = $firstColumn; $column <= $lastColumn; $column++) {
					$origins["$row:$column"] = [$firstRow, $firstColumn];
				}
			}
		}

		return $origins;
	}

	/**
	 * The text a person sees at (row, column): a merged block shows its first
	 * cell's value everywhere it spans.
	 *
	 * @param array<int, array<int, string>> $cells
	 * @param array<string, array{int, int}> $origins
	 */
	private static function at(array $cells, array $origins, int $row, int $column): string {
		[$row, $column] = $origins["$row:$column"] ?? [$row, $column];
		$value = self::clean($cells[$row][$column] ?? '');

		return self::isText($value) && preg_match(self::MARKER, $value) !== 1 ? $value : '';
	}

	/**
	 * Levels joined, top first, a level repeated by a merged block said once.
	 *
	 * @param list<string> $levels
	 */
	private static function label(array $levels): string {
		$label = [];
		foreach ($levels as $level) {
			if ($level !== '' && end($label) !== $level) {
				$label[] = $level;
			}
		}

		return implode(self::SEPARATOR, $label);
	}

	private static function codigo(string $value): ?string {
		$value = trim($value);
		if (ctype_digit($value) && strlen($value) < 8) {
			$value = str_pad($value, 8, '0', STR_PAD_LEFT);
		}

		return preg_match(self::CODIGO, $value) === 1 ? strtoupper($value) : null;
	}

	/** A label, not a figure: the dictionaries' «Total» rows carry formula zeros. */
	private static function isText(string $value): bool {
		return $value !== '' && !is_numeric($value);
	}

	/** Line breaks and runs of spaces inside a cell are layout, not meaning. */
	private static function clean(string $value): string {
		return trim((string)preg_replace('/\s+/u', ' ', $value));
	}
}
