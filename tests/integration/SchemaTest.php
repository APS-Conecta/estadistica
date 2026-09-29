<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCP\IConfig;
use OCP\Server;

/**
 * The lookups that read a table that grows have an index behind them
 * (Version000010). What only the migrated database can show: the index
 * exists, on the columns the lookup leads with.
 */
final class SchemaTest extends IntegrationTestCase {
	/** @return array<string, array{string, string, string}> table, index, its columns as pg_indexes prints them */
	public static function indexes(): array {
		return [
			'the upsert\'s arbiter (IngestStateMapper::markPending)' => ['estadistica_ingest', 'estad_ingest_key_idx', '(anio, serie)'],
			'a hoja\'s códigos (Catalog::scope)' => ['estadistica_code', 'estad_code_hoja_idx', '(serie, hoja, anio)'],
			'the preguntas, newest first' => ['estadistica_question', 'estad_question_upd_idx', '(updated_at, id)'],
			'the tableros, newest first' => ['estadistica_dashboard', 'estad_dashboard_upd_idx', '(updated_at, id)'],
		];
	}

	/** @dataProvider indexes */
	public function testTheLookupHasItsIndex(string $table, string $index, string $columns): void {
		$definition = $this->db()->executeQuery(
			'SELECT indexdef FROM pg_indexes WHERE tablename = ? AND indexname = ?',
			[Server::get(IConfig::class)->getSystemValueString('dbtableprefix', 'oc_') . $table, $index],
		)->fetchOne();

		self::assertIsString($definition, "$table has no $index");
		self::assertStringEndsWith($columns, $definition);
	}
}
