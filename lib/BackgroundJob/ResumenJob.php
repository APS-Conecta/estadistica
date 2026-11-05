<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\BackgroundJob;

use OCA\Estadistica\Goals\ResumenStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Rebuilds the stored Resumen once (docs/adr/0009, «computed once per
 * change, then read»). Queued where a change the Resumen reads commits: a
 * REM load (RemImporter), the commitments (Goals::commit) and a metas seed
 * (Goals::store). Nextcloud keeps one queued copy of a job, so changes that
 * arrive before it runs share one rebuild, and a change during a run queues
 * the next.
 */
class ResumenJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ResumenStore $resumen,
	) {
		parent::__construct($time);

		// Two rebuilds at once could store the older snapshot last.
		$this->setAllowParallelRuns(false);
	}

	#[\Override]
	protected function run($argument): void {
		$this->resumen->rebuild();
	}
}
