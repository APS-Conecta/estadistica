<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCA\Estadistica\Rem\RemReader;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The establishment's planillas (docs/adr/0010):
 *
 * - estadistica_code gains where each código's figures sit in its
 *   diccionario: the row (fila) and, per column, the sheet column of its
 *   marker (celdas, JSON). A planilla is read through them (PlanillaReader).
 * - estadistica_planilla: one row per file in the planilla folder, with what
 *   reading it found. A null error is «leído»; «reemplazado» is not stored,
 *   it is read from the others (Planillas::status).
 * - estadistica_planilla_rem: a leído planilla's figures, in estadistica_rem's
 *   typed shape, kept apart from DEIS's and keyed by their file.
 *
 * Diccionarios stored before this have no layout, so each (año, serie) with
 * labels is queued once: the job loads it again, and its diccionario with it.
 *
 * No foreign keys, hasTable() guards (farmacia's doctrine).
 */
class Version000011Date20261105053000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$code = $schema->getTable('estadistica_code');
		if (!$code->hasColumn('fila')) {
			$code->addColumn('fila', Types::INTEGER, ['notnull' => false]);
			$code->addColumn('celdas', Types::TEXT, ['notnull' => false]);
		}

		if (!$schema->hasTable('estadistica_planilla')) {
			$table = $schema->createTable('estadistica_planilla');
			// The Nextcloud file id: a file renamed in the folder is the same planilla.
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			// Unix seconds: the newest saved planilla of a serie and month wins.
			$table->addColumn('mtime', Types::BIGINT, ['notnull' => true]);
			// What it was judged against (Planillas::context): judged again when that changes.
			$table->addColumn('context', Types::STRING, ['notnull' => true, 'length' => 64]);
			// What the form says it is; null when it was refused before saying.
			$table->addColumn('serie', Types::STRING, ['notnull' => false, 'length' => 1]);
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => false]);
			$table->addColumn('mes', Types::SMALLINT, ['notnull' => false]);
			// The Spanish reason it was refused; null when it was read.
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('checked_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['file_id']);
		}

		if (!$schema->hasTable('estadistica_planilla_rem')) {
			$table = $schema->createTable('estadistica_planilla_rem');
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('serie', Types::STRING, ['notnull' => true, 'length' => 1]);
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('mes', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 16]);
			for ($i = 1; $i <= RemReader::COLUMNS; $i++) {
				$table->addColumn(sprintf('col%02d', $i), Types::DECIMAL, ['notnull' => false, 'precision' => 20, 'scale' => 6]);
			}
			$table->setPrimaryKey(['file_id', 'codigo']);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->executeStatement(
			'UPDATE *PREFIX*estadistica_ingest SET pending = true WHERE (anio, serie) IN '
			. '(SELECT DISTINCT anio, serie FROM *PREFIX*estadistica_code WHERE fila IS NULL)',
		);
	}
}
