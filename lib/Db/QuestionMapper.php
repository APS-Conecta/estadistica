<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Question> */
class QuestionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'estadistica_question', Question::class);
	}

	/** @return list<Question> the most recently changed first */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('updated_at', 'DESC')
			->addOrderBy('id', 'DESC');

		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Question {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	/**
	 * Which of $ids are preguntas: the cards a tablero gains must point at
	 * one (SavedContent).
	 *
	 * @param list<int> $ids
	 * @return list<int>
	 */
	public function existing(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $qb->executeQuery();
		$found = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();

		return $found;
	}
}
