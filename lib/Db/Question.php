<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A pregunta: one consulta saved under a title, for everyone to read and run
 * (acceptance a8). spec is the consulta as its author sent it, as JSON
 * (docs/adr/0006 amendment); chart is how it is drawn.
 *
 * Lesson #74: no property starts with a value, so every setter call records
 * a change, and SavedContent's create path sets them all.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getSpec()
 * @method void setSpec(string $spec)
 * @method string getChart()
 * @method void setChart(string $chart)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Question extends Entity {
	protected $title = null;
	protected $spec = null;
	protected $chart = null;
	protected $owner = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('updatedAt', Types::BIGINT);
	}

	/**
	 * The pregunta as GET preguntas serves it. autor is the creator's user id
	 * and autor_nombre their display name; editable says whether the person
	 * asking may change it (SavedContent).
	 *
	 * @return array{id: int, titulo: string, consulta: array<string, mixed>, tipo: string, autor: string, autor_nombre: string, actualizada: int, editable: bool}
	 */
	public function toClient(string $ownerName, bool $editable): array {
		return [
			'id' => $this->getId(),
			'titulo' => $this->getTitle(),
			'consulta' => json_decode($this->getSpec(), true, 512, JSON_THROW_ON_ERROR),
			'tipo' => $this->getChart(),
			'autor' => $this->getOwner(),
			'autor_nombre' => $ownerName,
			'actualizada' => $this->getUpdatedAt(),
			'editable' => $editable,
		];
	}
}
