<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\BackgroundJob;

use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Service\DroppedFiles;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * The only scheduled work in the app, declared in info.xml (acceptance a18:
 * nothing on the host's scheduler).
 *
 * Each hourly tick does at most ONE unit of work: it **loads one queued
 * (año, serie)** from the admin's drop, the newest year first. The largest
 * member takes ≈ 3.4 min (2024 Serie A, 6.56M rows at the measured 32k
 * rows/s), inside one cron invocation. With nothing queued, it removes the
 * drops left over from an interrupted run.
 *
 * Otherwise the tick touches no network, only two indexed queries.
 *
 * Outcomes are the importer's to record. RemImporter never throws for a
 * member, and a pair that cannot even start is recorded through fail(). What
 * can still reach this job's runner is a failure to record one, meaning the
 * database itself is failing, and Nextcloud's runner logs it.
 */
class RemJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IngestStateMapper $states,
		private DroppedFiles $drops,
		private RemImporter $importer,
	) {
		parent::__construct($time);

		$this->setInterval(3600);
		// The owner's cadence: REMs publish monthly and semi-annually, and people
		// consult sometimes, so the tick is hourly and mostly does nothing.
		// TIME_SENSITIVE so it runs around the clock, not only in the 05–09 UTC
		// maintenance window. This is decided here, at the class's creation,
		// because Nextcloud ratchets it: the flag flips to insensitive once and
		// nothing resets it (JobList::setLastRun), so a change needs a new job
		// class.
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
		// Two runs at once would load the same (año, serie) twice over.
		$this->setAllowParallelRuns(false);
	}

	#[\Override]
	protected function run($argument): void {
		$next = $this->states->nextPending();
		if ($next !== null) {
			$this->load($next->getAnio(), $next->getSerie());
			return;
		}

		$this->drops->removeStale();
	}

	private function load(int $year, string $serie): void {
		try {
			$local = $this->drops->localCopyOf($year);
		} catch (RefusedException $e) {
			$this->importer->fail($year, $serie, $e->getMessage());
			return;
		}

		if ($local === null) {
			$this->importer->fail($year, $serie, 'No se encontró el ZIP recibido de este año: vuelva a elegirlo en «Cifras REM cargadas».');
			return;
		}

		try {
			$this->importer->import(new LocalRange($local), $year, $serie, IngestState::ORIGIN_FILE);
		} finally {
			unlink($local);
		}

		// The drop served every series it queued once none of its year is still
		// waiting. A pair a newer drop re-queued keeps its file for the next tick.
		if (!$this->states->hasPending($year)) {
			$this->drops->remove($year);
		}
	}
}
