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
 * estadistica_resumen: the stored Resumen (docs/adr/0009, «computed once per
 * change, then read»): only the configured establishment's Resumen, replaced
 * whole by each rebuild (ResumenStore). The build time, the fingerprint and
 * the last failure sit beside the document, never in it.
 *
 * No foreign keys, hasTable() guards (farmacia's doctrine).
 */
class Version000012Date20261105120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_resumen')) {
			$table = $schema->createTable('estadistica_resumen');
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			// The JSON GET resumen serves; null until a rebuild first succeeds.
			$table->addColumn('document', Types::TEXT, ['notnull' => false]);
			// sha256 of the document: the same inputs give the same one.
			$table->addColumn('fingerprint', Types::STRING, ['notnull' => false, 'length' => 64]);
			// Unix seconds the document was computed.
			$table->addColumn('built_at', Types::BIGINT, ['notnull' => false]);
			// The Spanish reason the last rebuild failed; null once one succeeds.
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['estab']);
		}

		return $schema;
	}
}
