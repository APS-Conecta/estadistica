<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Xlsx\XlsxReader;

/**
 * The establishment's planilla: DEIS's own REM form (.xlsm or .xlsx) for one
 * serie and one month, read cell by cell through that year's diccionario
 * (docs/adr/0010). Measured on DEIS's 2026 forms and dictionaries, Series A
 * and P (2026-11-05):
 *
 * - **The diccionario is the form with the código inserted as column A.** Every
 *   other cell sits one column to its right, row for row: 15,209 of 15,209
 *   Serie A labels and 2,794 of 2,795 Serie P ones read the same there (the
 *   other is a renamed P4 label, at the same address). So each figure is read
 *   one column left of its «COLnn» marker.
 * - **NOMBRE says who, when and which form**: the establishment's code one
 *   digit per cell in C3:H3, the month by name in B6, the year in B7, the
 *   version in A9 («Versión 1.2: Febrero 2026», as the dictionary's own A9
 *   writes it) and the serie in B17 («SERIE A»).
 * - **A row counts when one of its figures is not 0.** The form's totals are
 *   formulas, so every row it leaves untouched still carries zeros, and DEIS
 *   publishes no row without a figure (2026, both series). Such a row is not
 *   kept, and its cells read as sin informar. A blank cell is null, never 0.
 *
 * Macros never run: only the workbook's XML is read (XlsxReader), so the
 * formulas count as the values Excel last saved, and vbaProject.bin is never
 * opened.
 *
 * @psalm-import-type Row from RemReader
 * @psalm-type Layout = array{name: string, entries: list<array{codigo: string, hoja: string, fila: int, celdas: array<int, int>}>}
 * @psalm-type Planilla = array{serie: string, anio: int, mes: int, estab: int, rows: list<Row>}
 */
final class PlanillaReader {
	private const NOT_A_FORM = 'El archivo no es un formulario REM del DEIS: su hoja NOMBRE no dice la serie (A o P), el año y la versión del formulario.';

	private const MONTHS = [
		'ENERO' => 1, 'FEBRERO' => 2, 'MARZO' => 3, 'ABRIL' => 4, 'MAYO' => 5, 'JUNIO' => 6,
		'JULIO' => 7, 'AGOSTO' => 8, 'SEPTIEMBRE' => 9, 'OCTUBRE' => 10, 'NOVIEMBRE' => 11, 'DICIEMBRE' => 12,
	];

	/**
	 * @param string $establishment the configured six-digit code, '' when there is none
	 * @param callable(int, string): ?Layout $layout the diccionario of (año, serie) this install holds
	 * @return Planilla
	 * @throws RefusedException the planilla is refused whole, with the Spanish reason
	 */
	public static function read(XlsxReader $xlsx, string $establishment, callable $layout): array {
		if (!in_array('NOMBRE', $xlsx->sheets(), true)) {
			throw new RefusedException(self::NOT_A_FORM);
		}
		$nombre = iterator_to_array($xlsx->rows('NOMBRE'));
		$cell = static fn (int $row, int $column): string => trim($nombre[$row][$column] ?? '');

		if (preg_match('/^SERIE ([AP])$/i', $cell(17, 2), $serie) !== 1
			|| preg_match('/^\d{4}$/', $cell(7, 2)) !== 1
			|| preg_match('/^Versi[oó]n (\d+(?:\.\d+)*)/iu', $cell(9, 1), $version) !== 1) {
			throw new RefusedException(self::NOT_A_FORM);
		}
		$serie = strtoupper($serie[1]);
		$anio = (int)$cell(7, 2);

		$code = implode('', array_map(static fn (int $column): string => $cell(3, $column), range(3, 8)));
		if (preg_match('/^\d{6}$/', $code) !== 1) {
			throw new RefusedException('La planilla no trae el código del establecimiento (hoja NOMBRE, un dígito en cada celda de C3 a H3).');
		}
		if ($establishment === '') {
			throw new RefusedException('No hay establecimiento configurado en esta instalación, así que ninguna planilla se puede leer como suya.');
		}
		if ($code !== $establishment) {
			throw new RefusedException(sprintf('La planilla es del establecimiento %s, no del configurado en esta instalación (%s).', $code, $establishment));
		}

		$mes = self::MONTHS[mb_strtoupper($cell(6, 2))] ?? null;
		if ($mes === null) {
			throw new RefusedException('La planilla no dice su mes (hoja NOMBRE, celda B6).');
		}

		$dictionary = $layout($anio, $serie);
		if ($dictionary === null) {
			throw new RefusedException(sprintf('No hay diccionario de %d de la Serie %s con el cual leer la planilla.', $anio, $serie));
		}
		$expected = preg_match('/_V(\d+(?:\.\d+)*)/i', $dictionary['name'], $match) === 1 ? $match[1] : '?';
		if ($version[1] !== $expected) {
			throw new RefusedException(sprintf(
				'La planilla es la versión %s del formulario de %d, y el diccionario de %d de la Serie %s es la versión %s: sus celdas no están donde el diccionario dice.',
				$version[1], $anio, $anio, $serie, $expected,
			));
		}

		return ['serie' => $serie, 'anio' => $anio, 'mes' => $mes, 'estab' => (int)$code, 'rows' => self::rows($xlsx, $dictionary['entries'], $mes, (int)$code)];
	}

	/**
	 * @param list<array{codigo: string, hoja: string, fila: int, celdas: array<int, int>}> $entries
	 * @return list<Row>
	 * @throws RefusedException
	 */
	private static function rows(XlsxReader $xlsx, array $entries, int $mes, int $estab): array {
		$byHoja = [];
		foreach ($entries as $entry) {
			$byHoja[$entry['hoja']][] = $entry;
		}

		$rows = [];
		foreach ($byHoja as $hoja => $hojaEntries) {
			$cells = iterator_to_array($xlsx->rows($hoja));
			foreach ($hojaEntries as $entry) {
				$figures = array_fill(1, RemReader::COLUMNS, null);
				$written = false;
				foreach ($entry['celdas'] as $col => $column) {
					$value = trim($cells[$entry['fila']][$column - 1] ?? '');
					if ($col <= RemReader::COLUMNS && is_numeric($value)) {
						$figures[$col] = $value;
						$written = $written || (float)$value !== 0.0;
					}
				}
				if ($written) {
					$rows[] = [$mes, $estab, $entry['codigo'], ...array_values($figures)];
				}
			}
		}

		return $rows;
	}
}
