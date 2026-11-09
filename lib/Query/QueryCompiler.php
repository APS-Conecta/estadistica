<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Query;

use LogicException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\RemReader;

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
 * - **The local series is DEIS overlaid by planillas** (docs/adr/0010): it
 *   reads local(), where the newest planilla of a serie and month stands in
 *   for every DEIS row of its establishment. The national and peer series,
 *   and so their informantes, read DEIS's rows alone.
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

	/**
	 * The newest leído planilla of each establishment, serie and month: by
	 * modification time, then file id (Planillas::status).
	 */
	public const PLANILLAS = 'SELECT DISTINCT ON (estab, serie, anio, mes) file_id, estab, serie, anio, mes, mtime'
		. ' FROM *PREFIX*estadistica_planilla WHERE error IS NULL ORDER BY estab, serie, anio, mes, mtime DESC, file_id DESC';

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
			. ' FROM ' . (isset($who['estab']) ? self::local() : '*PREFIX*estadistica_rem') . ' r'
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
	 * Each establishment's rows as its local series reads them, in
	 * estadistica_rem's columns: a serie and month its planilla covers from
	 * the newest one (a blank cell is NULL, sin informar, whatever DEIS
	 * wrote), every other from DEIS. It is filtered from outside like the
	 * table, and PostgreSQL pushes the filter into both halves.
	 */
	public static function local(): string {
		$columns = 'serie, anio, mes, estab, codigo, ' . implode(', ', array_map(static fn (int $i): string => sprintf('col%02d', $i), range(1, RemReader::COLUMNS)));

		return "(SELECT $columns FROM *PREFIX*estadistica_rem d WHERE NOT EXISTS (SELECT 1 FROM *PREFIX*estadistica_planilla p"
			. ' WHERE p.error IS NULL AND p.estab = d.estab AND p.serie = d.serie AND p.anio = d.anio AND p.mes = d.mes)'
			. " UNION ALL SELECT $columns FROM *PREFIX*estadistica_planilla_rem WHERE file_id IN (SELECT file_id FROM (" . self::PLANILLAS . ') n))';
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
