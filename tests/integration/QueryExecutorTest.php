<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Goals\GoalEvaluator;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\SavedContent;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\Server;

/**
 * Consultas against the instance's real PostgreSQL (acceptance a7, a14, a16).
 * The figures are loaded the way the job loads them (RemImporter), and every
 * expected number is summed by hand from the rows below.
 *
 * 2099, Serie A (col04 / col05; an empty cell is not written):
 *
 *     estab   mes  código     col04  col05
 *     900001   1   02010420     10      5     local, CESFAM, Servicio Uno
 *     900001   1   03500366      1
 *     900001   1   03500400    100            A03, sección B
 *     900001   1   09000001   1000            in no dictionary
 *     900001   2   02010420     20
 *     900002   1   02010420     30     10     CESFAM, Servicio Dos
 *     900002   2   02010420     (col01 = 7 only: reported, not «informante»)
 *     900003   1   02010420      6            PSR, Servicio Uno
 *     900003   2   02010420            4
 *
 * 2098 has no dictionary, so it borrows 2099's: 900001 reports 2 in
 * December and 900002 reports 8 in November (02010420, col04).
 *
 * The years 2098–2099 are these tests' own (RemImportTest's reasoning). The
 * register is one whole table, so the tests skip themselves on an instance
 * that already holds one (EnrichmentTest's reasoning).
 */
class QueryExecutorTest extends IntegrationTestCase {
	private const YEARS = [2098, 2099];

	private const DICTIONARY = 'Diccionarios/DICCIONARIO CODIGOS SA_99_V1.0.xlsm';

	private const CESFAM = 'Centro de Salud Familiar (CESFAM)';

	private bool $ownsRegister = false;

	private QueryExecutor $executor;

	protected function setUp(): void {
		parent::setUp();
		if ((int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_establishment')->fetchOne() > 0) {
			self::markTestSkipped('this instance already holds a Base de Establecimientos; run the integration suite on a throwaway instance');
		}
		$this->ownsRegister = true;
		$this->executor = Server::get(QueryExecutor::class);

		Server::get(Register::class)->replace(RegisterParser::parse(XlsxReader::open(XlsxFixture::register([
			XlsxFixture::establishment(900001),
			XlsxFixture::establishment(900002, ['servicio_codigo' => '92', 'servicio' => 'Servicio de Salud Dos']),
			XlsxFixture::establishment(900003, ['tipo' => 'Posta de Salud Rural (PSR)']),
		])))['rows'], IngestState::ORIGIN_DEIS, 2098);

		$importer = Server::get(RemImporter::class);
		$member = ZipFixture::header()
			. ZipFixture::row(2099, 1, '900001', '02010420', [4 => '10', 5 => '5'])
			. ZipFixture::row(2099, 1, '900001', '03500366', [4 => '1'])
			. ZipFixture::row(2099, 1, '900001', '03500400', [4 => '100'])
			. ZipFixture::row(2099, 1, '900001', '09000001', [4 => '1000'])
			. ZipFixture::row(2099, 2, '900001', '02010420', [4 => '20'])
			. ZipFixture::row(2099, 1, '900002', '02010420', [4 => '30', 5 => '10'])
			. ZipFixture::row(2099, 2, '900002', '02010420', [1 => '7'])
			. ZipFixture::row(2099, 1, '900003', '02010420', [4 => '6'])
			. ZipFixture::row(2099, 2, '900003', '02010420', [5 => '4']);
		$state = $importer->import(new LocalRange(ZipFixture::zip([
			'Datos/SerieA2099.csv' => $member,
			self::DICTIONARY => (string)file_get_contents(XlsxFixture::dictionary()),
		])), 2099, 'A', IngestState::ORIGIN_FILE);
		self::assertSame(9, $state->getRowCount(), 'the 2099 fixture loads');

		$state = $importer->import(new LocalRange(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::header()
			. ZipFixture::row(2098, 11, '900002', '02010420', [4 => '8'])
			. ZipFixture::row(2098, 12, '900001', '02010420', [4 => '2'])])), 2098, 'A', IngestState::ORIGIN_FILE);
		self::assertSame(2, $state->getRowCount(), 'the 2098 fixture loads');

		// The local establishment is read from app config, written as occ writes it.
		$this->provision(EstablishmentConfig::KEY_DEIS, '900001');
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
		ZipFixture::cleanup();
		XlsxFixture::cleanup();
		parent::tearDown();
	}

	private function local(): Establishment {
		return Server::get(EstablishmentConfig::class)->get();
	}

	/** @param array<string, mixed> $changes */
	private static function spec(array $changes = []): QuerySpec {
		return QuerySpec::from($changes + [
			'fuente' => 'rem-a',
			'periodo' => ['desde' => '2099-01', 'hasta' => '2099-02'],
			'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col04', 'col05']],
			'agrupar' => ['mes'],
		], 2099);
	}

	/**
	 * @param list<array{grupo: list<int|string|null>, valor: float, informantes: int}> $points
	 * @return list<array{list<int|string|null>, float, int}>
	 */
	private static function flat(array $points): array {
		return array_map(static fn (array $point): array => [$point['grupo'], $point['valor'], $point['informantes']], $points);
	}

	/** a7: local, nacional and pares side by side, from one spec, the local one from config. */
	public function testLocalNationalAndPeerSeriesComeFromOneSpec(): void {
		$answer = $this->executor->answer(self::spec(['comparar' => ['nacional' => true, 'pares' => ['tipo' => true]]]), $this->local());

		self::assertSame([[['2099-01'], 15.0, 1], [['2099-02'], 20.0, 1]], self::flat($answer['series']['local']));
		self::assertSame([[['2099-01'], 61.0, 3], [['2099-02'], 24.0, 2]], self::flat($answer['series']['nacional']), '900002 reported nothing in col04–05 in February');
		self::assertSame([[['2099-01'], 55.0, 2], [['2099-02'], 20.0, 1]], self::flat($answer['series']['pares']), 'the CESFAM, 900001 and 900002');

		self::assertSame(['local' => '900001', 'pares' => ['tipo' => self::CESFAM]], array_slice($answer['etiquetas'], 0, 2));
		self::assertSame(
			[[
				'codigo' => '02010420',
				'hoja' => 'A03',
				'seccion' => 'SECCIÓN A: PRIMERA SECCIÓN',
				'prestacion' => 'Evaluación › Normal',
				'columnas' => ['col04' => 'POR EDAD › Menos de 1 año › Hombres', 'col05' => 'POR EDAD › Menos de 1 año › Mujeres'],
				'nota' => null,
			]],
			$answer['etiquetas']['codigos'],
		);
		self::assertSame(['rem-a', 'datos públicos DEIS'], [$answer['fuente']['id'], $answer['fuente']['origen']]);
	}

	/** a7: promedio por establecimiento = the suma ÷ the establishments that reported a chosen cell. */
	public function testPromedioDividesByTheEstablishmentsThatReported(): void {
		$answer = $this->executor->answer(self::spec([
			'metrica' => 'promedio_establecimiento',
			'comparar' => ['nacional' => true, 'pares' => ['tipo' => self::CESFAM]],
		]), $this->local());

		self::assertSame([[['2099-01'], 15.0, 1], [['2099-02'], 20.0, 1]], self::flat($answer['series']['local']), 'one establishment: its own suma');
		self::assertEqualsWithDelta(61 / 3, $answer['series']['nacional'][0]['valor'], 1e-9);
		self::assertSame(12.0, $answer['series']['nacional'][1]['valor'], '24 ÷ 2: the one that reported nothing is not counted');
		self::assertSame([27.5, 20.0], array_column($answer['series']['pares'], 'valor'));
	}

	/**
	 * a7, REM and sección: a hoja is the códigos its dictionaries put there,
	 * and 2098, which has none, reads 2099's. The código no dictionary knows
	 * is not in any hoja.
	 */
	public function testAHojaIsWhatItsDictionariesPutThereEvenForABorrowingYear(): void {
		$byHoja = self::spec([
			'periodo' => ['desde' => '2098-01', 'hasta' => '2099-12'],
			'filtros' => ['hoja' => 'A03', 'columnas' => ['col04']],
			'agrupar' => ['anio'],
			'comparar' => ['nacional' => true],
		]);
		$answer = $this->executor->answer($byHoja, Establishment::none());

		self::assertNull($answer['series']['local'], 'an unconfigured install still answers the country');
		self::assertSame([[[2098], 10.0, 2], [[2099], 167.0, 3]], self::flat($answer['series']['nacional']));
		self::assertSame(['02010420', '03500366', '03500400', '06900300'], array_column($answer['etiquetas']['codigos'], 'codigo'));

		$onlyBorrowing = self::spec([
			'periodo' => ['desde' => '2098-01', 'hasta' => '2098-12'],
			'filtros' => ['hoja' => 'A03', 'columnas' => ['col04']],
			'agrupar' => [],
			'comparar' => ['nacional' => true],
		]);
		self::assertSame([[[], 10.0, 2]], self::flat($this->executor->answer($onlyBorrowing, Establishment::none())['series']['nacional']), '2098 alone reads its hoja from 2099\'s dictionary');

		$bySeccion = self::spec([
			'periodo' => ['desde' => '2099-01', 'hasta' => '2099-12'],
			'filtros' => ['hoja' => 'A03', 'seccion' => 'SECCIÓN A: PRIMERA SECCIÓN', 'columnas' => ['col04']],
			'agrupar' => [],
			'comparar' => ['nacional' => true],
		]);
		self::assertSame([[[], 67.0, 3]], self::flat($this->executor->answer($bySeccion, Establishment::none())['series']['nacional']), 'sección B\'s 100 is out');
	}

	public function testAHojaNoDictionaryHasAndACodigoOutsideItAreRefused(): void {
		foreach ([
			[['hoja' => 'A99', 'columnas' => ['col04']], 'Ningún diccionario del periodo tiene la hoja «A99».'],
			[['hoja' => 'A03', 'codigos' => ['09000001'], 'columnas' => ['col04']], 'La prestación 09000001 no está en la hoja «A03» según los diccionarios del periodo.'],
		] as [$filtros, $sentence]) {
			try {
				$this->executor->answer(self::spec(['filtros' => $filtros]), $this->local());
				self::fail('the filter was answered');
			} catch (RefusedException $e) {
				self::assertSame($sentence, $e->getMessage());
			}
		}
	}

	public function testGroupingByServicioAndColumnaKeepsEachApart(): void {
		$answer = $this->executor->answer(self::spec([
			'periodo' => ['desde' => '2099-01', 'hasta' => '2099-01'],
			'agrupar' => ['servicio', 'columna'],
			'comparar' => ['nacional' => true],
		]), $this->local());

		self::assertSame([
			[['Servicio de Salud Dos', 'col04'], 30.0, 1],
			[['Servicio de Salud Dos', 'col05'], 10.0, 1],
			[['Servicio de Salud Uno', 'col04'], 16.0, 2],
			[['Servicio de Salud Uno', 'col05'], 5.0, 1],
		], self::flat($answer['series']['nacional']));
	}

	/** Meta I's window: a periodo across a year stays in the calendar's order, and its labels say whose they are. */
	public function testAPeriodoAcrossAYearStaysInOrder(): void {
		$answer = $this->executor->answer(self::spec([
			'periodo' => ['desde' => '2098-11', 'hasta' => '2099-01'],
			'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col04']],
			'comparar' => ['nacional' => true],
		]), $this->local());
		self::assertSame([[['2098-11'], 8.0, 1], [['2098-12'], 2.0, 1], [['2099-01'], 46.0, 3]], self::flat($answer['series']['nacional']));

		$borrowed = $this->executor->answer(self::spec(['periodo' => ['desde' => '2098-01', 'hasta' => '2098-12']]), $this->local());
		self::assertSame('etiqueta de 2099', $borrowed['etiquetas']['codigos'][0]['nota']);
	}

	public function testPeersLikeOursNeedOursInTheRegister(): void {
		foreach ([
			[Establishment::none(), 'no hay establecimiento configurado'],
			[Establishment::of('900009'), 'El establecimiento 900009 no está en la Base de Establecimientos cargada'],
		] as [$local, $sentence]) {
			try {
				$this->executor->answer(self::spec(['comparar' => ['pares' => ['servicio' => true]]]), $local);
				self::fail('peers were chosen without the local establishment');
			} catch (RefusedException $e) {
				self::assertStringContainsString($sentence, $e->getMessage());
			}
		}

		$this->expectExceptionMessage('La consulta no tiene ninguna serie que calcular');
		$this->executor->answer(self::spec(), Establishment::none());
	}

	/**
	 * a14 and a16 through the route's controller, with the instance's own
	 * executor and config: the configured code by default, and a Spanish 422
	 * for a tampered spec. Its clock is set in 2099, the fixture's year; the
	 * route's wiring is smoke's (scripts/smoke.sh).
	 */
	public function testTheControllerAnswersWithTheConfiguredCodeAndRefusesTamperingInSpanish(): void {
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(gmmktime(12, 0, 0, 6, 1, 2099));
		$controller = new ApiController(
			Server::get(IRequest::class),
			Server::get(Sources::class),
			$this->executor,
			Server::get(EstablishmentConfig::class),
			new YearWindow(Server::get(IAppConfig::class), $time),
			Server::get(SavedContent::class),
			Server::get(GoalEvaluator::class),
		);

		$answered = $controller->consultas(['fuente' => 'rem-a', 'periodo' => ['desde' => '2099-01', 'hasta' => '2099-01'], 'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col04']]]);
		self::assertSame(Http::STATUS_OK, $answered->getStatus());
		self::assertSame('900001', $answered->getData()['etiquetas']['local'], 'no DEIS code in the request binds the configured one');
		self::assertSame(10.0, $answered->getData()['series']['local'][0]['valor']);

		foreach ([
			['sql' => 'DROP TABLE oc_estadistica_rem'],
			['filtros' => ['codigos' => ['02010420'], 'columnas' => ['col51']]],
			['metrica' => 'sum(col01)) FROM oc_users --'],
		] as $tampering) {
			$response = $controller->consultas($tampering + ['fuente' => 'rem-a', 'periodo' => ['desde' => '2099-01', 'hasta' => '2099-01'], 'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col04']]]);
			self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
			self::assertMatchesRegularExpression('/(no admite|no es una columna|no existe)/', $response->getData()['message']);
			self::assertStringNotContainsString('SQLSTATE', $response->getData()['message']);
		}
	}

	/** a16: the transaction is PostgreSQL's to hold to reading, whatever SQL reaches it. */
	public function testAWriteThroughTheExecutorFailsInPostgresql(): void {
		foreach ([
			'INSERT' => ['INSERT INTO *PREFIX*estadistica_rem (serie, anio, mes, estab, codigo) VALUES (?, ?, ?, ?, ?)', ['A', 2099, 3, 900009, '02010420']],
			'DELETE' => ['DELETE FROM *PREFIX*estadistica_rem WHERE anio = ?', [2099]],
		] as $verb => [$sql, $params]) {
			try {
				$this->executor->readOnly(fn (): int => $this->db()->executeStatement($sql, $params));
				self::fail("$verb went through");
			} catch (Exception $e) {
				self::assertStringContainsString("cannot execute $verb in a read-only transaction", $e->getMessage());
			}
		}

		self::assertFalse($this->db()->inTransaction(), 'the failed transaction was rolled back');
		self::assertSame(9, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_rem WHERE anio = ?', [2099])->fetchOne(), 'the rows are untouched');
	}

	/**
	 * a16: a statement past the timeout is cancelled, and says so in Spanish.
	 * The message does not claim the cause: PostgreSQL raises the same
	 * SQLSTATE when an administrator cancels the statement.
	 */
	public function testAStatementPastTheTimeoutIsCancelled(): void {
		$started = microtime(true);
		try {
			$this->executor->readOnly(fn (): array => $this->db()->executeQuery('SELECT pg_sleep(3)')->fetchAll(), 1000);
			self::fail('the statement ran to the end');
		} catch (RefusedException $e) {
			self::assertSame('La consulta se canceló antes de terminar: superó el límite de 1 s, o el servidor la interrumpió. Acote el periodo, las prestaciones o la comparación.', $e->getMessage());
		}
		self::assertLessThan(2.5, microtime(true) - $started, 'cancelled at the timeout, not at the end of the sleep');

		self::assertFalse($this->db()->inTransaction());
		self::assertSame(1, $this->executor->readOnly(fn (): int => (int)$this->db()->executeQuery('SELECT 1')->fetchOne()), 'the connection answers the next consulta');
	}
}
