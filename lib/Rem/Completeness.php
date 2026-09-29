<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

/**
 * Whether a member that read cleanly is also whole. That is a property of its
 * content: the zip's CRC and the server's ETag both vouch for 2025 Serie A,
 * which is cut mid-row and missing 615 establishments across six servicios
 * (research §The 2025 Serie A gap).
 *
 * The answer is a list of sentences, one per finding, empty when the member is
 * complete. They become the (año, serie) row's «incompleto» detail
 * (RemImporter), so a person reads what is missing rather than a flag.
 *
 * @psalm-import-type Summary from RemReader
 */
final class Completeness {
	/**
	 * A servicio reporting fewer establishments than this share of its count in
	 * the nearest complete year is a gap, not drift. In 2025 Serie A the
	 * collapsed servicios fell to 0.15–0.65 of 2024; the intact ones stayed at
	 * 0.99–1.07.
	 */
	public const RATIO = 0.75;

	/** Servicios smaller than this in the baseline year are too small for a ratio to mean anything. */
	public const MIN_ESTABLISHMENTS = 5;

	/**
	 * @param Summary $summary RemReader::rows()'s return
	 * @param ?array{anio: int, profile: array<string, int>} $neighbour the nearest complete load of the same serie
	 * @return list<string>
	 */
	public static function of(array $summary, ?array $neighbour): array {
		$findings = [];

		if ($summary['cut']) {
			$findings[] = 'La última fila venía cortada (el archivo no termina en salto de línea) y se omitió.';
		}
		if ($summary['malformed'] > 0) {
			$findings[] = sprintf('Se omitieron %s filas ilegibles: con campos de más o de menos, o con valores que no son números.', self::count($summary['malformed']));
		}
		if ($summary['otherYear'] > 0) {
			$findings[] = sprintf('Se omitieron %s filas de otro año.', self::count($summary['otherYear']));
		}
		if ($neighbour !== null) {
			array_push($findings, ...self::gaps($summary['profile'], $neighbour));
		}

		return $findings;
	}

	/**
	 * @param array<string, int> $profile
	 * @param array{anio: int, profile: array<string, int>} $neighbour
	 * @return list<string>
	 */
	private static function gaps(array $profile, array $neighbour): array {
		$compared = array_filter($neighbour['profile'], static fn (int $count): bool => $count >= self::MIN_ESTABLISHMENTS);

		// Most servicios missing outright is not a gap. It means the codes
		// changed between eras (research: IdServicio drifts by era), and there
		// is nothing to compare.
		if (count(array_intersect_key($compared, $profile)) * 2 < count($compared)) {
			return [];
		}

		$gaps = [];
		foreach ($compared as $servicio => $before) {
			$now = $profile[$servicio] ?? 0;
			if ($now < $before * self::RATIO) {
				$gaps[] = sprintf(
					'Servicio de salud %s: informan %s establecimientos, frente a %s en %d.',
					(string)$servicio === '' ? '(sin código)' : $servicio,
					self::count($now),
					self::count($before),
					$neighbour['anio'],
				);
			}
		}

		return $gaps;
	}

	private static function count(int $n): string {
		return number_format($n, 0, ',', '.');
	}
}
