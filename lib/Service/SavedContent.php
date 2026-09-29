<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\Db\Dashboard;
use OCA\Estadistica\Db\DashboardMapper;
use OCA\Estadistica\Db\Question;
use OCA\Estadistica\Db\QuestionMapper;
use OCA\Estadistica\Exception\ForbiddenException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Settings\AdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Settings\IManager;

/**
 * Preguntas and tableros: what staff save for everyone to read (acceptance
 * a8, D6). Every read is everyone's. A change is the creator's, or belongs to
 * whoever administers Estadística.
 *
 * - **Who administers Estadística is core's answer** (owner, Slice 7;
 *   docs/adr/0003 amendment). It is a Nextcloud admin, or a member of a group
 *   the section is delegated to. The app names no group: it asks core the
 *   same two questions core's SecurityMiddleware asks before an admin write.
 *   Core builds every app's admin-settings class to answer the second one, so
 *   it is asked at most once per request, and only about content that is not
 *   the person's own.
 * - **A pregunta keeps its consulta as sent** (docs/adr/0006 amendment).
 *   QuerySpec::from() must accept it when it is saved. The JSON it came in is
 *   what is stored and served: never a normalized copy, which would rewrite
 *   the frozen contract. Every card asks it again through POST consultas,
 *   which judges it again. So a pregunta that a later dictionary no longer
 *   understands answers its own 422, in its own card.
 * - **A tablero's cards point at preguntas.** A card it gains must point at
 *   one that exists. A card it already has is kept when its pregunta is
 *   deleted, and says so where it stands (owner, Slice 7), so re-saving a
 *   tablero never fails on a card its editor did not add.
 */
class SavedContent {
	/** How a pregunta is drawn; query.js CHART_TYPES mirrors it. */
	public const CHART_TYPES = ['barra', 'linea', 'tabla'];

	/** A card spans one, two or three of the tablero's three columns. */
	public const WIDTHS = [1, 2, 3];

	/** Each card is one consulta, and a person has 120 a minute. */
	public const MAX_CARDS = 24;

	/** The columns' length (Version000007Date20260928000000). */
	public const MAX_TITLE = 200;

	private const NOT_YOURS_QUESTION = 'Solo quien creó esta pregunta, o quien administra Estadística, puede cambiarla o eliminarla.';

	private const NOT_YOURS_DASHBOARD = 'Solo quien creó este tablero, o quien administra Estadística, puede cambiarlo o eliminarlo.';

	/** Whether the person moderates, once asked; core's answer does not change within a request. */
	private ?bool $moderates = null;

	public function __construct(
		private QuestionMapper $questions,
		private DashboardMapper $dashboards,
		private IUserSession $userSession,
		private IUserManager $users,
		private IGroupManager $groups,
		private IManager $settings,
		private ITimeFactory $time,
		private YearWindow $window,
	) {
	}

	/** @return list<array<string, mixed>> every pregunta, the most recently changed first */
	public function listQuestions(): array {
		return array_map(fn (Question $question): array => $this->served($question), $this->questions->findAll());
	}

	/**
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	public function getQuestion(int $id): array {
		return $this->served($this->questions->find($id));
	}

	/**
	 * @param array<mixed> $spec
	 * @return array<string, mixed>
	 * @throws RefusedException
	 */
	public function createQuestion(string $title, array $spec, string $chart): array {
		$question = new Question();
		$question->setTitle(self::title($title));
		$question->setSpec($this->spec($spec));
		$question->setChart(self::chart($chart));
		$question->setOwner($this->owner());
		$question->setUpdatedAt($this->time->getTime());

		return $this->served($this->questions->insert($question));
	}

	/**
	 * An absent value (null) is left as it is.
	 *
	 * @param ?array<mixed> $spec
	 * @return array<string, mixed>
	 * @throws DoesNotExistException|ForbiddenException|RefusedException
	 */
	public function updateQuestion(int $id, ?string $title, ?array $spec, ?string $chart): array {
		$question = $this->questions->find($id);
		$this->mustBeAbleToChange($question->getOwner(), self::NOT_YOURS_QUESTION);
		if ($title !== null) {
			$question->setTitle(self::title($title));
		}
		if ($spec !== null) {
			$question->setSpec($this->spec($spec));
		}
		if ($chart !== null) {
			$question->setChart(self::chart($chart));
		}
		$question->setUpdatedAt($this->time->getTime());

		return $this->served($this->questions->update($question));
	}

	/**
	 * The tableros that show it keep their card, which says the pregunta is
	 * gone (owner, Slice 7): nobody else's tablero is written.
	 *
	 * @throws DoesNotExistException|ForbiddenException
	 */
	public function deleteQuestion(int $id): void {
		$question = $this->questions->find($id);
		$this->mustBeAbleToChange($question->getOwner(), self::NOT_YOURS_QUESTION);
		$this->questions->delete($question);
	}

	/** @return list<array<string, mixed>> every tablero, the most recently changed first */
	public function listDashboards(): array {
		return array_map(fn (Dashboard $dashboard): array => $this->served($dashboard), $this->dashboards->findAll());
	}

	/**
	 * @return array<string, mixed>
	 * @throws DoesNotExistException
	 */
	public function getDashboard(int $id): array {
		return $this->served($this->dashboards->find($id));
	}

	/**
	 * @param array<mixed> $cards
	 * @return array<string, mixed>
	 * @throws RefusedException
	 */
	public function createDashboard(string $title, array $cards): array {
		$dashboard = new Dashboard();
		$dashboard->setTitle(self::title($title));
		$dashboard->setLayout($this->cards($cards, []));
		$dashboard->setOwner($this->owner());
		$dashboard->setUpdatedAt($this->time->getTime());

		return $this->served($this->dashboards->insert($dashboard));
	}

	/**
	 * An absent value (null) is left as it is; $cards replaces the whole list.
	 *
	 * @param ?array<mixed> $cards
	 * @return array<string, mixed>
	 * @throws DoesNotExistException|ForbiddenException|RefusedException
	 */
	public function updateDashboard(int $id, ?string $title, ?array $cards): array {
		$dashboard = $this->dashboards->find($id);
		$this->mustBeAbleToChange($dashboard->getOwner(), self::NOT_YOURS_DASHBOARD);
		if ($title !== null) {
			$dashboard->setTitle(self::title($title));
		}
		if ($cards !== null) {
			$dashboard->setLayout($this->cards($cards, $dashboard->questionIds()));
		}
		$dashboard->setUpdatedAt($this->time->getTime());

		return $this->served($this->dashboards->update($dashboard));
	}

	/** @throws DoesNotExistException|ForbiddenException */
	public function deleteDashboard(int $id): void {
		$dashboard = $this->dashboards->find($id);
		$this->mustBeAbleToChange($dashboard->getOwner(), self::NOT_YOURS_DASHBOARD);
		$this->dashboards->delete($dashboard);
	}

	/**
	 * Whether the person asking administers Estadística: a Nextcloud admin, or
	 * a member of a group the section is delegated to. Both are core's answers
	 * (docs/adr/0003 amendment).
	 */
	public function moderates(): bool {
		if ($this->moderates === null) {
			$user = $this->userSession->getUser();
			$this->moderates = $user !== null && (
				$this->groups->isAdmin($user->getUID())
				|| array_filter(
					$this->settings->getAllAllowedAdminSettings($user),
					static fn (object $setting): bool => $setting instanceof AdminSettings,
				) !== []
			);
		}

		return $this->moderates;
	}

	/** @return array<string, mixed> */
	private function served(Question|Dashboard $saved): array {
		$owner = $saved->getOwner();

		return $saved->toClient($this->users->getDisplayName($owner) ?? $owner, $this->mayChange($owner));
	}

	private function mayChange(string $owner): bool {
		$uid = $this->userSession->getUser()?->getUID();

		return $uid !== null && ($owner === $uid || $this->moderates());
	}

	/** @throws ForbiddenException */
	private function mustBeAbleToChange(string $owner, string $refusal): void {
		if (!$this->mayChange($owner)) {
			throw new ForbiddenException($refusal);
		}
	}

	/** @throws ForbiddenException */
	private function owner(): string {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			throw new ForbiddenException('Hay que iniciar sesión para guardar preguntas y tableros.');
		}

		return $uid;
	}

	/** @throws RefusedException */
	private static function title(string $title): string {
		$title = trim($title);
		if ($title === '') {
			throw new RefusedException('Póngale un título: es lo que el resto del personal verá en la lista.');
		}
		if (mb_strlen($title) > self::MAX_TITLE) {
			throw new RefusedException(sprintf('El título tiene hasta %d caracteres.', self::MAX_TITLE));
		}

		return $title;
	}

	/** @throws RefusedException */
	private static function chart(string $chart): string {
		if (!in_array($chart, self::CHART_TYPES, true)) {
			throw new RefusedException(sprintf('El tipo de gráfico «%s» no existe: es barra, linea o tabla.', mb_substr($chart, 0, 40)));
		}

		return $chart;
	}

	/**
	 * The consulta as it came, once QuerySpec understands it.
	 *
	 * @param array<mixed> $spec
	 * @throws RefusedException
	 */
	private function spec(array $spec): string {
		QuerySpec::from($spec, $this->window->currentYear());

		return json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * The cards as stored: each {pregunta, ancho}, in order. A card whose
	 * pregunta the tablero already showed is kept whether or not it exists.
	 *
	 * @param array<mixed> $cards
	 * @param list<int> $kept the preguntas the stored tablero already points at
	 * @throws RefusedException
	 */
	private function cards(array $cards, array $kept): string {
		if (!array_is_list($cards)) {
			throw new RefusedException('tarjetas es una lista, como [{"pregunta": 12, "ancho": 2}].');
		}
		if (count($cards) > self::MAX_CARDS) {
			throw new RefusedException(sprintf('Un tablero tiene hasta %d tarjetas: cada una es una consulta.', self::MAX_CARDS));
		}

		$clean = [];
		foreach ($cards as $card) {
			if (!is_array($card)) {
				throw new RefusedException('Cada tarjeta es un objeto, como {"pregunta": 12, "ancho": 2}.');
			}
			foreach (array_keys($card) as $key) {
				if ($key !== 'pregunta' && $key !== 'ancho') {
					throw new RefusedException(sprintf('Una tarjeta admite pregunta y ancho, no «%s».', mb_substr((string)$key, 0, 40)));
				}
			}
			$question = $card['pregunta'] ?? null;
			if (!is_int($question) || $question < 1) {
				throw new RefusedException('Cada tarjeta dice su pregunta con el número que la identifica, como {"pregunta": 12}.');
			}
			$width = $card['ancho'] ?? 1;
			if (!in_array($width, self::WIDTHS, true)) {
				throw new RefusedException('El ancho de una tarjeta es 1, 2 o 3 columnas.');
			}
			$clean[] = ['pregunta' => $question, 'ancho' => $width];
		}

		$added = array_values(array_diff(array_unique(array_column($clean, 'pregunta')), $kept));
		$missing = array_values(array_diff($added, $this->questions->existing($added)));
		if ($missing !== []) {
			throw new RefusedException(sprintf('La pregunta %d no existe: puede que la hayan eliminado.', $missing[0]));
		}

		return json_encode($clean, JSON_THROW_ON_ERROR);
	}
}
