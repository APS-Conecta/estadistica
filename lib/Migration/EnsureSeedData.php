<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Goals\Goals;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Loads the Metas Sanitarias the app ships (seeds/metas-<año>.json) into
 * estadistica_goal (acceptance a9, docs/adr/0008).
 *
 * A repair step, not a migration's postSchemaChange: a first `occ app:enable`
 * applies the schema only, and skips postSchemaChange (farmacia's
 * EnsureSeedData, whose doc says why). Registered for both install and
 * post-migration in appinfo/info.xml.
 *
 * A shipped file loads when its year has no metas, or holds an older version
 * of them: a release corrects its own seed. A file an admin loaded through
 * the door with a version as high or higher is theirs, and stays. The
 * commitments are never touched.
 *
 * A shipped file that cannot be loaded, whatever refuses it (GoalSeed or the
 * database), is reported and skipped. It would be a bug in the release, and it
 * must not stop the other seeds, nor the app from enabling.
 */
class EnsureSeedData implements IRepairStep {
	private const SEEDS = __DIR__ . '/../../seeds';

	public function __construct(
		private IDBConnection $db,
		private Goals $goals,
	) {
	}

	public function getName(): string {
		return 'Estadística: load the Metas Sanitarias the app ships';
	}

	public function run(IOutput $output): void {
		if (!$this->db->tableExists('estadistica_goal')) {
			return;
		}

		foreach (glob(self::SEEDS . '/metas-*.json') ?: [] as $file) {
			try {
				$raw = json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
				$anio = is_array($raw) && is_int($raw['anio'] ?? null) ? $raw['anio'] : null;
				$version = is_array($raw) && is_int($raw['version'] ?? null) ? $raw['version'] : null;
				if ($anio === null || $version === null) {
					throw new RefusedException('no dice su anio y su version');
				}
				$stored = $this->goals->version($anio);
				if ($stored !== null && $stored >= $version) {
					continue;
				}
				$loaded = $this->goals->store($raw);
				$output->info(sprintf('Estadística: loaded the %d Metas Sanitarias, version %d (%d metas)', $loaded['anio'], $loaded['version'], $loaded['indicadores']));
			} catch (Throwable $e) {
				// Any failure, the database's too: one seed must not stop the others,
				// nor the app from enabling.
				$output->warning(sprintf('Estadística: %s was not loaded: %s', basename($file), $e->getMessage()));
			}
		}
	}
}
