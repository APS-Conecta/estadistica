<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The Metas Sanitarias (Slice 9, acceptance a9–a13). The earlier tables are
 * left as Slices 2, 4, 7 and 8 made them.
 *
 * - estadistica_goal: one row per year, its seed file kept as it was loaded
 *   (docs/adr/0008). The metas are always read a year at a time, and the file
 *   is judged again on every read (GoalSeed), as a pregunta's consulta is.
 *   version is the file's own: a shipped file replaces a stored one only when
 *   its version is higher (EnsureSeedData).
 * - estadistica_commitment: what this install's establishment committed for a
 *   meta of a year: its meta comprometida (percent), its denominador
 *   comprometido (people) and, for a manual meta, its cumplimiento (percent).
 *   Each is null until an admin types it. estab keeps the commitments of
 *   another establishment out of this one's metas if the install is ever
 *   configured anew (gestion ADR-0013).
 *
 * Every NOT NULL column has no default, and each table has one writer
 * (Goals), whose INSERT names every column (lesson #74). No foreign keys,
 * hasTable() guards.
 */
class Version000009Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_goal')) {
			$table = $schema->createTable('estadistica_goal');
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true]);
			// The seed file, as JSON.
			$table->addColumn('document', Types::TEXT, ['notnull' => true]);
			// Unix seconds.
			$table->addColumn('loaded_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['anio']);
		}

		if (!$schema->hasTable('estadistica_commitment')) {
			$table = $schema->createTable('estadistica_commitment');
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			// The meta's codigo in its year's seed: I, II, III.A …
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('meta', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('denominador', Types::FLOAT, ['notnull' => false]);
			$table->addColumn('cumplimiento', Types::FLOAT, ['notnull' => false]);
			// Unix seconds.
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['anio', 'codigo', 'estab']);
		}

		return $schema;
	}
}
