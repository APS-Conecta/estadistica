<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one importer (acceptance a3). The job calls it for a member DEIS
 * published (HttpRange) and for an admin's dropped zip (LocalRange) alike. Given a zip's
 * bytes, a year and a serie, it replaces that (año, serie) in one transaction
 * and records the outcome on its state row, which the admin section lists
 * (a4):
 *
 * - **Loaded.** The rows and the state commit together.
 * - **Incomplete** (Completeness). The member is loaded and flagged
 *   «incompleto»: the owner's decision for a member of which no complete copy
 *   exists, like 2025 Serie A. The exception is when a complete load of that
 *   (año, serie) is already stored. That load is kept, and the new member's
 *   shortfall is reported instead (a4: a truncated member leaves the previous
 *   rows readable and unchanged).
 * - **Refused or failed.** Rolled back, so the previous rows keep serving,
 *   with the sentence that says why. The #74 lesson: a failure never replaces
 *   the last good copy, and never goes quiet.
 *
 * Outcomes are recorded, not thrown. What still propagates is a failure to
 * record one, the database itself; the job's runner logs it.
 *
 * @psalm-import-type Member from ZipDirectory
 */
class RemImporter {
	/**
	 * Written when an attempt starts and overwritten when it ends. If it is still
	 * there later, the process died mid-load, and the row says so rather than
	 * looking like nothing happened.
	 */
	public const UNFINISHED = 'La carga empezó y no ha terminado; si sigue así pasados 15 minutos, se interrumpió.';

	private const INTERNAL = 'La carga falló por un error interno; el detalle quedó en el registro del servidor.';

	public function __construct(
		private IDBConnection $db,
		private IngestStateMapper $states,
		private RemLoader $loader,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function import(RangeReader $range, int $year, string $serie, string $origin): IngestState {
		$state = $this->begin($year, $serie);

		try {
			$zip = ZipDirectory::read($range);
			$member = $zip->member($serie);
		} catch (Throwable $e) {
			return $this->failed($state, $e);
		}

		return $this->replace($range, $member, $state, $year, $serie, $origin);
	}

	/**
	 * The figures: (año, serie)'s rows and their state, in one transaction.
	 *
	 * @param Member $member
	 */
	private function replace(RangeReader $range, array $member, IngestState $state, int $year, string $serie, string $origin): IngestState {
		// Read before the transaction: the baseline and the load to protect are
		// what was stored when this attempt began.
		$keepComplete = $state->getRowCount() > 0 && !$state->isIncomplete();
		$neighbour = $this->states->neighbour($year, $serie);

		$this->db->beginTransaction();
		try {
			$rows = RemReader::rows(MemberLines::of($range, $member), $year);
			$this->loader->replace($serie, $year, $rows);
			$summary = $rows->getReturn();
			$findings = Completeness::of($summary, $neighbour);

			// Either way below, the member was judged on its content. The daily
			// upstream check (DeisRepository::check) compares against this
			// identity, so the same bytes are not read again.
			if ($findings !== [] && $keepComplete) {
				$this->db->rollBack();
				$state->setMemberCrc($member['crc']);
				$state->setMemberSize($member['size']);
				$state->setLastError('Se mantiene la carga anterior, que estaba completa: la versión nueva viene incompleta. ' . implode(' ', $findings));

				return $this->states->save($state);
			}

			$state->setMemberCrc($member['crc']);
			$state->setMemberSize($member['size']);
			$state->setOrigin($origin);
			$state->setRowCount($summary['rows']);
			$state->setFechaCorte(sprintf('%04d-%02d', $year, $summary['lastMonth']));
			$state->setDetail($findings === [] ? null : implode("\n", $findings));
			$state->setProfile(json_encode($summary['profile'], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
			$state->setLastSuccess($this->time->getTime());
			$state->setLastError(null);
			$this->states->save($state);
			$this->db->commit();

			return $state;
		} catch (Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}

			// Read the row afresh: whatever this attempt set on $state described
			// rows that were just rolled back.
			return $this->failed($this->states->findOrNew($year, $serie), $e);
		}
	}

	/**
	 * An attempt that could not start: the job found nothing to read, or the
	 * install does not consult DEIS. Recorded like any other failure, so the
	 * queued row does not wait forever.
	 */
	public function fail(int $year, string $serie, string $message): IngestState {
		$state = $this->begin($year, $serie);
		$state->setLastError($message);

		return $this->states->save($state);
	}

	/**
	 * Lets go of a year the window no longer keeps: its rows and its state rows,
	 * in one transaction, so no state row ever describes rows that are gone.
	 * The job calls it, one year per tick (the Window decision: narrowing
	 * happens in the job, never in the request).
	 */
	public function forget(int $year): void {
		$this->db->beginTransaction();
		try {
			$this->loader->forget($year);
			$this->states->forget($year);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Records the attempt before any work, in its own statement:
	 *
	 * - the section shows it while a long member loads;
	 * - the queue flag is consumed here, so a drop that queues the same
	 *   (año, serie) while this attempt runs survives it: the save at the end
	 *   writes only what changed after this point.
	 */
	private function begin(int $year, string $serie): IngestState {
		$state = $this->states->findOrNew($year, $serie);
		$state->setLastAttempt($this->time->getTime());
		$state->setPending(false);
		$state->setLastError(self::UNFINISHED);

		return $this->states->save($state);
	}

	private function failed(IngestState $state, Throwable $e): IngestState {
		if ($e instanceof RefusedException) {
			$message = $e->getMessage();
		} else {
			$this->logger->warning('Loading REM {anio} Serie {serie} failed', [
				'anio' => $state->getAnio(),
				'serie' => $state->getSerie(),
				'exception' => $e,
			]);
			$message = self::INTERNAL;
		}
		$state->setLastError($message);

		return $this->states->save($state);
	}
}
