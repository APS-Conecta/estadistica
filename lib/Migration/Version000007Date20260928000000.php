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
 * What staff save for everyone to read (Slice 7, acceptance a8): preguntas
 * and tableros. The earlier tables are left as Slices 2 and 4 made them.
 *
 * - estadistica_question: one consulta, kept as the JSON its author sent
 *   (docs/adr/0006 amendment), with a title and how it is drawn. The chart
 *   type lives here because the spec never says it: a chart is how an answer
 *   is drawn, not what is asked.
 * - estadistica_dashboard: a title and its cards, a JSON list
 *   [{"pregunta": id, "ancho": 1–3}] in display order (run plan baseline: a
 *   JSON column, no tablero-items table). A card points at a pregunta and
 *   copies nothing, so a renamed pregunta shows renamed on every tablero, and
 *   a deleted one leaves a card that says so.
 *
 * owner is the creator's user id. Everyone reads every row (D6); the owner
 * and whoever administers Estadística change it (SavedContent).
 *
 * Lesson #74: every column is NOT NULL without a default, the entities
 * initialize none of them, and SavedContent's create path sets each one, so
 * no setter can skip a column an INSERT needs. No foreign keys, hasTable()
 * guards.
 */
class Version000007Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_question')) {
			$table = $schema->createTable('estadistica_question');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 200]);
			$table->addColumn('spec', Types::TEXT, ['notnull' => true]);
			// barra, linea or tabla (SavedContent::CHART_TYPES).
			$table->addColumn('chart', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Unix seconds.
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
		}

		if (!$schema->hasTable('estadistica_dashboard')) {
			$table = $schema->createTable('estadistica_dashboard');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 200]);
			$table->addColumn('layout', Types::TEXT, ['notnull' => true]);
			$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Unix seconds.
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}
}
