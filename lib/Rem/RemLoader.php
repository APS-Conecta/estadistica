<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;
use OCP\DB\Exception;
use OCP\IDBConnection;

/**
 * Replaces one (año, serie) in estadistica_rem with the rows it is given, in
 * the caller's transaction. RemImporter owns that transaction: the rows and
 * their state commit together, or neither does (acceptance a4).
 *
 * The rows go in as PostgreSQL arrays, one per column, through
 * INSERT … SELECT FROM unnest(…). Measured in Nextcloud's own driver mode
 * (ATTR_DISABLE_PREPARES): 32k rows/s, 6.1× a 1,000-row multi-row VALUES, with
 * a 22 MiB peak at 5,000 rows per batch (design, 2026-09-28).
 *
 * Every value is a bound parameter; the SQL carries only fixed table and
 * column names. The casts are written CAST(? AS …), not ?::…, so no SQL parser
 * between here and PostgreSQL can read the cast's colon as a named parameter.
 *
 * ponytail: ≈ 32k rows/s is the ceiling inside OCP (2024 Serie A, 6.56M rows,
 * ≈ 3.4 min). The upgrade is COPY over a second connection, which the suite's
 * OCP-only rule (gestion apps/README.md, AD-9) and the owner rejected.
 *
 * @psalm-import-type Row from RemReader
 */
class RemLoader {
	/** Rows per INSERT. 20,000 measured no faster, at 2.5× the memory. */
	public const BATCH = 5000;

	private readonly string $insert;

	public function __construct(
		private IDBConnection $db,
	) {
		$this->insert = sprintf(
			'INSERT INTO *PREFIX*estadistica_rem (serie, anio, mes, estab, codigo, %s) '
			. 'SELECT ?, CAST(? AS smallint), t.* FROM unnest(CAST(? AS smallint[]), CAST(? AS integer[]), CAST(? AS text[]), %s) AS t',
			implode(', ', array_map(static fn (int $i): string => sprintf('col%02d', $i), range(1, RemReader::COLUMNS))),
			implode(', ', array_fill(0, RemReader::COLUMNS, 'CAST(? AS numeric(20,6)[])')),
		);
	}

	/**
	 * @param iterable<Row> $rows
	 * @return int the rows inserted
	 * @throws RefusedException the member repeats a row
	 */
	public function replace(string $serie, int $year, iterable $rows): int {
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_rem WHERE anio = ? AND serie = ?', [$year, $serie]);

		$inserted = 0;
		$batch = [];
		foreach ($rows as $row) {
			$batch[] = $row;
			if (count($batch) === self::BATCH) {
				$inserted += $this->insert($serie, $year, $batch);
				$batch = [];
			}
		}
		if ($batch !== []) {
			$inserted += $this->insert($serie, $year, $batch);
		}

		return $inserted;
	}

	/**
	 * One array literal per column. Their elements are safe unquoted, or quoted
	 * without escaping, because RemReader lets through only integers, is_numeric()
	 * strings and códigos from a closed charset.
	 *
	 * @param non-empty-list<Row> $batch
	 */
	private function insert(string $serie, int $year, array $batch): int {
		$params = [$serie, $year];
		foreach (array_keys($batch[0]) as $i) {
			$column = array_column($batch, $i);
			$params[] = match (true) {
				$i < 2 => '{' . implode(',', $column) . '}',
				$i === 2 => '{"' . implode('","', $column) . '"}',
				default => '{' . implode(',', array_map(static fn (?string $value): string => $value ?? 'NULL', $column)) . '}',
			};
		}

		try {
			return $this->db->executeStatement($this->insert, $params);
		} catch (Exception $e) {
			if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new RefusedException('El archivo repite una fila (mismo establecimiento, mes y código): no se cargó.');
			}
			throw $e;
		}
	}
}
