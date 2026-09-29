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
 * FONASA's Población Inscrita Validada (Slice 8, acceptance a11): the
 * denominator of the metas that count people rather than prestaciones. The
 * earlier tables are left as Slices 2, 4 and 7 made them.
 *
 * - estadistica_piv: one row per corte, establishment, age band and sex, and
 *   whether an admin typed it. A corte from FONASA's file holds its inscritos
 *   summed over FONASA's income tramo (A/B/C/D/X), which no meta reads. The
 *   establishment's name, comuna and servicio are the register's (one source
 *   per fact; owner, Slice 8).
 *   - edad is the band's first year (0, 10 … 80; «80 a más» is 80).
 *   - sexo is M, H or S (sin información).
 *   - manual is 1 for a figure an admin typed for this install's own
 *     establishment. It wins over FONASA's for that establishment alone
 *     (docs/adr/0007).
 *
 * Every column is NOT NULL without a default. The table has one writer,
 * PivImporter's single INSERT, which names every column (lesson #74). No
 * foreign keys, and a hasTable() guard.
 */
class Version000008Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_piv')) {
			$table = $schema->createTable('estadistica_piv');
			$table->addColumn('corte', Types::DATE, ['notnull' => true]);
			// FONASA's COD_CENTRO: an establishment's six-digit DEIS code, or a comuna's longer administrative one.
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('edad', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('sexo', Types::STRING, ['notnull' => true, 'length' => 1]);
			// 0: FONASA's file. 1: typed by an admin.
			$table->addColumn('manual', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('inscritos', Types::INTEGER, ['notnull' => true]);
			// Unix seconds.
			$table->addColumn('loaded_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['corte', 'estab', 'edad', 'sexo', 'manual']);
		}

		return $schema;
	}
}
