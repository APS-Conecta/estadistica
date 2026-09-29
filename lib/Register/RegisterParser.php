<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Register;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Xlsx\XlsxReader;

/**
 * DEIS's Base de Establecimientos: every establishment the country has had,
 * open or closed, with the attributes a peer group is made of. REM rows carry
 * only the establishment's code, so tipo, dependencia, nivel, comuna, servicio
 * and región come from here, once (the Storage decision).
 *
 * The header is locked by name. These are the register's own field names,
 * the ones DEIS wrote in 2025 and datos.gob.cl's copy has always used. A
 * column is found by its name wherever it sits, other columns are ignored, and
 * a missing one refuses the whole file, which is then not a Base de
 * Establecimientos this app knows. The yearly files have changed layout before
 * (2019 and 2024 carry a title row and a second header in words), so a
 * refusal names what is missing instead of loading columns by guess.
 *
 * Measured on the 2025 file (design, 2026-09-28): 5,563 establishments, 383
 * of them closed, every code six digits and unique. Every establishment in
 * REM 2017 Serie A and 2013 Serie P is in it, and 23 of 3,005 in 2026 Serie A
 * are not. Those opened after the file was cut, and they wait for the next
 * year's file.
 *
 * @psalm-type Row = array{estab: int, nombre: ?string, tipo: ?string, dependencia: ?string, nivel: ?string, comuna_cut: ?string, comuna: ?string, servicio_codigo: ?string, servicio: ?string, region_codigo: ?string, region: ?string, estado: ?string}
 */
final class RegisterParser {
	/** Row key → the register's column name. */
	public const COLUMNS = [
		'estab' => 'EstablecimientoCodigo',
		'nombre' => 'EstablecimientoGlosa',
		'tipo' => 'TipoEstablecimientoGlosa',
		'dependencia' => 'DependenciaAdministrativa',
		'nivel' => 'NivelAtencionEstabglosa',
		'comuna_cut' => 'ComunaCodigo',
		'comuna' => 'ComunaGlosa',
		'servicio_codigo' => 'SeremiSaludCodigo_ServicioDeSaludCodigo',
		'servicio' => 'SeremiSaludGlosa_ServicioDeSaludGlosa',
		'region_codigo' => 'RegionCodigo',
		'region' => 'RegionGlosa',
		'estado' => 'EstadoFuncionamiento',
	];

	/** How far down a sheet its header may sit. */
	private const HEADER_ROWS = 5;

	/**
	 * @return array{rows: list<Row>, skipped: int}
	 * @throws RefusedException
	 */
	public static function parse(XlsxReader $xlsx): array {
		$headerOnly = false;
		foreach ($xlsx->sheets() as $sheet) {
			$positions = null;
			$rows = [];
			$seen = [];
			$skipped = 0;

			foreach ($xlsx->rows($sheet) as $number => $values) {
				if ($positions === null) {
					$positions = self::header($values);
					if ($positions === null && $number >= self::HEADER_ROWS) {
						break;
					}
					continue;
				}

				$row = self::row($values, $positions);
				if ($row === null || isset($seen[$row['estab']])) {
					$skipped++;
					continue;
				}
				$seen[$row['estab']] = true;
				$rows[] = $row;
			}

			if ($positions === null) {
				continue;
			}
			if ($rows === []) {
				// The header with nothing under it (a summary tab): the register
				// may be the next sheet.
				$headerOnly = true;
				continue;
			}

			return ['rows' => $rows, 'skipped' => $skipped];
		}

		if ($headerOnly) {
			throw new RefusedException('La Base de Establecimientos no trae establecimientos.');
		}
		throw new RefusedException(sprintf(
			'La planilla no es la Base de Establecimientos del DEIS: ninguna hoja trae la columna %s.',
			self::COLUMNS['estab'],
		));
	}

	/**
	 * The column of each field, when $values is the header, or null when it is
	 * not. A header that has the code but lacks another field is a register
	 * of a shape this app does not know, and is refused by name.
	 *
	 * @param array<int, string> $values
	 * @return ?array<string, int>
	 * @throws RefusedException
	 */
	private static function header(array $values): ?array {
		$columns = array_flip(array_map(static fn (string $name): string => trim($name), $values));
		if (!isset($columns[self::COLUMNS['estab']])) {
			return null;
		}

		$missing = array_diff(self::COLUMNS, array_keys($columns));
		if ($missing !== []) {
			throw new RefusedException(sprintf(
				'La Base de Establecimientos no trae %s: no tiene la forma que esta aplicación conoce.',
				implode(', ', $missing),
			));
		}

		return array_map(static fn (string $name): int => $columns[$name], self::COLUMNS);
	}

	/**
	 * A row, or null when its code is not an establishment's six digits. A
	 * comuna's five digits and a región's two are padded back where Excel typed
	 * them as numbers (the 2025 file mixes both), and a code that is not digits
	 * at all is left out rather than stored as one.
	 *
	 * @param array<int, string> $values
	 * @param array<string, int> $positions
	 * @return ?Row
	 */
	private static function row(array $values, array $positions): ?array {
		$code = trim($values[$positions['estab']] ?? '');
		if (strlen($code) !== 6 || !ctype_digit($code)) {
			return null;
		}

		$row = ['estab' => (int)$code];
		foreach ($positions as $key => $column) {
			if ($key !== 'estab') {
				$value = trim($values[$column] ?? '');
				$row[$key] = $value === '' ? null : $value;
			}
		}
		$row['comuna_cut'] = self::pad($row['comuna_cut'], 5);
		$row['region_codigo'] = self::pad($row['region_codigo'], 2);
		$row['servicio_codigo'] = self::pad($row['servicio_codigo'], 2);

		return $row;
	}

	private static function pad(?string $code, int $length): ?string {
		return $code !== null && ctype_digit($code) && strlen($code) <= $length ? str_pad($code, $length, '0', STR_PAD_LEFT) : null;
	}
}
