<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Query;

use LogicException;
use OCA\Estadistica\Exception\RefusedException;

/**
 * A QuerySpec, as the SQL PostgreSQL runs (docs/adr/0006).
 *
 * - **Parameter-only.** Every value is bound. The SQL is built only from this
 *   class's own fixed text and from names QuerySpec checked against closed
 *   sets: columns col01…col50, DIMENSIONS, TRAITS, METRICS. Nothing the caller
 *   typed is written into it, so two consultas that differ only in their
 *   values compile to the same SQL (QueryCompilerTest).
 * - **One statement per series.** The local, national and peer statements
 *   differ only in who is counted: the local establishment (r.estab = ?),
 *   everyone, or the establishments whose register row has the chosen traits.
 * - **Only reported cells count.** The chosen columns are unpivoted (LATERAL
 *   VALUES) and a cell counts only where it was reported. An empty cell is
 *   NULL, never 0 (docs/adr/0004), and an establishment «informante» is one
 *   with at least one reported cell among the chosen columns.
 *
 * It is pure (no Nextcloud class).
 *
 * @psalm-type Compiled = array{sql: string, params: list<int|string>}
 * @psalm-type Punto = array{grupo: list<int|string|null>, valor: float, informantes: int}
 */
final class QueryCompiler {
	/** A series has at most this many groups. One more is fetched, to know it overflowed. */
	public const MAX_GROUPS = 5000;

	private const METRIC = [
		'suma' => 'sum(c.valor)',
		'promedio_establecimiento' => 'sum(c.valor) / count(DISTINCT r.estab)',
	];

	/**
	 * @param list<string> $codigos what the filtros resolved to (QueryExecutor)
	 * @param array<string, int|string> $who [] for the country; ['estab' => code] for the local
	 *                                       establishment; trait → value for a peer group
	 * @return Compiled
	 */
	public static function compile(QuerySpec $spec, array $codigos, array $who): array {
		$groups = [];
		foreach ($spec->agrupar as $i => $dimension) {
			$groups["g$i"] = self::expression($dimension);
		}
		$select = [];
		foreach ($groups as $alias => $expression) {
			$select[] = "$expression AS $alias";
		}
		$select[] = self::METRIC[$spec->metrica] . ' AS valor';
		$select[] = 'count(DISTINCT r.estab) AS informantes';

		$cells = implode(', ', array_map(static fn (int $col): string => sprintf('(%d, r.col%02d)', $col, $col), $spec->columnas));
		$sql = 'SELECT ' . implode(', ', $select)
			. ' FROM *PREFIX*estadistica_rem r'
			. " CROSS JOIN LATERAL (VALUES $cells) AS c(columna, valor)";
		if (array_intersect($spec->agrupar, QuerySpec::TRAITS) !== []) {
			$sql .= ' LEFT JOIN *PREFIX*estadistica_establishment e ON e.estab = r.estab';
		}
		$sql .= ' WHERE r.serie = ? AND r.anio BETWEEN ? AND ? AND r.anio * 100 + r.mes BETWEEN ? AND ?'
			. ' AND r.codigo IN (SELECT jsonb_array_elements_text(CAST(? AS jsonb)))'
			. ' AND c.valor IS NOT NULL';
		$params = [
			$spec->serie, $spec->firstYear(), $spec->lastYear(), $spec->desde, $spec->hasta,
			json_encode(array_values($codigos), JSON_THROW_ON_ERROR),
		];

		if (isset($who['estab'])) {
			$sql .= ' AND r.estab = ?';
			$params[] = $who['estab'];
		} elseif ($who !== []) {
			$traits = [];
			foreach ($who as $trait => $value) {
				// The one set of names that does not come through QuerySpec.
				if (!in_array($trait, QuerySpec::TRAITS, true)) {
					throw new LogicException("Not a peer trait: $trait");
				}
				$traits[] = "p.$trait = ?";
				$params[] = $value;
			}
			$sql .= ' AND r.estab IN (SELECT p.estab FROM *PREFIX*estadistica_establishment p WHERE ' . implode(' AND ', $traits) . ')';
		}

		if ($groups !== []) {
			$aliases = implode(', ', array_keys($groups));
			$sql .= " GROUP BY $aliases ORDER BY $aliases LIMIT " . (self::MAX_GROUPS + 1);
		}

		return ['sql' => $sql, 'params' => $params];
	}

	/**
	 * One series' rows as points. grupo lists the group's values in agrupar's
	 * order: mes as YYYY-MM, columna as colNN, a trait as the register writes
	 * it (null for an establishment the register lacks).
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<Punto>
	 * @throws RefusedException more groups than MAX_GROUPS
	 */
	public static function points(QuerySpec $spec, array $rows): array {
		if (count($rows) > self::MAX_GROUPS) {
			throw new RefusedException(sprintf(
				'La consulta da más de %s grupos: acote el periodo, las prestaciones o lo que se agrupa.',
				number_format(self::MAX_GROUPS, 0, ',', '.'),
			));
		}

		$points = [];
		foreach ($rows as $row) {
			// Without agrupar, nothing reported is still one row: sum NULL, count 0.
			if ((int)$row['informantes'] === 0) {
				continue;
			}
			$grupo = [];
			foreach ($spec->agrupar as $i => $dimension) {
				$grupo[] = self::value($dimension, $row["g$i"]);
			}
			$points[] = ['grupo' => $grupo, 'valor' => (float)$row['valor'], 'informantes' => (int)$row['informantes']];
		}

		return $points;
	}

	private static function expression(string $dimension): string {
		return match ($dimension) {
			'anio' => 'r.anio',
			'mes' => 'r.anio * 100 + r.mes',
			'codigo' => 'r.codigo',
			'columna' => 'c.columna',
			// QuerySpec let only DIMENSIONS through; what is left is a trait.
			default => "e.$dimension",
		};
	}

	private static function value(string $dimension, mixed $value): int|string|null {
		return match ($dimension) {
			'anio' => (int)$value,
			'mes' => sprintf('%04d-%02d', intdiv((int)$value, 100), (int)$value % 100),
			'columna' => sprintf('col%02d', (int)$value),
			default => $value === null ? null : (string)$value,
		};
	}
}
