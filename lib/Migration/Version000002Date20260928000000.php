<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCA\Estadistica\Rem\RemReader;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The REM pillar's two tables: the national figures, and one row of state per
 * (año, serie) that says what is loaded and what the last attempt did
 * (docs/adr/0004).
 *
 * estadistica_rem is the lean typed shape research measured (≈ 1.08 GB per
 * year of Serie A + P with its indexes; JSONB was ≈ 6×, and the unpivoted long
 * form timed out): one row per establishment, month and código, with its fifty
 * figures typed. What the extract repeats on every row about the establishment
 * (servicio, región, comuna) is not stored: it drifts by era in the extract,
 * and the register holds it once (Slice 4).
 *
 * - Every figure is DECIMAL(20,6) and nullable. DEIS publishes fractions in
 *   every Serie A year, negatives, and values up to 111111111111, so neither
 *   an integer column nor a float is honest. An empty cell is NULL, never 0:
 *   «no informó» and «informó cero» are different answers, and the promedio
 *   por establecimiento counts only who reported.
 * - The primary key is the natural key (research: zero duplicates in every
 *   sampled member). Its prefix (anio, serie, codigo) serves the national and
 *   peer consultas; (estab, anio) serves the local one.
 * - The NOT NULL columns carry no defaults because the table has exactly one
 *   writer, RemLoader, whose INSERT names every one of them. There is no
 *   Entity here for lesson #74 to bite.
 *
 * estadistica_ingest applies lesson #74: every NOT NULL column the Entity may
 * leave unmarked has a default equal to the Entity's initial value. The two
 * without one (anio, serie) are set by both paths that create a row
 * (IngestStateMapper::markPending and ::findOrNew).
 *
 * No foreign keys, hasTable() guards (farmacia's doctrine).
 */
class Version000002Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_rem')) {
			$table = $schema->createTable('estadistica_rem');
			$table->addColumn('serie', Types::STRING, ['notnull' => true, 'length' => 1]);
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('mes', Types::SMALLINT, ['notnull' => true]);
			// Always the 6-digit code. RemReader maps the 2009–2013 SS-LLL form on
			// the way in, so one establishment is one number in every year.
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			// Text, never a number: códigos keep their leading zeros (02010420).
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 16]);
			for ($i = 1; $i <= RemReader::COLUMNS; $i++) {
				$table->addColumn(sprintf('col%02d', $i), Types::DECIMAL, ['notnull' => false, 'precision' => 20, 'scale' => 6]);
			}
			$table->setPrimaryKey(['anio', 'serie', 'codigo', 'mes', 'estab']);
			$table->addIndex(['estab', 'anio'], 'estad_rem_estab_idx');
		}

		if (!$schema->hasTable('estadistica_ingest')) {
			$table = $schema->createTable('estadistica_ingest');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('serie', Types::STRING, ['notnull' => true, 'length' => 1]);
			// Where the loaded rows came from: 'deis' or 'archivo' (a dropped zip).
			// Empty until something loads.
			$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => '']);
			$table->addColumn('row_count', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			// YYYY-MM of the latest month the loaded rows carry. Null while none are.
			$table->addColumn('fecha_corte', Types::STRING, ['notnull' => false, 'length' => 7]);
			// Null for a complete load; the «incompleto» sentences otherwise.
			$table->addColumn('detail', Types::TEXT, ['notnull' => false]);
			// JSON, servicio → establishments reporting: Completeness's baseline
			// for the neighbouring years.
			$table->addColumn('profile', Types::TEXT, ['notnull' => false]);
			// The member last judged on its content: its CRC-32 and inflated size
			// from the central directory.
			$table->addColumn('member_crc', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('member_size', Types::BIGINT, ['notnull' => false]);
			// Queued for one attempt: by a drop now, by the daily upstream check
			// from Slice 3.
			$table->addColumn('pending', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			// Unix seconds.
			$table->addColumn('last_attempt', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('last_success', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			// The upsert's arbiter (IngestStateMapper::markPending): one row per pair.
			$table->addUniqueIndex(['anio', 'serie'], 'estad_ingest_key_idx');
		}

		return $schema;
	}
}
