<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use Generator;
use OCA\Estadistica\Exception\RefusedException;

/**
 * The rows of one Serie A or P member, normalized across the eras DEIS has
 * published in (research §DEIS REM extract):
 *
 * - the same 57-column header every year, compared case-insensitively (2009
 *   writes col42…col50 in lowercase) once the BOM 2025 Serie A carries is gone;
 * - the establishment as its 6-digit code: 2009–2013 write SS-LLL, which is
 *   1SSLLL in the 6-digit numbering (2,086 of 2,097 match, 2013 P vs 2014);
 * - `,` decimals (2015–2022, exponent forms included) read as `.`;
 * - an empty cell is null, never 0;
 * - servicio is read only to profile the member (the establishments reporting
 *   per servicio, for Completeness). Servicio, región and comuna are never
 *   returned for storage.
 *
 * A row that does not fit is skipped and counted, never guessed at: a row
 * without 57 fields, a code or month that is not one, a cell that is not a
 * number. Completeness turns the counts into the «incompleto» detail.
 *
 * These refuse the whole member instead (RefusedException, nothing written):
 * a header that is not REM's, a first row of another year, or no rows at all.
 *
 * rows() RETURNS the member's summary once it is exhausted.
 *
 * @psalm-type Row = list<int|string|null>
 * @psalm-type Summary = array{rows: int, lastMonth: int, cut: bool, malformed: int, otherYear: int, profile: array<string, int>}
 */
final class RemReader {
	/** The extract's Col01…Col50: the typed table's figures. */
	public const COLUMNS = 50;

	private const FIELDS = 7 + self::COLUMNS;

	private const NOT_REM = 'El archivo no tiene el encabezado de una serie REM del DEIS (Mes;IdServicio;Ano;IdEstablecimiento;CodigoPrestacion;IdRegion;IdComuna;Col01…Col50).';

	private const NO_ROWS = 'El archivo no trae filas de datos.';

	/**
	 * Row = [mes, estab, codigo, col01 … col50], the order RemLoader inserts in.
	 *
	 * @param Generator<int, string, mixed, bool> $lines MemberLines::of()
	 * @return Generator<int, Row, mixed, Summary>
	 * @throws RefusedException
	 */
	public static function rows(Generator $lines, int $year): Generator {
		self::header($lines);

		$rows = 0;
		$malformed = 0;
		$otherYear = 0;
		$lastMonth = 0;
		$yearConfirmed = false;
		/** @var array<string, array<int, true>> $establishments servicio → estab → seen */
		$establishments = [];

		for (; $lines->valid(); $lines->next()) {
			$line = $lines->current();
			if ($line === '') {
				continue;
			}

			$fields = explode(';', $line);
			if (count($fields) !== self::FIELDS) {
				$malformed++;
				continue;
			}

			// The first row decides whether this is the year it was fetched or
			// dropped as. After that, a stray row of another year is skipped
			// and counted.
			if (trim($fields[2]) !== (string)$year) {
				if (!$yearConfirmed) {
					throw new RefusedException(sprintf('El archivo trae cifras de %s, no de %d.', self::printable($fields[2]), $year));
				}
				$otherYear++;
				continue;
			}
			$yearConfirmed = true;

			$row = self::row($fields);
			if ($row === null) {
				$malformed++;
				continue;
			}

			$establishments[trim($fields[1])][$row[1]] = true;
			$lastMonth = max($lastMonth, $row[0]);
			$rows++;
			yield $row;
		}

		if ($rows === 0) {
			throw new RefusedException(self::NO_ROWS);
		}

		$profile = array_map('count', $establishments);
		ksort($profile, SORT_NATURAL);

		return [
			'rows' => $rows,
			'lastMonth' => $lastMonth,
			'cut' => !$lines->getReturn(),
			'malformed' => $malformed,
			'otherYear' => $otherYear,
			'profile' => $profile,
		];
	}

	/**
	 * The year a member carries, from its first readable row. This is how a
	 * dropped zip, whose name may say anything, is filed under the right
	 * (año, serie) before any of it is loaded (DroppedFiles).
	 *
	 * @param Generator<int, string, mixed, bool> $lines
	 * @throws RefusedException
	 */
	public static function year(Generator $lines): int {
		self::header($lines);

		for (; $lines->valid(); $lines->next()) {
			$fields = explode(';', $lines->current());
			if (count($fields) === self::FIELDS && preg_match('/^\d{4}$/', trim($fields[2])) === 1) {
				return (int)trim($fields[2]);
			}
		}

		throw new RefusedException(self::NO_ROWS);
	}

	/**
	 * Checks the first line and leaves $lines on the first data line.
	 *
	 * @param Generator<int, string, mixed, bool> $lines
	 */
	private static function header(Generator $lines): void {
		if (!$lines->valid()) {
			throw new RefusedException('El archivo está vacío.');
		}

		$line = $lines->current();
		if (str_starts_with($line, "\u{FEFF}")) {
			$line = substr($line, 3);
		}
		$names = array_map(static fn (string $name): string => strtolower(trim($name)), explode(';', $line));
		if ($names !== self::expectedHeader()) {
			throw new RefusedException(self::NOT_REM);
		}

		$lines->next();
	}

	/** @return list<string> */
	private static function expectedHeader(): array {
		static $header = null;

		return $header ??= [
			'mes', 'idservicio', 'ano', 'idestablecimiento', 'codigoprestacion', 'idregion', 'idcomuna',
			...array_map(static fn (int $i): string => sprintf('col%02d', $i), range(1, self::COLUMNS)),
		];
	}

	/**
	 * @param list<string> $fields
	 * @return ?Row null when the row does not fit
	 */
	private static function row(array $fields): ?array {
		$month = trim($fields[0]);
		if (!ctype_digit($month) || (int)$month < 1 || (int)$month > 12) {
			return null;
		}

		$establishment = self::establishment(trim($fields[3]));
		$codigo = trim($fields[4]);
		// A closed charset also keeps the código safe inside RemLoader's quoted
		// array element.
		if ($establishment === null || preg_match('/^[0-9A-Za-z._-]{1,16}$/', $codigo) !== 1) {
			return null;
		}

		$row = [(int)$month, $establishment, $codigo];
		for ($i = 7; $i < self::FIELDS; $i++) {
			$value = trim($fields[$i]);
			if ($value === '') {
				$row[] = null;
				continue;
			}

			$value = str_replace(',', '.', $value);
			// Also what keeps RemLoader's unquoted numeric array elements
			// well-formed: digits, one point, a sign, an exponent. Nothing else.
			if (!is_numeric($value)) {
				return null;
			}
			$row[] = $value;
		}

		return $row;
	}

	private static function establishment(string $code): ?int {
		if (strlen($code) === 6 && ctype_digit($code)) {
			return (int)$code;
		}
		if (preg_match('/^(\d{2})-(\d{3})$/', $code, $match) === 1) {
			return (int)('1' . $match[1] . $match[2]);
		}

		return null;
	}

	/** A field quoted back in a refusal: printable ASCII, short. */
	private static function printable(string $field): string {
		return substr((string)preg_replace('/[^\x20-\x7E]/', '?', trim($field)), 0, 20);
	}
}
