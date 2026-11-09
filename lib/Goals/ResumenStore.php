<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The stored Resumen (docs/adr/0009, «computed once per change, then read»).
 *
 * - **Rebuilt in the background.** ResumenJob calls rebuild() after a change
 *   the Resumen reads commits. It builds the configured establishment's
 *   Resumen the one way there is (Resumen::build) and replaces its row whole,
 *   in one statement. That row is the only one kept: each rebuild first drops
 *   any other establishment's, which could otherwise be served stale once
 *   that establishment is configured again.
 * - **Read as stored.** read() answers the configured establishment with
 *   the stored document, so every reader sees the same figures until the
 *   next rebuild. Any other establishment is built on request and never
 *   stored, and so is the configured one until a rebuild first succeeds.
 * - **Deterministic.** The document holds only what Resumen::build answers,
 *   so the same inputs give the same bytes. The build time and the
 *   fingerprint (sha256 of the document) sit beside it, never in it.
 * - **A failure keeps the last good one.** A rebuild that fails leaves the
 *   stored document serving and records its Spanish reason, which the admin
 *   section shows until a rebuild succeeds.
 * - **Its nota beside it** (CONTEXT.md). A rebuild that changes the
 *   fingerprint drops the stored nota and asks Nota for the next, from the
 *   previous document; note() keeps the answer only while the fingerprint it
 *   was asked for is still the stored one, and only as Nota::check passes it.
 *   read() serves it as nota, beside the document's keys (served()).
 *
 * @psalm-import-type Respuesta from Resumen
 */
class ResumenStore {
	public const INTERNAL = 'El cálculo falló por un error interno; el detalle quedó en el registro del servidor.';

	private const JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	public function __construct(
		private IDBConnection $db,
		private Resumen $resumen,
		private EstablishmentConfig $establishment,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private Nota $nota,
	) {
	}

	/**
	 * $local's Resumen: the stored one when $local is this install's
	 * establishment and one is stored, else built now and not kept.
	 *
	 * @return Respuesta&array{nota: ?string} served()
	 * @throws DoesNotExistException no metas are loaded
	 * @throws RefusedException
	 */
	public function read(Establishment $local): array {
		if ($local->isConfigured() && $local->deisCode === $this->establishment->get()->deisCode) {
			$result = $this->db->executeQuery('SELECT document, nota FROM *PREFIX*estadistica_resumen WHERE estab = ? AND document IS NOT NULL', [(int)$local->deisCode]);
			$row = $result->fetch();
			$result->closeCursor();
			if ($row !== false) {
				return self::served(self::decode($row['document']), $row['nota']);
			}
		}

		return self::served($this->resumen->build($local), null);
	}

	/**
	 * What GET resumen serves: the document's keys, and its nota beside them
	 * (null when there is none), in one place so ApiContractTest reads them.
	 *
	 * @param Respuesta $document
	 * @return Respuesta&array{nota: ?string}
	 */
	public static function served(array $document, ?string $nota): array {
		return [...$document, 'nota' => $nota];
	}

	/** Builds the configured establishment's Resumen and stores it whole, the only row kept; a failure keeps the stored one and records why. */
	public function rebuild(): void {
		$local = $this->establishment->get();
		if (!$local->isConfigured()) {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_resumen');
			return;
		}
		$estab = (int)$local->deisCode;
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_resumen WHERE estab <> ?', [$estab]);
		$result = $this->db->executeQuery('SELECT document, fingerprint FROM *PREFIX*estadistica_resumen WHERE estab = ? AND document IS NOT NULL', [$estab]);
		$before = $result->fetch();
		$result->closeCursor();

		try {
			$built = $this->resumen->build($local);
			$document = json_encode($built, self::JSON);
		} catch (DoesNotExistException) {
			// No metas loaded: nothing to show, and nothing failed.
			return;
		} catch (Throwable $e) {
			if (!$e instanceof RefusedException) {
				$this->logger->warning('Rebuilding the Resumen of {estab} failed', ['estab' => $estab, 'exception' => $e]);
			}
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_resumen (estab, error) VALUES (?, ?) '
				. 'ON CONFLICT (estab) DO UPDATE SET error = EXCLUDED.error',
				[$estab, $e instanceof RefusedException ? $e->getMessage() : self::INTERNAL],
			);
			return;
		}

		$fingerprint = hash('sha256', $document);
		// The nota stays only with the fingerprint it was written for.
		$this->db->executeStatement(
			'INSERT INTO *PREFIX*estadistica_resumen (estab, document, fingerprint, built_at, error) VALUES (?, ?, ?, ?, NULL) '
			. 'ON CONFLICT (estab) DO UPDATE SET document = EXCLUDED.document, fingerprint = EXCLUDED.fingerprint, built_at = EXCLUDED.built_at, error = NULL, '
			. 'nota = CASE WHEN *PREFIX*estadistica_resumen.fingerprint = EXCLUDED.fingerprint THEN *PREFIX*estadistica_resumen.nota END',
			[$estab, $document, $fingerprint, $this->time->getTime()],
		);
		if ($before !== false && $before['fingerprint'] !== $fingerprint) {
			$this->nota->request(self::decode($before['document']), $built, $fingerprint);
		}
	}

	/**
	 * Keeps $answer as the stored Resumen's nota, when it was written for the
	 * stored fingerprint and Nota::check passes it against that document.
	 */
	public function note(string $fingerprint, string $answer): void {
		$local = $this->establishment->get();
		if (!$local->isConfigured()) {
			return;
		}
		$estab = (int)$local->deisCode;
		$result = $this->db->executeQuery('SELECT document FROM *PREFIX*estadistica_resumen WHERE estab = ? AND fingerprint = ?', [$estab, $fingerprint]);
		$document = $result->fetchOne();
		$result->closeCursor();
		$nota = $document === false ? null : Nota::check($answer, self::decode($document));
		if ($nota !== null) {
			$this->db->executeStatement('UPDATE *PREFIX*estadistica_resumen SET nota = ? WHERE estab = ? AND fingerprint = ?', [$nota, $estab, $fingerprint]);
		}
	}

	/** @return Respuesta */
	private static function decode(string $document): array {
		return json_decode($document, true, 64, JSON_THROW_ON_ERROR);
	}

	/**
	 * What the admin section shows: when the stored Resumen was computed, and
	 * why the last rebuild failed. Null without an establishment, or before
	 * any rebuild.
	 *
	 * @return ?array{builtAt: ?int, error: ?string}
	 */
	public function status(): ?array {
		$local = $this->establishment->get();
		if (!$local->isConfigured()) {
			return null;
		}
		$result = $this->db->executeQuery('SELECT built_at, error FROM *PREFIX*estadistica_resumen WHERE estab = ?', [(int)$local->deisCode]);
		$row = $result->fetch();
		$result->closeCursor();

		return $row === false ? null : [
			'builtAt' => $row['built_at'] === null ? null : (int)$row['built_at'],
			'error' => $row['error'] === null ? null : (string)$row['error'],
		];
	}
}
