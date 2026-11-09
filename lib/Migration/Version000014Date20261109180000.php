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
 * estadistica_resumen gains the nota (CONTEXT.md): a model's text about what
 * changed, beside the document and never in it, so the document stays the
 * same bytes for the same inputs (ResumenStore).
 *
 * No foreign keys, hasColumn() guards (farmacia's doctrine).
 */
class Version000014Date20261109180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$table = $schema->getTable('estadistica_resumen');
		if (!$table->hasColumn('nota')) {
			// Null with no provider, until its task answers, and when Nota::check dropped it.
			$table->addColumn('nota', Types::TEXT, ['notnull' => false]);
		}

		return $schema;
	}
}
