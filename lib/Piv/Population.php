<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Piv;

use LogicException;
use OCA\Estadistica\Query\QuerySpec;
use OCP\IDBConnection;

/**
 * The inscritos a meta counts against, for a meta year: this install's
 * establishment's, the country's, or a peer group's (the PIV denominators
 * decision, docs/adr/0007). Slice 9's metas ask here.
 *
 * Two rules are pure, so they test without a database: which corte a meta year
 * reads (pick()), and which age ranges FONASA's decade bands give, whole
 * (count()). Everything else reads estadistica_piv:
 * - The establishment's own figures take its typed cells over FONASA's, cell
 *   by cell. Its cortes are every corte it has a figure for.
 * - The country and a peer group are FONASA's published counts only, summed
 *   over the cortes FONASA's files brought. A peer group is the register's
 *   filter, exactly as for a consulta's pares (QueryCompiler), and includes
 *   the local establishment.
 *
 * @psalm-type Inscritos = array{corte: string, factor: float, manual: bool, bands: array<int, array<string, int>>}
 */
class Population {
	/** OOTT 2026: a unit without the year's PIV grows the last one it has by 3 %. */
	public const GROWTH = 1.03;

	/** Every FONASA band is ten years wide. «80 a más» counts as 80–89. */
	public const WIDTH = 10;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * This install's establishment: FONASA's figures, with each cell an admin
	 * typed in its place.
	 *
	 * @return ?Inscritos null when no corte serves $year
	 */
	public function local(int $year, int $estab): ?array {
		$picked = self::pick($year, $this->cortes('estab = ?', [$estab]));
		if ($picked === null) {
			return null;
		}

		// FONASA's rows first, so a typed cell lands on top of its FONASA twin.
		$result = $this->db->executeQuery(
			'SELECT edad, sexo, manual, inscritos FROM *PREFIX*estadistica_piv WHERE corte = ? AND estab = ? ORDER BY manual, edad, sexo',
			[$picked['corte'], $estab],
		);
		$bands = [];
		$manual = false;
		while (($row = $result->fetch()) !== false) {
			$bands[(int)$row['edad']][(string)$row['sexo']] = (int)$row['inscritos'];
			$manual = $manual || (int)$row['manual'] === 1;
		}
		$result->closeCursor();

		return $picked + ['manual' => $manual, 'bands' => $bands];
	}

	/** @return ?Inscritos every establishment in FONASA's file */
	public function national(int $year): ?array {
		return $this->sum($year, '', []);
	}

	/**
	 * @param array<string, string> $traits trait → value (QuerySpec::TRAITS), at least one
	 * @return ?Inscritos the establishments whose register row has every trait
	 */
	public function peers(int $year, array $traits): ?array {
		if ($traits === []) {
			throw new LogicException('A peer group is chosen by at least one trait.');
		}

		$where = [];
		foreach (array_keys($traits) as $trait) {
			// Only the fixed trait names reach the SQL. Their values are bound.
			if (!in_array($trait, QuerySpec::TRAITS, true)) {
				throw new LogicException("Not a peer trait: $trait");
			}
			$where[] = "p.$trait = ?";
		}

		return $this->sum(
			$year,
			' AND estab IN (SELECT p.estab FROM *PREFIX*estadistica_establishment p WHERE ' . implode(' AND ', $where) . ')',
			array_values($traits),
		);
	}

	/**
	 * The corte a meta year reads: 31 December of the year before, as stored.
	 * Failing that, the latest corte before it, grown by GROWTH. Failing that,
	 * none. A later corte never stands in for an earlier year.
	 *
	 * @param list<string> $cortes YYYY-MM-DD
	 * @return ?array{corte: string, factor: float}
	 */
	public static function pick(int $year, array $cortes): ?array {
		$wanted = sprintf('%d-12-31', $year - 1);
		if (in_array($wanted, $cortes, true)) {
			return ['corte' => $wanted, 'factor' => 1.0];
		}

		$earlier = array_filter($cortes, static fn (string $corte): bool => $corte < $wanted);

		return $earlier === [] ? null : ['corte' => max($earlier), 'factor' => self::GROWTH];
	}

	/**
	 * The inscritos aged $from to $to (both included; null means no upper
	 * age), times the corte's factor. $sex picks one sex. When it is null all
	 * three count, «Sin información» included, because those people have an age.
	 *
	 * @param Inscritos $inscritos
	 * @return ?float null when the range cuts a band (whole())
	 */
	public static function count(array $inscritos, int $from, ?int $to, ?string $sex = null): ?float {
		if (!self::whole($from, $to)) {
			return null;
		}

		$total = 0;
		foreach ($inscritos['bands'] as $first => $sexes) {
			if (self::shared($first, $from, $to) > 0) {
				$total += $sex === null ? array_sum($sexes) : ($sexes[$sex] ?? 0);
			}
		}

		return $total * $inscritos['factor'];
	}

	/**
	 * Whether $from to $to is a sum of whole bands, so FONASA's own figures
	 * give it. No band is split (docs/adr/0007, amendment): every figure is
	 * official.
	 */
	public static function whole(int $from, ?int $to): bool {
		if ($from < 0 || ($to !== null && $to < $from)) {
			throw new LogicException(sprintf('Not an age range: %d to %s.', $from, $to ?? 'any'));
		}

		foreach (PivReader::BANDS as $first) {
			$shared = self::shared($first, $from, $to);
			if ($shared > 0 && $shared < self::WIDTH) {
				return false;
			}
		}

		return true;
	}

	/** The years the band starting at $first shares with $from to $to. */
	private static function shared(int $first, int $from, ?int $to): int {
		$last = $first + self::WIDTH - 1;

		return min($last, $to ?? $last) - max($first, $from) + 1;
	}

	/**
	 * FONASA's counts summed over the establishments $filter keeps.
	 *
	 * @param list<string> $params the filter's values
	 * @return ?Inscritos
	 */
	private function sum(int $year, string $filter, array $params): ?array {
		$picked = self::pick($year, $this->cortes('manual = 0'));
		if ($picked === null) {
			return null;
		}

		$result = $this->db->executeQuery(
			'SELECT edad, sexo, sum(inscritos) AS n FROM *PREFIX*estadistica_piv WHERE corte = ? AND manual = 0' . $filter . ' GROUP BY edad, sexo ORDER BY edad, sexo',
			[$picked['corte'], ...$params],
		);
		$bands = [];
		while (($row = $result->fetch()) !== false) {
			$bands[(int)$row['edad']][(string)$row['sexo']] = (int)$row['n'];
		}
		$result->closeCursor();

		return $picked + ['manual' => false, 'bands' => $bands];
	}

	/**
	 * @param list<int> $params
	 * @return list<string> the cortes the rows $where keeps have, YYYY-MM-DD
	 */
	private function cortes(string $where, array $params = []): array {
		$result = $this->db->executeQuery('SELECT DISTINCT corte FROM *PREFIX*estadistica_piv WHERE ' . $where, $params);
		$cortes = array_map('strval', $result->fetchFirstColumn());
		$result->closeCursor();

		return $cortes;
	}
}
