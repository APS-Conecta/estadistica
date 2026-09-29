<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Controller\AdminController;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Piv\Population;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Piv\PivFixture;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\Server;

/**
 * FONASA's PIV against the instance's real PostgreSQL (acceptance a11): through
 * the drop door as the FilePicker drives it, and through the admin's typed
 * figures, read back the way Slice 9's metas will read them.
 *
 * The centers 9000xx are these tests' own: the register has no such code.
 * Their cortes are 2096–2098, which no FONASA file carries, and 31-12-2009
 * where a figure is typed (TYPED). So the tests run beside a real load (the
 * a21 manual run's) without touching it, and they delete only what they
 * wrote.
 */
class PivImportTest extends IntegrationTestCase {
	private const UID = 'admin';

	private const DROPPED = 'estadistica-piv-test.csv';

	private const LOCAL = 900001;

	/**
	 * A typed figure takes a corte year from 2009 to this one
	 * (PivImporter::enter), so the tests that type load FONASA's rows at
	 * 31-12-2009, the oldest year a corte may carry. FONASA's report offers
	 * recent cortes only, so no real file has it. On an instance that holds one
	 * anyway, those tests skip rather than replace it (EnrichmentTest's way).
	 */
	private const TYPED = 2009;

	protected function tearDown(): void {
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_piv WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_establishment WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		try {
			Server::get(IRootFolder::class)->getUserFolder(self::UID)->get(self::DROPPED)->delete();
		} catch (NotFoundException) {
			// Only the door's tests put a file in Files.
		}
		ZipFixture::cleanup();
		parent::tearDown();
	}

	/** @param list<string> $rows PivFixture rows */
	private function drop(array $rows, string $header = PivFixture::HEADER): void {
		try {
			Server::get(IRootFolder::class)->getUserFolder(self::UID)->get(self::DROPPED)->delete();
		} catch (NotFoundException) {
			// The first drop of the test.
		}
		$this->putInFiles(self::UID, self::DROPPED, ZipFixture::file(PivFixture::csv($rows, $header)));
	}

	/** The admin section's controller, acting for UID through the same door. */
	private function admin(): AdminController {
		return new AdminController(
			Server::get(IRequest::class),
			Server::get(YearWindow::class),
			$this->dropsFor(self::UID),
			Server::get(IngestStateMapper::class),
			Server::get(DeisRepository::class),
			Server::get(Catalog::class),
			Server::get(Register::class),
			Server::get(PivImporter::class),
			Server::get(Goals::class),
		);
	}

	private function skipOverARealCorte(): void {
		$real = (int)$this->db()->executeQuery(
			'SELECT count(*) FROM *PREFIX*estadistica_piv WHERE corte = ? AND estab NOT BETWEEN ? AND ?',
			[self::TYPED . '-12-31', 900001, 900099],
		)->fetchOne();
		if ($real > 0) {
			self::markTestSkipped('this instance holds a FONASA corte of 31-12-' . self::TYPED . '; run the integration suite on a throwaway instance');
		}
	}

	/** @return list<array{estab: int, edad: int, sexo: string, manual: int, inscritos: int}> */
	private function stored(string $corte): array {
		return array_map(static fn (array $row): array => [
			'estab' => (int)$row['estab'],
			'edad' => (int)$row['edad'],
			'sexo' => (string)$row['sexo'],
			'manual' => (int)$row['manual'],
			'inscritos' => (int)$row['inscritos'],
		], $this->db()->executeQuery(
			'SELECT estab, edad, sexo, manual, inscritos FROM *PREFIX*estadistica_piv WHERE corte = ? ORDER BY estab, edad, sexo, manual',
			[$corte],
		)->fetchAll());
	}

	/** @return list<string> FONASA rows for 31-12-$year: 900001 and 900002, a tramo split, an unaged row */
	private static function fonasa(int $year, int $women20s = 200): array {
		$periodo = "31-12-$year";

		return [
			PivFixture::row($periodo, self::LOCAL, 'A', '20 a 29 años', 'Mujeres', $women20s - 50),
			PivFixture::row($periodo, self::LOCAL, 'B', '20 a 29 años', 'Mujeres', 50),
			PivFixture::row($periodo, self::LOCAL, 'A', '20 a 29 años', 'Hombres', 180),
			PivFixture::row($periodo, 900002, 'D', '20 a 29 años', 'Mujeres', 100),
			PivFixture::row($periodo, 900002, 'X', 'Sin información', 'Hombres', 4),
		];
	}

	/** a11: another header set is an HTTP 422 in Spanish, and not one row is written, over a corte already stored. */
	public function testAHeaderThatIsNotFonasasIsA422AndLeavesTheCorteAsItWas(): void {
		$this->drop(self::fonasa(2098));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		$before = $this->stored('2098-12-31');
		self::assertCount(3, $before);

		$this->drop(
			array_map(static fn (string $row): string => $row . ',11111111-1', self::fonasa(2098, 999)),
			PivFixture::HEADER . ',"RUT"',
		);
		$response = $this->admin()->drop('/' . self::DROPPED);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('Sobra: RUT.', $response->getData()['message']);
		self::assertSame($before, $this->stored('2098-12-31'), 'nothing of the refused file was written');
	}

	public function testFonasasExportIsStoredUnderItsCorteSummedOverTheIncomeTramo(): void {
		$this->drop(self::fonasa(2098));

		self::assertSame(
			['piv' => ['cortes' => ['2098-12-31'], 'establishments' => 2, 'inscritos' => 480, 'unknownAge' => 4]],
			$this->dropsFor(self::UID)->accept('/' . self::DROPPED),
		);
		self::assertSame([
			['estab' => self::LOCAL, 'edad' => 20, 'sexo' => 'H', 'manual' => 0, 'inscritos' => 180],
			['estab' => self::LOCAL, 'edad' => 20, 'sexo' => 'M', 'manual' => 0, 'inscritos' => 200],
			['estab' => 900002, 'edad' => 20, 'sexo' => 'M', 'manual' => 0, 'inscritos' => 100],
		], $this->stored('2098-12-31'));

		// Lesson #74: the first insert wrote every NOT NULL column, loaded_at included.
		self::assertGreaterThan(0, (int)$this->db()->executeQuery(
			'SELECT min(loaded_at) FROM *PREFIX*estadistica_piv WHERE corte = ?',
			['2098-12-31'],
		)->fetchOne());
	}

	public function testALaterFileReplacesItsCorteAndLeavesTheOthers(): void {
		$this->drop([...self::fonasa(2097), ...self::fonasa(2098)]);
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);

		$this->drop(self::fonasa(2098, 260));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);

		self::assertSame(260, $this->stored('2098-12-31')[1]['inscritos'], 'the corte is the new file, not both');
		self::assertCount(3, $this->stored('2098-12-31'));
		self::assertSame(200, $this->stored('2097-12-31')[1]['inscritos'], 'a corte the file does not carry stays');

		$cortes = array_values(array_filter(
			Server::get(PivImporter::class)->cortes(),
			static fn (array $corte): bool => $corte['corte'] >= '2096-01-01',
		));
		self::assertSame(['2098-12-31', '2097-12-31'], array_column($cortes, 'corte'), 'newest first');
		self::assertSame([3, 2, 540], [$cortes[0]['rows'], $cortes[0]['establishments'], $cortes[0]['inscritos']]);
	}

	/**
	 * a11: a figure typed for this install's establishment persists and wins,
	 * for that establishment alone, and outlives the next FONASA file.
	 */
	public function testATypedFigureWinsForTheEstablishmentAloneAndOutlivesTheNextFile(): void {
		$this->skipOverARealCorte();
		$this->provision(EstablishmentConfig::KEY_DEIS, (string)self::LOCAL);
		$this->drop(self::fonasa(self::TYPED));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		$importer = Server::get(PivImporter::class);
		$population = Server::get(Population::class);
		$corte = self::TYPED . '-12-31';
		$metaYear = self::TYPED + 1;

		$importer->enter(self::TYPED, [['edad' => 20, 'sexo' => 'M', 'inscritos' => 250]]);

		$local = $population->local($metaYear, self::LOCAL);
		self::assertSame([$corte, 1.0, true], [$local['corte'], $local['factor'], $local['manual']]);
		self::assertSame(['H' => 180, 'M' => 250], $local['bands'][20], 'the typed cell over FONASA\'s, the other cell FONASA\'s');
		self::assertSame(['H' => 180, 'M' => 300], $population->national($metaYear)['bands'][20], 'the country stays FONASA\'s: 200 + 100 women');

		$this->drop(self::fonasa(self::TYPED, 210));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		self::assertSame(250, $population->local($metaYear, self::LOCAL)['bands'][20]['M'], 'a new file leaves the typed figure');

		$cell = array_values(array_filter(
			$importer->status()['local']['cells'],
			static fn (array $cell): bool => $cell['corte'] === $corte && $cell['edad'] === 20 && $cell['sexo'] === 'M',
		));
		self::assertSame([['corte' => $corte, 'edad' => 20, 'sexo' => 'M', 'fonasa' => 210, 'manual' => 250]], $cell);

		$importer->enter(self::TYPED, []);
		self::assertSame(210, $population->local($metaYear, self::LOCAL)['bands'][20]['M'], 'clearing the typed cells gives FONASA\'s back');
	}

	/** a11: a year with no corte of its own reads the latest earlier one × 1.03, and never a later one. */
	public function testAYearWithNoCorteReadsTheLatestEarlierOneGrownBy3Percent(): void {
		$this->drop(self::fonasa(2096));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		$population = Server::get(Population::class);

		$exact = $population->local(2097, self::LOCAL);
		self::assertSame(['2096-12-31', 1.0], [$exact['corte'], $exact['factor']]);

		$grown = $population->local(2099, self::LOCAL);
		self::assertSame(['2096-12-31', Population::GROWTH], [$grown['corte'], $grown['factor']]);
		self::assertEqualsWithDelta(206.0, Population::count($grown, 20, 29, 'M')['valor'], 1e-9, '200 × 1.03');

		self::assertNull($population->local(2096, self::LOCAL), 'the 2096 corte is later than the one 2096 needs');
	}

	/** A peer group is the register's filter over FONASA's counts, the local establishment included. */
	public function testAPeerGroupIsTheRegistersFilterOverFonasasCounts(): void {
		foreach ([[self::LOCAL, 'Tipo Uno'], [900002, 'Tipo Uno'], [900003, 'Tipo Dos']] as [$estab, $tipo]) {
			// Straight into the table: Register::replace() would replace a real register.
			$this->db()->executeStatement('INSERT INTO *PREFIX*estadistica_establishment (estab, tipo) VALUES (?, ?)', [$estab, $tipo]);
		}
		$this->drop([...self::fonasa(2098), PivFixture::row('31-12-2098', 900003, 'A', '20 a 29 años', 'Mujeres', 1000)]);
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		$population = Server::get(Population::class);

		self::assertSame(300, $population->peers(2099, ['tipo' => 'Tipo Uno'])['bands'][20]['M']);
		self::assertSame(1000, $population->peers(2099, ['tipo' => 'Tipo Dos'])['bands'][20]['M']);
		self::assertSame(1300, $population->national(2099)['bands'][20]['M']);
		self::assertSame([], $population->peers(2099, ['tipo' => 'Tipo Tres'])['bands'], 'a group nobody is in counts no one');
	}

	public function testATypedFigureIsRefusedInSpanishAndWritesNothing(): void {
		$importer = Server::get(PivImporter::class);
		$this->provision(EstablishmentConfig::KEY_DEIS, '');
		try {
			$importer->enter(self::TYPED, [['edad' => 20, 'sexo' => 'M', 'inscritos' => 1]]);
			self::fail('a figure was typed for no establishment');
		} catch (RefusedException $e) {
			self::assertStringContainsString('no se ha configurado el establecimiento', $e->getMessage());
		}

		$this->provision(EstablishmentConfig::KEY_DEIS, (string)self::LOCAL);
		foreach ([
			[2008, [], 'El año del corte debe estar entre 2009'],
			[2098, [], 'El año del corte debe estar entre 2009 y'],
			[self::TYPED, [['edad' => 25, 'sexo' => 'M', 'inscritos' => 1]], 'Cada cifra va en un tramo de edad de FONASA'],
			[self::TYPED, [['edad' => 20, 'sexo' => 'S', 'inscritos' => 1]], 'de Mujeres o de Hombres'],
			[self::TYPED, [['edad' => 20, 'sexo' => 'M', 'inscritos' => -1]], 'Los inscritos de mujeres de 20 a 29 años son un número entero'],
			[self::TYPED, [['edad' => 20, 'sexo' => 'M', 'inscritos' => '7']], 'son un número entero'],
			[self::TYPED, [['edad' => 20, 'sexo' => 'H', 'inscritos' => 1], ['edad' => 20, 'sexo' => 'H', 'inscritos' => 2]], 'La cifra de hombres de 20 a 29 años viene dos veces'],
		] as [$year, $cells, $sentence]) {
			try {
				$importer->enter($year, $cells);
				self::fail("accepted: $sentence");
			} catch (RefusedException $e) {
				self::assertStringContainsString($sentence, $e->getMessage());
			}
		}
		self::assertSame([], $this->stored(self::TYPED . '-12-31'));
	}

	/** a14: the PIV is a fuente, from FONASA, dated by its newest corte, with its cortes as its catálogo. */
	public function testThePivIsAFuenteFromFonasa(): void {
		$this->drop(self::fonasa(2098));
		$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
		$sources = Server::get(Sources::class);

		$listed = array_values(array_filter($sources->all(), static fn (array $fuente): bool => $fuente['id'] === 'piv'));
		self::assertSame(['FONASA', '2098-12-31', 'ok'], [$listed[0]['origen'], $listed[0]['fecha_corte'], $listed[0]['estado']]);
		self::assertSame(
			['corte' => '2098-12-31', 'establecimientos' => 2, 'inscritos' => 480],
			$sources->one('piv', null)['catalogo']['cortes'][0],
		);
	}
}
