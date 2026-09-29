<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Register;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Query\QuerySpec;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Throwable;

/**
 * The establishments, stored once: estadistica_establishment, which every
 * REM row joins by its code for tipo, dependencia, nivel, comuna, servicio and
 * región (acceptance a5). A peer group is a filter over it.
 *
 * It is replaced whole, in one transaction, by the newest Base de
 * Establecimientos DEIS publishes (DeisRepository's daily check) or by one an
 * admin drops (DroppedFiles). Readers see the old register until the new one
 * commits.
 *
 * ponytail: an establishment REM reports and the register does not have yet
 * (23 of 3,005 in 2026 Serie A, opened after the 2025 file was cut) resolves
 * nothing, so it is left out of every peer group and is not counted anywhere.
 * The upgrade is to count them per (año, serie) at load time, as the códigos
 * without a label are (Catalog).
 *
 * @psalm-import-type Row from RegisterParser
 */
class Register {
	/** What the stored register is: where it came from, its year, when, and how many. */
	public const KEY = 'register';

	/** The attributes a peer group is chosen by, each with the plural the fuente names it by. */
	private const DIMENSIONS = [
		'tipos' => 'tipo',
		'dependencias' => 'dependencia',
		'niveles' => 'nivel',
		'servicios' => 'servicio',
		'regiones' => 'region',
	];

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	/**
	 * The rows go in as one JSON document through jsonb_to_recordset (free
	 * text, escaped by json_encode; every value is bound).
	 *
	 * @param list<Row> $rows
	 * @param ?int $year the file's year when DEIS named it, null for a dropped file
	 * @return int the establishments stored
	 */
	public function replace(array $rows, string $origin, ?int $year): int {
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_establishment');
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_establishment (estab, nombre, tipo, dependencia, nivel, comuna_cut, comuna, servicio_codigo, servicio, region_codigo, region, estado) '
				. 'SELECT t.estab, t.nombre, t.tipo, t.dependencia, t.nivel, t.comuna_cut, t.comuna, t.servicio_codigo, t.servicio, t.region_codigo, t.region, t.estado '
				. 'FROM jsonb_to_recordset(CAST(? AS jsonb)) AS t(estab integer, nombre text, tipo text, dependencia text, nivel text, comuna_cut text, '
				. 'comuna text, servicio_codigo text, servicio text, region_codigo text, region text, estado text)',
				[json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
			);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->appConfig->setValueArray(Application::APP_ID, self::KEY, [
			'origin' => $origin,
			'year' => $year,
			'loadedAt' => $this->time->getTime(),
			'rows' => count($rows),
		]);

		return count($rows);
	}

	/** @return array{origin: ?string, year: ?int, loadedAt: ?int, rows: int} */
	public function status(): array {
		$stored = $this->appConfig->getValueArray(Application::APP_ID, self::KEY);

		return [
			'origin' => isset($stored['origin']) ? (string)$stored['origin'] : null,
			'year' => isset($stored['year']) ? (int)$stored['year'] : null,
			'loadedAt' => isset($stored['loadedAt']) ? (int)$stored['loadedAt'] : null,
			'rows' => (int)($stored['rows'] ?? 0),
		];
	}

	/**
	 * One establishment's traits, the ones a peer group is chosen by, or null
	 * when the register does not have that establishment. A consulta reads
	 * them for pares «like ours» (QueryExecutor).
	 *
	 * @return ?array<string, ?string> trait → value, QuerySpec::TRAITS
	 */
	public function traits(int $estab): ?array {
		// The traits are fixed names (QuerySpec::TRAITS), never input.
		$result = $this->db->executeQuery(
			'SELECT ' . implode(', ', QuerySpec::TRAITS) . ' FROM *PREFIX*estadistica_establishment WHERE estab = ?',
			[$estab],
		);
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : $row;
	}

	/**
	 * Each attribute's values, with how many establishments carry each: what
	 * a peer group is chosen from.
	 *
	 * @return array<string, list<array{valor: string, establecimientos: int}>>
	 */
	public function dimensions(): array {
		$dimensions = [];
		foreach (self::DIMENSIONS as $name => $column) {
			// $column is one of the fixed names above, never input.
			$result = $this->db->executeQuery(sprintf(
				'SELECT %1$s AS valor, count(*) AS n FROM *PREFIX*estadistica_establishment WHERE %1$s IS NOT NULL GROUP BY %1$s ORDER BY %1$s',
				$column,
			));
			$dimensions[$name] = array_map(
				static fn (array $row): array => ['valor' => (string)$row['valor'], 'establecimientos' => (int)$row['n']],
				$result->fetchAll(),
			);
			$result->closeCursor();
		}

		return $dimensions;
	}
}
