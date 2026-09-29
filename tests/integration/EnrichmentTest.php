<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Catalog\DictionaryParser;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\Server;

/**
 * What the REM rows join, against the instance's real PostgreSQL: the
 * establishments (acceptance a5), the labels (a6) and the fuentes that report
 * both (a14), loaded the way the job and the drop door load them.
 *
 * The years 2097–2099 are these tests' own (RemImportTest's reasoning). The
 * register is one whole table, so these tests skip themselves on an instance
 * that already holds one (the a21 manual run's) rather than replace it.
 */
class EnrichmentTest extends IntegrationTestCase {
	private const UID = 'admin';

	private const YEARS = [2097, 2098, 2099];

	private const DROPPED = 'estadistica-enrichment-test.xlsx';

	private const DICTIONARY = 'Diccionarios/DICCIONARIO CODIGOS SA_99_V1.0.xlsm';

	/** Whether the register is this test's to empty: false when the instance held one already. */
	private bool $ownsRegister = false;

	protected function setUp(): void {
		parent::setUp();
		if ((int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_establishment')->fetchOne() > 0) {
			self::markTestSkipped('this instance already holds a Base de Establecimientos; run the integration suite on a throwaway instance');
		}
		$this->ownsRegister = true;
	}

	protected function tearDown(): void {
		foreach (self::YEARS as $year) {
			foreach (['estadistica_rem', 'estadistica_ingest', 'estadistica_code', 'estadistica_dictionary'] as $table) {
				$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE anio = ?", [$year]);
			}
		}
		if ($this->ownsRegister) {
			$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_establishment');
			Server::get(IAppConfig::class)->deleteKey('estadistica', Register::KEY);
		}
		try {
			Server::get(IRootFolder::class)->getUserFolder(self::UID)->get(self::DROPPED)->delete();
		} catch (NotFoundException) {
			// Only the drop test puts a file in Files.
		}
		ZipFixture::cleanup();
		XlsxFixture::cleanup();
		parent::tearDown();
	}

	/** @param array<string, string> $members */
	private function import(int $year, array $members): IngestState {
		return Server::get(RemImporter::class)->import(new LocalRange(ZipFixture::zip($members)), $year, 'A', IngestState::ORIGIN_FILE);
	}

	/** The fixture dictionary's bytes, as a zip member. */
	private static function dictionary(): string {
		return (string)file_get_contents(XlsxFixture::dictionary());
	}

	/** @param list<array<string, string|int|null>> $establishments */
	private static function registerRows(array $establishments): array {
		return RegisterParser::parse(XlsxReader::open(XlsxFixture::register($establishments)))['rows'];
	}

	/** @return array<string, mixed> (año, serie)'s row of Catalog::status() */
	private static function dictionaryRow(int $anio): array {
		foreach (Server::get(Catalog::class)->status() as $row) {
			if ($row['anio'] === $anio && $row['serie'] === 'A') {
				return $row;
			}
		}
		self::fail("no dictionary row for $anio");
	}

	/** a5: every stored row resolves tipo, comuna, servicio, región and dependencia, and a peer group is a filter. */
	public function testEveryStoredRowResolvesItsEstablishmentFromTheRegister(): void {
		Server::get(Register::class)->replace(self::registerRows([
			XlsxFixture::establishment(900001),
			XlsxFixture::establishment(900002, ['tipo' => 'Posta de Salud Rural (PSR)', 'comuna_cut' => '99002', 'comuna' => 'Comuna Dos']),
			XlsxFixture::establishment(900003, ['dependencia' => 'Servicio de Salud', 'servicio_codigo' => '92', 'servicio' => 'Servicio de Salud Dos']),
			XlsxFixture::establishment(900004, ['region_codigo' => '98', 'region' => 'Región Dos']),
		]), IngestState::ORIGIN_DEIS, 2098);
		$this->import(2099, ['SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(4))]);

		$row = $this->db()->executeQuery(
			'SELECT count(*) AS n, count(e.estab) AS joined, count(e.tipo) AS tipo, count(e.comuna) AS comuna, '
			. 'count(e.servicio) AS servicio, count(e.region) AS region, count(e.dependencia) AS dependencia '
			. 'FROM *PREFIX*estadistica_rem r LEFT JOIN *PREFIX*estadistica_establishment e ON e.estab = r.estab '
			. 'WHERE r.anio = ? AND r.serie = ?',
			[2099, 'A'],
		)->fetch();
		self::assertSame(16, (int)$row['n'], '4 establishments × 2 months × 2 códigos');
		foreach (['joined', 'tipo', 'comuna', 'servicio', 'region', 'dependencia'] as $attribute) {
			self::assertSame(16, (int)$row[$attribute], "every stored row resolves its $attribute");
		}

		$peers = (int)$this->db()->executeQuery(
			'SELECT count(DISTINCT r.estab) FROM *PREFIX*estadistica_rem r JOIN *PREFIX*estadistica_establishment e ON e.estab = r.estab '
			. 'WHERE r.anio = ? AND r.serie = ? AND e.tipo = ?',
			[2099, 'A', 'Centro de Salud Familiar (CESFAM)'],
		)->fetchOne();
		self::assertSame(3, $peers, 'a peer group is a filter over the register');
	}

	/** The drop door: a Base de Establecimientos picked in Files replaces the whole register at once. */
	public function testADroppedBaseReplacesTheWholeRegisterAtOnce(): void {
		Server::get(Register::class)->replace(self::registerRows([XlsxFixture::establishment(900009)]), IngestState::ORIGIN_DEIS, 2098);
		$this->putInFiles(self::UID, self::DROPPED, XlsxFixture::register([XlsxFixture::establishment(900001), XlsxFixture::establishment(900002)]));

		self::assertSame(['establecimientos' => 2], $this->dropsFor(self::UID)->accept('/' . self::DROPPED));

		self::assertSame([900001, 900002], array_map('intval', $this->db()->executeQuery(
			'SELECT estab FROM *PREFIX*estadistica_establishment ORDER BY estab',
		)->fetchFirstColumn()));
		$status = Server::get(Register::class)->status();
		self::assertSame([IngestState::ORIGIN_FILE, null, 2], [$status['origin'], $status['year'], $status['rows']]);
	}

	public function testADroppedWorkbookThatIsNotTheBaseIsRefusedAndTheRegisterStays(): void {
		Server::get(Register::class)->replace(self::registerRows([XlsxFixture::establishment(900009)]), IngestState::ORIGIN_DEIS, 2098);
		$this->putInFiles(self::UID, self::DROPPED, XlsxFixture::dictionary());

		try {
			$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
			self::fail('a dictionary was taken for the register');
		} catch (RefusedException $e) {
			self::assertStringContainsString('no es la Base de Establecimientos del DEIS', $e->getMessage());
		}
		self::assertSame(1, Server::get(Register::class)->status()['rows']);
	}

	/** a6, through the database: the zip's own dictionary labels its year, and what it lacks is counted. */
	public function testTheDictionaryInTheZipLabelsItsYearAndCountsWhatItLacks(): void {
		$zip = ZipFixture::zip([
			'Datos/SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(2), ['02010420', '03500366', '09000001']),
			self::DICTIONARY => self::dictionary(),
		]);
		$state = Server::get(RemImporter::class)->import(new LocalRange($zip), 2099, 'A', IngestState::ORIGIN_FILE);
		self::assertNull($state->getLastError(), 'the figures load');

		$catalog = Server::get(Catalog::class);
		$labels = $catalog->labels(2099, 'A', ['02010420', '09000001']);
		self::assertSame(
			['hoja' => 'A03', 'seccion' => 'SECCIÓN A: PRIMERA SECCIÓN', 'prestacion' => 'Evaluación › Normal', 'columna' => 'POR EDAD › Menos de 1 año › Mujeres', 'nota' => null],
			Catalog::describe($labels['02010420'], 2099, 5),
		);
		self::assertSame(Catalog::NO_LABEL, $labels['09000001']['prestacion']);

		$row = self::dictionaryRow(2099);
		self::assertSame(['DICCIONARIO CODIGOS SA_99_V1.0.xlsm', 4, 1, 2099, null], [$row['file'], $row['codes'], $row['unmapped'], $row['labelsFrom'], $row['error']]);
		self::assertTrue(
			$catalog->judged(2099, 'A', DictionaryParser::member(ZipDirectory::read(new LocalRange($zip)), 'A')),
			'the daily check will not read these bytes again',
		);

		$tree = $catalog->tree(2099, 'A');
		self::assertSame([2099, 'DICCIONARIO CODIGOS SA_99_V1.0.xlsm'], [$tree['etiquetas_de'], $tree['diccionario']]);
		self::assertSame(['A03', 'REM-A03. HOJA DE PRUEBA'], [$tree['hojas'][0]['hoja'], $tree['hojas'][0]['titulo']]);
		self::assertSame(['col' => 'col01', 'etiqueta' => 'TOTAL › Ambos sexos'], $tree['hojas'][0]['secciones'][0]['columnas'][0]);
		self::assertSame(['codigo' => '02010420', 'etiqueta' => 'Evaluación › Normal'], $tree['hojas'][0]['secciones'][0]['prestaciones'][0]);
	}

	/** A year whose zip carries no dictionary this app reads borrows the nearest later year's labels, and says so. */
	public function testAYearWithoutADictionaryBorrowsTheNearestLaterYearsLabels(): void {
		$this->import(2099, ['Datos/SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(2)), self::DICTIONARY => self::dictionary()]);
		$this->import(2098, ['SerieA2098.csv' => ZipFixture::member(2098, ZipFixture::establishments(2))]);

		$catalog = Server::get(Catalog::class);
		self::assertSame('etiqueta de 2099', Catalog::describe($catalog->labels(2098, 'A', ['03500366'])['03500366'], 2098, 1)['nota']);
		$row = self::dictionaryRow(2098);
		self::assertSame([null, 0, 0, 2099], [$row['file'], $row['codes'], $row['unmapped'], $row['labelsFrom']]);
		self::assertSame(2099, $catalog->tree(2098, 'A')['etiquetas_de']);
	}

	/** #74: a dictionary that is not one never holds back the figures, and says why. */
	public function testADictionaryThatCannotBeReadKeepsTheFiguresAndSaysWhy(): void {
		$state = $this->import(2099, ['Datos/SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(2)), self::DICTIONARY => 'no es una planilla']);

		self::assertNull($state->getLastError());
		self::assertSame(8, $state->getRowCount());
		$row = self::dictionaryRow(2099);
		self::assertStringContainsString('no se pudo leer', (string)$row['error']);
		self::assertSame([0, 2], [$row['codes'], $row['unmapped']], 'both códigos are «sin descripción»');
	}

	public function testForgettingAYearDropsItsLabels(): void {
		$this->import(2098, ['Datos/SerieA2098.csv' => ZipFixture::member(2098, ZipFixture::establishments(2)), self::DICTIONARY => self::dictionary()]);
		self::assertSame(4, self::dictionaryRow(2098)['codes']);

		Server::get(RemImporter::class)->forget(2098);

		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_code WHERE anio = ?', [2098])->fetchOne());
		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_dictionary WHERE anio = ?', [2098])->fetchOne());
	}

	/** a14: the fuentes say what is loaded, up to when, and how whole. */
	public function testTheFuentesSayWhatIsLoadedAndHowWhole(): void {
		$this->import(2099, [
			'Datos/SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(3), ['02010420', '09000001']),
			self::DICTIONARY => self::dictionary(),
		]);
		Server::get(Register::class)->replace(self::registerRows([XlsxFixture::establishment(900001), XlsxFixture::establishment(900002)]), IngestState::ORIGIN_DEIS, 2098);
		$sources = Server::get(Sources::class);

		$fuentes = array_column($sources->all(), null, 'id');
		self::assertSame(['rem-a', 'rem-p', 'establecimientos'], array_keys($fuentes));
		self::assertSame(
			['anio' => 2099, 'fecha_corte' => '2099-02', 'filas' => 12, 'estado' => 'ok', 'sin_descripcion' => 1, 'etiquetas_de' => 2099],
			$fuentes['rem-a']['anios'][0],
		);
		self::assertSame(['datos públicos DEIS', '2099-02', 'ok'], [$fuentes['rem-a']['origen'], $fuentes['rem-a']['fecha_corte'], $fuentes['rem-a']['estado']]);
		self::assertSame(['datos públicos DEIS', '2098', 2, 'ok'], [$fuentes['establecimientos']['origen'], $fuentes['establecimientos']['fecha_corte'], $fuentes['establecimientos']['filas'], $fuentes['establecimientos']['estado']]);

		self::assertSame(
			[['valor' => 'Centro de Salud Familiar (CESFAM)', 'establecimientos' => 2]],
			$sources->one('establecimientos', null)['catalogo']['tipos'],
		);
		try {
			$sources->one('rem-a', 2008);
			self::fail('a year before DEIS publishes was answered');
		} catch (RefusedException $e) {
			self::assertStringContainsString('El año debe estar entre 2009 y', $e->getMessage());
		}
		$this->expectException(DoesNotExistException::class);
		$sources->one('nada', null);
	}
}
