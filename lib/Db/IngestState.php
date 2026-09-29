<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * What is loaded for one (año, serie) of the national REM, and what happened
 * the last time the job tried (acceptance a4). The admin section lists these;
 * the fuentes read their fecha de corte and completeness from them (Slice 4).
 *
 * The row has two halves, which change at different moments on purpose:
 *
 * - **The load** (origin, rowCount, fechaCorte, detail, profile, lastSuccess)
 *   changes only when a member is loaded, in the same transaction as its rows,
 *   so it always describes the rows actually stored.
 * - **The attempt** (lastAttempt, lastError, pending, memberCrc, memberSize)
 *   is written on every try. lastError is null exactly when the last attempt
 *   loaded.
 *
 * detail is null for a complete load and holds the «incompleto» sentences
 * (Completeness) otherwise: one fact, one column.
 *
 * memberCrc and memberSize identify the member last judged on its content:
 * loaded, or kept out because it was incomplete over a complete load. The
 * daily upstream check (Slice 3) re-queues an (año, serie) only when DEIS
 * publishes different bytes. A transport failure judged nothing, so it leaves
 * them alone and is retried.
 *
 * Lesson #74: every property initialized to a value is a NOT NULL column whose
 * default is that same value (Version000002Date20260928000000). A setter that
 * skips an equal value therefore never leaves out a column an INSERT needed.
 *
 * @method int getAnio()
 * @method void setAnio(int $anio)
 * @method string getSerie()
 * @method void setSerie(string $serie)
 * @method string getOrigin()
 * @method void setOrigin(string $origin)
 * @method int getRowCount()
 * @method void setRowCount(int $rowCount)
 * @method ?string getFechaCorte()
 * @method void setFechaCorte(?string $fechaCorte)
 * @method ?string getDetail()
 * @method void setDetail(?string $detail)
 * @method ?string getProfile()
 * @method void setProfile(?string $profile)
 * @method ?int getMemberCrc()
 * @method void setMemberCrc(?int $memberCrc)
 * @method ?int getMemberSize()
 * @method void setMemberSize(?int $memberSize)
 * @method bool getPending()
 * @method void setPending(bool $pending)
 * @method ?int getLastAttempt()
 * @method void setLastAttempt(?int $lastAttempt)
 * @method ?int getLastSuccess()
 * @method void setLastSuccess(?int $lastSuccess)
 * @method ?string getLastError()
 * @method void setLastError(?string $lastError)
 */
class IngestState extends Entity {
	public const ORIGIN_DEIS = 'deis';
	public const ORIGIN_FILE = 'archivo';

	// Null until set: both paths that create a row set them (the migration
	// gives them no default).
	protected $anio = null;
	protected $serie = null;
	protected $origin = '';
	protected $rowCount = 0;
	protected $fechaCorte = null;
	protected $detail = null;
	protected $profile = null;
	protected $memberCrc = null;
	protected $memberSize = null;
	protected $pending = false;
	protected $lastAttempt = null;
	protected $lastSuccess = null;
	protected $lastError = null;

	public function __construct() {
		$this->addType('anio', Types::SMALLINT);
		$this->addType('rowCount', Types::BIGINT);
		$this->addType('memberCrc', Types::BIGINT);
		$this->addType('memberSize', Types::BIGINT);
		$this->addType('pending', Types::BOOLEAN);
		$this->addType('lastAttempt', Types::BIGINT);
		$this->addType('lastSuccess', Types::BIGINT);
	}

	public function isIncomplete(): bool {
		return $this->getDetail() !== null;
	}

	/**
	 * What the admin section's table shows, one row per (año, serie).
	 *
	 * @return array{anio: int, serie: string, origin: string, rowCount: int, fechaCorte: ?string, incomplete: bool, detail: ?string, pending: bool, lastAttempt: ?int, lastSuccess: ?int, lastError: ?string}
	 */
	public function toClient(): array {
		return [
			'anio' => $this->getAnio(),
			'serie' => $this->getSerie(),
			'origin' => $this->getOrigin(),
			'rowCount' => $this->getRowCount(),
			'fechaCorte' => $this->getFechaCorte(),
			'incomplete' => $this->isIncomplete(),
			'detail' => $this->getDetail(),
			'pending' => $this->getPending(),
			'lastAttempt' => $this->getLastAttempt(),
			'lastSuccess' => $this->getLastSuccess(),
			'lastError' => $this->getLastError(),
		];
	}
}
