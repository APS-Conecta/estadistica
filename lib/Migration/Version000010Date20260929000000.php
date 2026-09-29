<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Indexes for the lookups on tables that grow (code review Q4):
 *
 * - estadistica_code (≈ 5,000 códigos a year and serie): Catalog::scope()
 *   finds a hoja's códigos by serie and hoja over the periodo's years;
 * - estadistica_question and estadistica_dashboard: the lists, newest first.
 *
 * estadistica_ingest gets none: two series a year over the window is a few
 * dozen rows, which PostgreSQL reads whole whatever index it has. Its unique
 * (anio, serie) is the upsert's arbiter and already there.
 */
class Version000010Date20260929000000 extends SimpleMigrationStep {
	private const INDEXES = [
		'estadistica_code' => ['estad_code_hoja_idx', ['serie', 'hoja', 'anio']],
		'estadistica_question' => ['estad_question_upd_idx', ['updated_at', 'id']],
		'estadistica_dashboard' => ['estad_dashboard_upd_idx', ['updated_at', 'id']],
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (self::INDEXES as $name => [$index, $columns]) {
			$table = $schema->getTable($name);
			if (!$table->hasIndex($index)) {
				$table->addIndex($columns, $index);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
