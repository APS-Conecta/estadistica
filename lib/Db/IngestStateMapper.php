<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<IngestState> */
class IngestStateMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'estadistica_ingest', IngestState::class);
	}

	/** @return list<IngestState> newest year first, A before P */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('anio', 'DESC')
			->addOrderBy('serie', 'ASC');

		return $this->findEntities($qb);
	}

	/** The (año, serie)'s row, or a new one not yet saved. */
	public function findOrNew(int $anio, string $serie): IngestState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('anio', $qb->createNamedParameter($anio, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('serie', $qb->createNamedParameter($serie)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			$state = new IngestState();
			$state->setAnio($anio);
			$state->setSerie($serie);

			return $state;
		}
	}

	/**
	 * The next (año, serie) queued for one attempt. The newest year goes first,
	 * because it holds the figures people consult most.
	 */
	public function nextPending(): ?IngestState {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pending', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('anio', 'DESC')
			->addOrderBy('serie', 'ASC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function hasPending(int $anio): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->getTableName())
			->where($qb->expr()->eq('anio', $qb->createNamedParameter($anio, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pending', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * Queues (año, serie) for one attempt, creating its row if this is the first
	 * anyone has heard of it.
	 *
	 * It is one statement, PostgreSQL's upsert (docs/adr/0002). A drop that
	 * arrives while the job is loading the same (año, serie) is therefore
	 * queued, not lost: the job consumed the flag when its attempt began
	 * (RemImporter::begin).
	 */
	public function markPending(int $anio, string $serie): void {
		$this->db->executeStatement(
			'INSERT INTO *PREFIX*estadistica_ingest (anio, serie, pending) VALUES (?, ?, true) '
			. 'ON CONFLICT (anio, serie) DO UPDATE SET pending = true',
			[$anio, $serie],
		);
	}

	/**
	 * Inserts or updates, then forgets what was written. The next save then
	 * carries only what changed after this one, which is what lets
	 * RemImporter leave a concurrently queued flag alone.
	 */
	public function save(IngestState $state): IngestState {
		$saved = $state->getId() === null ? $this->insert($state) : $this->update($state);
		$saved->resetUpdatedFields();

		return $saved;
	}

	/**
	 * The profile of the nearest other year of the same serie whose load was
	 * complete: the baseline Completeness compares a member against. The
	 * nearest year wins; on a tie the earlier one, which is a closed year.
	 *
	 * @return ?array{anio: int, profile: array<string, int>}
	 */
	public function neighbour(int $anio, string $serie): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('anio', 'profile')
			->from($this->getTableName())
			->where($qb->expr()->eq('serie', $qb->createNamedParameter($serie)))
			->andWhere($qb->expr()->neq('anio', $qb->createNamedParameter($anio, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('profile'))
			->andWhere($qb->expr()->isNull('detail'));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		if ($rows === []) {
			return null;
		}

		usort($rows, static fn (array $a, array $b): int => [abs((int)$a['anio'] - $anio), (int)$a['anio']]
			<=> [abs((int)$b['anio'] - $anio), (int)$b['anio']]);

		return [
			'anio' => (int)$rows[0]['anio'],
			'profile' => json_decode((string)$rows[0]['profile'], true, 512, JSON_THROW_ON_ERROR),
		];
	}
}
