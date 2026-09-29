<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A tablero: a title and its cards, for everyone to read (acceptance a8).
 * layout is the cards as JSON, [{"pregunta": id, "ancho": 1–3}] in display
 * order; each card points at a pregunta and runs it on its own.
 *
 * Lesson #74: no property starts with a value, so every setter call records
 * a change, and SavedContent's create path sets them all.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getLayout()
 * @method void setLayout(string $layout)
 * @method string getOwner()
 * @method void setOwner(string $owner)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Dashboard extends Entity {
	protected $title = null;
	protected $layout = null;
	protected $owner = null;
	protected $updatedAt = null;

	public function __construct() {
		$this->addType('updatedAt', Types::BIGINT);
	}

	/** @return list<int> the preguntas the cards point at, in order */
	public function questionIds(): array {
		return array_column($this->cards(), 'pregunta');
	}

	/**
	 * The tablero as GET tableros serves it; autor, autor_nombre and editable
	 * as a pregunta's.
	 *
	 * @return array{id: int, titulo: string, tarjetas: list<array{pregunta: int, ancho: int}>, autor: string, autor_nombre: string, actualizado: int, editable: bool}
	 */
	public function toClient(string $ownerName, bool $editable): array {
		return [
			'id' => $this->getId(),
			'titulo' => $this->getTitle(),
			'tarjetas' => $this->cards(),
			'autor' => $this->getOwner(),
			'autor_nombre' => $ownerName,
			'actualizado' => $this->getUpdatedAt(),
			'editable' => $editable,
		];
	}

	/** @return list<array{pregunta: int, ancho: int}> */
	private function cards(): array {
		return json_decode($this->getLayout(), true, 512, JSON_THROW_ON_ERROR);
	}
}
