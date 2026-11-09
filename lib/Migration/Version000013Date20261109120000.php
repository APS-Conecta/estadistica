<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * estadistica_planilla gains the establishment its form names: a planilla is
 * that establishment's local series for its serie and month, and no other's
 * (docs/adr/0010). A planilla read before this has none, so each is judged
 * again at the next scan, which writes it.
 *
 * No foreign keys, hasColumn() guards (farmacia's doctrine).
 */
class Version000013Date20261109120000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('estadistica_planilla');
		if (!$table->hasColumn('estab')) {
			// Null when it was refused before saying.
			$table->addColumn('estab', Types::INTEGER, ['notnull' => false]);
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->executeStatement("UPDATE *PREFIX*estadistica_planilla SET context = '' WHERE estab IS NULL AND error IS NULL");
	}
}
