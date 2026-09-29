<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Piv;

use APS\Common\Csv;
use APS\Common\Text;
use OCA\Estadistica\Exception\RefusedException;

/**
 * FONASA's «Inscritos APS» export: the Población Inscrita Validada by centro,
 * FONASA tramo, age band and sex (acceptance a11). FONASA shows it only
 * behind a Tableau report that no program can read (research). So an admin
 * exports it and hands it over through the drop door (DroppedFiles), which
 * gives this reader every file that is not a zip.
 *
 * The header is locked. The file must carry exactly FONASA's eleven columns,
 * in any order, and any other set is refused whole with nothing stored. Above
 * all this refuses a file that adds a RUT or a name: that would be patients'
 * data, which this app never holds (D5).
 * - Headers are compared by their slug (Text::slugFor), so a BOM, the accent in
 *   REGIÓN and Excel's capitals all pass.
 * - The delimiter is read off the header (Csv::delimiterOf), because Excel in
 *   es-CL writes `;`.
 * - A cell that is not UTF-8 is read as Windows-1252 (Csv::utf8).
 *
 * The rows are strict too. Any of these refuses the whole file, naming the
 * line: a corte that is not a date, a band or a sex FONASA does not write, a
 * center code that is not digits, or a count that is not a whole number.
 * FONASA's own export has none (research: 146,345 rows, 0 malformed), so a
 * wrong row means a file someone edited.
 *
 * The file is read row by row, never whole, and summed on the way:
 * - FONASA's income tramo (A/B/C/D/X) is summed away, because no meta reads it
 *   (owner, Slice 8). The 2025 export goes from 146,345 rows to 35,862.
 * - People with no age band (3,642 in 2025) fit no meta's age range. They are
 *   counted and reported, not stored.
 *
 * Depends on no OCP class: bare PHP, so it tests without Nextcloud.
 *
 * @psalm-type Row = array{corte: string, estab: int, edad: int, sexo: string, inscritos: int}
 */
final class PivReader {
	/** Column key → FONASA's header, as the export writes it (a11). */
	public const COLUMNS = [
		'periodo' => 'PERIODO',
		'servicio' => 'SERVICIO_SALUD',
		'region' => 'REGIÓN',
		'comuna' => 'COMUNA',
		'centro' => 'COD_CENTRO',
		'nombre' => 'NOMBRE_CENTRO',
		'dependencia' => 'DEPENDENCIA_ADMINISTRATIVA',
		'tramo' => 'TRAMO_FONASA',
		'edad' => 'EDAD_TRAMO',
		'sexo' => 'SEXO',
		'inscritos' => 'TOTAL_INSCRITOS',
	];

	/** EDAD_TRAMO → the band's first year. «80 a más años» counts as 80–89 (docs/adr/0007). */
	public const BANDS = [
		'0 a 9 años' => 0,
		'10 a 19 años' => 10,
		'20 a 29 años' => 20,
		'30 a 39 años' => 30,
		'40 a 49 años' => 40,
		'50 a 59 años' => 50,
		'60 a 69 años' => 60,
		'70 a 79 años' => 70,
		'80 a más años' => 80,
	];

	/** SEXO → the stored letter. */
	public const SEXES = ['Mujeres' => 'M', 'Hombres' => 'H', 'Sin información' => 'S'];

	/** EDAD_TRAMO and SEXO when FONASA does not know. */
	private const UNKNOWN = 'Sin información';

	/** A header line is ≈ 130 bytes. A first line longer than this is not a CSV header. */
	private const HEADER_BYTES = 64 * 1024;

	/**
	 * @param resource $stream the file, from its first byte
	 * @return array{rows: list<Row>, lines: int, unknownAge: int}
	 * @throws RefusedException
	 */
	public static function read($stream): array {
		$first = fgets($stream, self::HEADER_BYTES);
		$header = Csv::utf8(rtrim($first === false ? '' : $first, "\r\n"));
		$delimiter = Csv::delimiterOf($header);
		$positions = self::header(str_getcsv($header, $delimiter, '"', ''));

		$bands = self::folded(self::BANDS);
		$sexes = self::folded(self::SEXES);
		$unknown = Text::fold(self::UNKNOWN);
		/** @var array<string, ?string> $cortes PERIODO as written → YYYY-MM-DD */
		$cortes = [];
		/** @var array<string, string> $folds a cell as written → folded (ten bands and three sexes, folded once) */
		$folds = [];

		$sums = [];
		$lines = 0;
		$unknownAge = 0;
		$number = 1;
		while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
			$number++;
			if ($cells === [null]) {
				continue;
			}
			if (count($cells) !== count(self::COLUMNS)) {
				throw self::badLine($number, sprintf('trae %d columnas, y el encabezado %d', count($cells), count(self::COLUMNS)));
			}
			$cell = static fn (string $key): string => trim(Csv::utf8((string)$cells[$positions[$key]]));

			$periodo = $cell('periodo');
			$corte = $cortes[$periodo] ??= self::corte($periodo);
			if ($corte === null) {
				throw self::badLine($number, sprintf('el PERIODO «%s» no es una fecha como 31-12-2025', self::quote($periodo)));
			}
			$centro = $cell('centro');
			if (!ctype_digit($centro) || strlen($centro) > 9) {
				throw self::badLine($number, sprintf('el COD_CENTRO «%s» no es un código de establecimiento', self::quote($centro)));
			}
			$sexoText = $cell('sexo');
			$sexo = $sexes[$folds[$sexoText] ??= Text::fold($sexoText)] ?? null;
			if ($sexo === null) {
				throw self::badLine($number, sprintf('el SEXO «%s» no es Mujeres, Hombres ni Sin información', self::quote($sexoText)));
			}
			$total = $cell('inscritos');
			if (!ctype_digit($total) || strlen($total) > 9) {
				throw self::badLine($number, sprintf('el TOTAL_INSCRITOS «%s» no es un número entero', self::quote($total)));
			}
			$edadText = $cell('edad');
			$edadFolded = $folds[$edadText] ??= Text::fold($edadText);
			$lines++;
			if ($edadFolded === $unknown) {
				$unknownAge += (int)$total;
				continue;
			}
			$edad = $bands[$edadFolded] ?? null;
			if ($edad === null) {
				throw self::badLine($number, sprintf('el EDAD_TRAMO «%s» no es un tramo de FONASA (0 a 9 años … 80 a más años)', self::quote($edadText)));
			}

			$key = $corte . '|' . (int)$centro . '|' . $edad . '|' . $sexo;
			$sums[$key] = ($sums[$key] ?? 0) + (int)$total;
		}

		if ($sums === []) {
			throw new RefusedException('El CSV «Inscritos APS» no trae inscritos con tramo de edad: no hay nada que cargar.');
		}

		$rows = [];
		foreach ($sums as $key => $inscritos) {
			[$corte, $estab, $edad, $sexo] = explode('|', $key);
			$rows[] = ['corte' => $corte, 'estab' => (int)$estab, 'edad' => (int)$edad, 'sexo' => $sexo, 'inscritos' => $inscritos];
		}

		return ['rows' => $rows, 'lines' => $lines, 'unknownAge' => $unknownAge];
	}

	/**
	 * Where each column sits, found by its slug. The set must be exactly
	 * FONASA's: a column it lacks, or one it does not have, refuses the file
	 * by name.
	 *
	 * @param list<?string> $names
	 * @return array<string, int>
	 * @throws RefusedException
	 */
	private static function header(array $names): array {
		$expected = [];
		foreach (self::COLUMNS as $key => $name) {
			$expected[Text::slugFor($name)] = $key;
		}

		$positions = [];
		$extra = [];
		foreach ($names as $position => $name) {
			$key = $expected[Text::slugFor((string)$name)] ?? null;
			if ($key !== null && !isset($positions[$key])) {
				$positions[$key] = $position;
			} else {
				$extra[] = trim((string)$name) === '' ? '(una columna sin nombre)' : self::quote(trim((string)$name));
			}
		}

		$missing = array_values(array_diff_key(self::COLUMNS, $positions));
		if ($extra !== [] || $missing !== []) {
			// It opens with «El archivo no es un ZIP»: the door hands this reader
			// every file that is not a zip, so this is also the door's answer to
			// a file of any other kind (DroppedFiles, and Slice 2's checks).
			throw new RefusedException(sprintf(
				'El archivo no es un ZIP del DEIS ni el CSV «Inscritos APS» de FONASA: su primera línea debe traer exactamente las columnas %s.%s%s Nada se guardó.',
				implode(', ', self::COLUMNS),
				$extra === [] ? '' : ' Sobra: ' . implode(', ', array_slice($extra, 0, 5)) . (count($extra) > 5 ? '…' : '') . '.',
				$missing === [] ? '' : ' Falta: ' . implode(', ', $missing) . '.',
			));
		}

		return $positions;
	}

	/**
	 * PERIODO as FONASA writes it (31-12-2025), or as a spreadsheet may
	 * rewrite it (31/12/2025, 2025-12-31), as YYYY-MM-DD; null when it is not
	 * a date.
	 */
	private static function corte(string $value): ?string {
		if (preg_match('#^(\d{2})[-/](\d{2})[-/](\d{4})$#', $value, $m) === 1) {
			[$day, $month, $year] = [$m[1], $m[2], $m[3]];
		} elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
			[$year, $month, $day] = [$m[1], $m[2], $m[3]];
		} else {
			return null;
		}

		return checkdate((int)$month, (int)$day, (int)$year) ? "$year-$month-$day" : null;
	}

	/**
	 * @template T
	 * @param array<string, T> $values text as FONASA writes it → value
	 * @return array<string, T> the same, keyed by the folded text
	 */
	private static function folded(array $values): array {
		$folded = [];
		foreach ($values as $text => $value) {
			$folded[Text::fold($text)] = $value;
		}

		return $folded;
	}

	/** A cell as a refusal quotes it: short, whatever the file held. */
	private static function quote(string $value): string {
		return mb_substr($value, 0, 40);
	}

	private static function badLine(int $number, string $what): RefusedException {
		return new RefusedException(sprintf('Línea %d del CSV «Inscritos APS»: %s. Nada se guardó.', $number, $what));
	}
}
