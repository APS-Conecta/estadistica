<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Exception\ForbiddenException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Settings\AdminSettings;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

/**
 * Preguntas and tableros on a real instance (acceptance a8). What one person
 * saves, a second non-admin reads. Its creator changes it, and so does
 * whoever administers Estadística: the instance's admin, and a member of a
 * group the section is delegated to (owner, Slice 7). Everyone else is
 * refused, and nothing changes.
 *
 * The three people and the delegated group are made for each test and
 * removed after it, with every row they saved.
 */
final class SavedContentTest extends IntegrationTestCase {
	/** A consulta QuerySpec understands. It is never run here: saving judges it, and cards run it. */
	private const SPEC = [
		'fuente' => 'rem-a',
		'periodo' => ['desde' => '2025-01', 'hasta' => '2025-03'],
		'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col01']],
		'agrupar' => ['mes'],
		'comparar' => ['nacional' => true],
	];

	private string $run;

	private string $author;

	private string $reader;

	private string $delegate;

	private string $group;

	protected function setUp(): void {
		parent::setUp();
		$this->run = bin2hex(random_bytes(3));
		$this->author = "autora-$this->run";
		$this->reader = "lector-$this->run";
		$this->delegate = "delegada-$this->run";
		$this->group = "estadistica-$this->run";

		$users = Server::get(IUserManager::class);
		foreach ([$this->author, $this->reader, $this->delegate] as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(12)) . 'Aa1!');
		}
		Server::get(IGroupManager::class)->createGroup($this->group)->addUser($users->get($this->delegate));
		$this->occ('admin-delegation:add', AdminSettings::class, $this->group);
	}

	protected function tearDown(): void {
		foreach (['estadistica_question', 'estadistica_dashboard'] as $table) {
			$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE owner IN (?, ?, ?, 'admin') AND title LIKE ?",
				[$this->author, $this->reader, $this->delegate, "%$this->run%"]);
		}
		$this->occ('admin-delegation:remove', AdminSettings::class, $this->group);
		Server::get(IGroupManager::class)->get($this->group)?->delete();
		foreach ([$this->author, $this->reader, $this->delegate] as $uid) {
			Server::get(IUserManager::class)->get($uid)?->delete();
		}
		parent::tearDown();
	}

	/** a8: a second, non-admin user lists and reads what the first saved. */
	public function testWhatOnePersonSavesASecondOneReads(): void {
		$pregunta = $this->savedFor($this->author)->createQuestion("Controles $this->run", self::SPEC, 'linea');
		$tablero = $this->savedFor($this->author)->createDashboard("Metas $this->run", [['pregunta' => $pregunta['id'], 'ancho' => 2]]);

		$reader = $this->savedFor($this->reader);
		$listed = array_column($reader->listQuestions(), null, 'id')[$pregunta['id']] ?? null;
		self::assertNotNull($listed, 'the second user does not see the pregunta');
		self::assertSame(['titulo' => "Controles $this->run", 'consulta' => self::SPEC, 'tipo' => 'linea', 'autor' => $this->author, 'editable' => false],
			array_intersect_key($listed, array_flip(['titulo', 'consulta', 'tipo', 'autor', 'editable'])));
		self::assertSame($listed, $reader->getQuestion($pregunta['id']));

		$board = array_column($reader->listDashboards(), null, 'id')[$tablero['id']] ?? null;
		self::assertNotNull($board, 'the second user does not see the tablero');
		self::assertSame([[$pregunta['id'], 2], false], [[$board['tarjetas'][0]['pregunta'], $board['tarjetas'][0]['ancho']], $board['editable']]);
		self::assertSame($board, $reader->getDashboard($tablero['id']));

		self::assertTrue($this->savedFor($this->author)->getQuestion($pregunta['id'])['editable'], 'the creator may change it');
	}

	/** a8: the second user's edit and delete are refused, and nothing changes. */
	public function testSomeoneElsesContentIsReadNeverChanged(): void {
		$pregunta = $this->savedFor($this->author)->createQuestion("Controles $this->run", self::SPEC, 'barra');
		$tablero = $this->savedFor($this->author)->createDashboard("Metas $this->run", [['pregunta' => $pregunta['id']]]);
		$reader = $this->savedFor($this->reader);

		foreach ([
			fn () => $reader->updateQuestion($pregunta['id'], 'Otro', null, null),
			fn () => $reader->deleteQuestion($pregunta['id']),
			fn () => $reader->updateDashboard($tablero['id'], 'Otro', null),
			fn () => $reader->deleteDashboard($tablero['id']),
		] as $i => $attempt) {
			try {
				$attempt();
				self::fail("attempt $i by a user who neither created it nor administers Estadística went through");
			} catch (ForbiddenException $e) {
				self::assertStringStartsWith('Solo quien creó', $e->getMessage());
			}
		}

		$author = $this->savedFor($this->author);
		self::assertSame([$pregunta, $tablero], [$author->getQuestion($pregunta['id']), $author->getDashboard($tablero['id'])]);
	}

	/** a8: the creator's edit and delete go through. */
	public function testTheCreatorChangesAndDeletes(): void {
		$author = $this->savedFor($this->author);
		$pregunta = $author->createQuestion("Controles $this->run", self::SPEC, 'barra');

		$edited = $author->updateQuestion($pregunta['id'], "  Controles de salud $this->run ", null, 'tabla');
		self::assertSame(["Controles de salud $this->run", 'tabla', self::SPEC], [$edited['titulo'], $edited['tipo'], $edited['consulta']]);

		$author->deleteQuestion($pregunta['id']);
		$this->expectException(DoesNotExistException::class);
		$author->getQuestion($pregunta['id']);
	}

	/** a8 «or an admin», as the owner read it: the instance's admin, and a delegate of the section. */
	public function testAnAdminAndADelegateOfTheSectionChangeAnyonesContent(): void {
		$pregunta = $this->savedFor($this->author)->createQuestion("Controles $this->run", self::SPEC, 'barra');
		$tablero = $this->savedFor($this->author)->createDashboard("Metas $this->run", []);

		$delegate = $this->savedFor($this->delegate);
		self::assertTrue($delegate->moderates(), 'a member of a group the section is delegated to administers Estadística');
		self::assertTrue($delegate->getQuestion($pregunta['id'])['editable']);
		self::assertSame('linea', $delegate->updateQuestion($pregunta['id'], null, null, 'linea')['tipo']);

		$admin = $this->savedFor('admin');
		self::assertTrue($admin->moderates());
		$admin->deleteDashboard($tablero['id']);
		$admin->deleteQuestion($pregunta['id']);

		self::assertFalse($this->savedFor($this->reader)->moderates(), 'a user in no delegated group does not');
		self::assertSame([], array_filter(
			$this->savedFor($this->reader)->listQuestions(),
			fn (array $question): bool => $question['id'] === $pregunta['id'],
		));
	}

	/**
	 * Lesson #74 (territorio ca9ebb9): every column is NOT NULL without a
	 * default, so the first save must write each one.
	 */
	public function testAFirstSaveWritesEveryColumn(): void {
		$author = $this->savedFor($this->author);
		$pregunta = $author->createQuestion("Controles $this->run", self::SPEC, 'barra');
		$tablero = $author->createDashboard("Metas $this->run", [['pregunta' => $pregunta['id'], 'ancho' => 3]]);

		foreach (['estadistica_question' => $pregunta['id'], 'estadistica_dashboard' => $tablero['id']] as $table => $id) {
			$result = $this->db()->executeQuery("SELECT * FROM *PREFIX*$table WHERE id = ?", [$id]);
			$row = $result->fetch();
			$result->closeCursor();
			self::assertIsArray($row, "$table has no row $id");
			self::assertSame([], array_keys(array_filter($row, static fn (mixed $value): bool => $value === null || $value === '')),
				"$table's first row left a column empty");
			self::assertSame($this->author, $row['owner']);
			self::assertGreaterThan(0, (int)$row['updated_at']);
		}
	}

	/** docs/adr/0006 amendment: the consulta is kept as it came, not normalized, and only if QuerySpec understands it. */
	public function testTheConsultaIsKeptAsSentOnceUnderstood(): void {
		$author = $this->savedFor($this->author);
		// No metrica: QuerySpec reads suma, and the stored consulta still has none.
		$pregunta = $author->createQuestion("Controles $this->run", self::SPEC, 'barra');
		$stored = $this->db()->executeQuery('SELECT spec FROM *PREFIX*estadistica_question WHERE id = ?', [$pregunta['id']])->fetchOne();
		self::assertSame(json_encode(self::SPEC, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $stored);

		$before = count($author->listQuestions());
		foreach ([
			[['sql' => 'DROP TABLE x'] + self::SPEC, 'barra', 'no admite'],
			[['metrica' => 'max'] + self::SPEC, 'barra', 'no existe'],
			[self::SPEC, 'torta', 'no existe'],
		] as [$spec, $chart, $sentence]) {
			try {
				$author->createQuestion("Mal $this->run", $spec, $chart);
				self::fail('a pregunta QuerySpec or the chart types refuse was stored');
			} catch (RefusedException $e) {
				self::assertStringContainsString($sentence, $e->getMessage());
			}
		}
		self::assertCount($before, $author->listQuestions(), 'a refused pregunta left a row');

		try {
			$author->createQuestion('   ', self::SPEC, 'barra');
			self::fail('a pregunta without a title was stored');
		} catch (RefusedException $e) {
			self::assertStringContainsString('Póngale un título', $e->getMessage());
		}
	}

	/**
	 * A card a tablero gains must point at a pregunta that exists. A card it
	 * keeps may point at one since deleted: the delete writes nobody's
	 * tablero, and re-saving the tablero keeps that card (owner, Slice 7).
	 */
	public function testACardAddedMustExistAndACardKeptMayBeGone(): void {
		$author = $this->savedFor($this->author);
		$gone = $author->createQuestion("Se irá $this->run", self::SPEC, 'barra');
		$stays = $author->createQuestion("Queda $this->run", self::SPEC, 'tabla');

		try {
			$author->createDashboard("Metas $this->run", [['pregunta' => PHP_INT_MAX]]);
			self::fail('a tablero with a card for no pregunta was stored');
		} catch (RefusedException $e) {
			self::assertStringContainsString('no existe', $e->getMessage());
		}
		foreach ([[['pregunta' => $gone['id'], 'ancho' => 4]], [['pregunta' => $gone['id'], 'alto' => 1]], ['x' => ['pregunta' => $gone['id']]], [['pregunta' => (string)$gone['id']]]] as $cards) {
			try {
				$author->createDashboard("Metas $this->run", $cards);
				self::fail('a malformed card was stored: ' . json_encode($cards));
			} catch (RefusedException) {
			}
		}

		$tablero = $author->createDashboard("Metas $this->run", [['pregunta' => $gone['id'], 'ancho' => 2]]);
		$this->savedFor('admin')->deleteQuestion($gone['id']);
		self::assertSame($tablero, $author->getDashboard($tablero['id']), 'deleting a pregunta wrote a tablero');

		$reordered = $author->updateDashboard($tablero['id'], null, [['pregunta' => $stays['id']], ['pregunta' => $gone['id'], 'ancho' => 2]]);
		self::assertSame([[$stays['id'], 1], [$gone['id'], 2]], array_map(static fn (array $card): array => [$card['pregunta'], $card['ancho']], $reordered['tarjetas']));

		$this->expectException(RefusedException::class);
		$author->updateDashboard($tablero['id'], null, [['pregunta' => $gone['id'] + 1_000_000]]);
	}
}
