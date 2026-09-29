<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Controller\AdminController;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Goals\GoalEvaluator;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Migration\EnsureSeedData;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Piv\Population;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Piv\PivFixture;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Migration\IOutput;
use OCP\Server;

/**
 * The Metas Sanitarias against the instance's real PostgreSQL (acceptance a9–a13).
 * The figures are loaded the way the job loads them (RemImporter), FONASA's the
 * way the door loads them (PivImporter), and every expected number is summed by
 * hand from the rows below. The clock is set on 15 December 2099, so 2099 is the
 * meta year with its twelve months, and nothing here meets a real year.
 *
 * The metas of 2099 (this test's seed): Meta I and II on the 2026 routes,
 * Meta C whose denominator only a commitment gives, and Meta VIII, manual.
 *
 * Serie A, Meta I (col08–col11; a blank cell is not written):
 *
 *     estab   año-mes  código     cells            counts in
 *     900001  2098-10  02010321   08=6  09=4       denominador +10
 *     900001  2098-12  03500331   08=1             denominador −1
 *     900001  2099-01  02010420   08=2  09=1       numerador +3
 *     900001  2099-01  03500366   10=1             numerador +1
 *     900001  2099-02  02010321   10=5             denominador +5
 *     900001  2099-02  03500334   11=2             denominador −2
 *     900001  2099-03  02010420   11=3             numerador +3
 *     900001  2099-10  02010321   08=100           past September: never
 *     900002  2098-11  02010321   08=20            the country's denominador
 *     900002  2099-01  02010420   08=10            the country's numerador
 *     900003  2099-12  02010420   08=1             the country's; sets the corte at December
 *
 * Serie P, Meta II (col01 + col02): 900001 in June P1206010 30 + 2 and
 * P1206020 8 = 40, in December P1206010 45; 900002 in June P1206010 300.
 *
 * FONASA, mujeres 25–64: 900001 has 20–29 100 (half is 25–29: 50) and 50 in
 * each of 30–39, 40–49 and 50–59, and 60–69 100 (half: 50) = 250, and 999
 * hombres who never count; 900002 has 1,000 mujeres 30–39. The country:
 * 1,250.
 *
 * 900001 and 900002 are CESFAM, 900003 a PSR: the peers «of the same tipo»
 * are 900001 and 900002. The register is one whole table, so the tests skip
 * on an instance that holds one (EnrichmentTest's reasoning).
 */
class GoalsTest extends IntegrationTestCase {
	private const YEARS = [2098, 2099];

	private const CESFAM = 'Centro de Salud Familiar (CESFAM)';

	private const DROPPED = 'estadistica-metas-test.json';

	private bool $ownsRegister = false;

	private ?int $droppedYear = null;

	private ITimeFactory $clock;

	protected function setUp(): void {
		parent::setUp();
		if ((int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_establishment')->fetchOne() > 0) {
			self::markTestSkipped('this instance already holds a Base de Establecimientos; run the integration suite on a throwaway instance');
		}
		$this->ownsRegister = true;
		$this->clock = $this->createStub(ITimeFactory::class);
		$this->clock->method('getTime')->willReturn(gmmktime(12, 0, 0, 12, 15, 2099));

		Server::get(Register::class)->replace(RegisterParser::parse(XlsxReader::open(XlsxFixture::register([
			XlsxFixture::establishment(900001),
			XlsxFixture::establishment(900002, ['servicio_codigo' => '92', 'servicio' => 'Servicio de Salud Dos']),
			XlsxFixture::establishment(900003, ['tipo' => 'Posta de Salud Rural (PSR)']),
		])))['rows'], IngestState::ORIGIN_DEIS, 2098);

		$importer = Server::get(RemImporter::class);
		$loads = [
			[2098, 'A', ZipFixture::row(2098, 10, '900001', '02010321', [8 => '6', 9 => '4'])
				. ZipFixture::row(2098, 11, '900002', '02010321', [8 => '20'])
				. ZipFixture::row(2098, 12, '900001', '03500331', [8 => '1'])],
			[2099, 'A', ZipFixture::row(2099, 1, '900001', '02010420', [8 => '2', 9 => '1'])
				. ZipFixture::row(2099, 1, '900001', '03500366', [10 => '1'])
				. ZipFixture::row(2099, 1, '900002', '02010420', [8 => '10'])
				. ZipFixture::row(2099, 2, '900001', '02010321', [10 => '5'])
				. ZipFixture::row(2099, 2, '900001', '03500334', [11 => '2'])
				. ZipFixture::row(2099, 3, '900001', '02010420', [11 => '3'])
				. ZipFixture::row(2099, 10, '900001', '02010321', [8 => '100'])
				. ZipFixture::row(2099, 12, '900003', '02010420', [8 => '1'])],
			[2099, 'P', ZipFixture::row(2099, 6, '900001', 'P1206010', [1 => '30', 2 => '2'])
				. ZipFixture::row(2099, 6, '900001', 'P1206020', [1 => '8'])
				. ZipFixture::row(2099, 6, '900002', 'P1206010', [1 => '300'])
				. ZipFixture::row(2099, 12, '900001', 'P1206010', [1 => '45'])],
		];
		foreach ($loads as [$year, $serie, $rows]) {
			$state = $importer->import(new LocalRange(ZipFixture::zip(["Serie{$serie}{$year}.csv" => ZipFixture::header() . $rows])), $year, $serie, IngestState::ORIGIN_FILE);
			self::assertGreaterThan(0, $state->getRowCount(), "the $year $serie fixture loads");
		}

		$this->goals()->store(self::seed());
		$this->provision(EstablishmentConfig::KEY_DEIS, '900001');
	}

	protected function tearDown(): void {
		foreach (self::YEARS as $year) {
			foreach (['estadistica_rem', 'estadistica_ingest', 'estadistica_code', 'estadistica_dictionary', 'estadistica_goal', 'estadistica_commitment'] as $table) {
				$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE anio = ?", [$year]);
			}
		}
		if ($this->droppedYear !== null) {
			$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_goal WHERE anio = ?', [$this->droppedYear]);
		}
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_commitment WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_piv WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		if ($this->ownsRegister) {
			$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_establishment');
			Server::get(IAppConfig::class)->deleteKey('estadistica', Register::KEY);
			try {
				Server::get(IRootFolder::class)->getUserFolder('admin')->get(self::DROPPED)->delete();
			} catch (NotFoundException) {
				// Only the door's test puts a file in Files.
			}
		}
		ZipFixture::cleanup();
		XlsxFixture::cleanup();
		parent::tearDown();
	}

	/** @return array<string, mixed> 2099's metas: I and II on the 2026 routes, C counted only against a commitment, VIII manual */
	private static function seed(int $anio = 2099): array {
		$columns = ['col08', 'col09', 'col10', 'col11'];
		$previous = $anio - 1;

		return [
			'anio' => $anio,
			'version' => 1,
			'nombre' => "Metas de prueba $anio",
			'referencia' => 'GoalsTest',
			'diccionarios' => ['rem-a' => 'SA_99_V1.0', 'rem-p' => 'SP_99_V1.0'],
			'indicadores' => [
				[
					'codigo' => 'I', 'nombre' => 'DSM', 'indicador' => 'Recuperación del DSM', 'meta_nacional' => 90, 'peso' => 25,
					'numerador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => "$anio-01", 'hasta' => "$anio-12"], 'sumar' => [['codigos' => ['02010420', '03500366'], 'columnas' => $columns]]],
					'denominador' => [
						'fuente' => 'rem-a', 'periodo' => ['desde' => "$previous-10", 'hasta' => "$anio-09"],
						'sumar' => [['codigos' => ['02010321'], 'columnas' => $columns]],
						'restar' => [['codigos' => ['03500331', '03500334'], 'columnas' => $columns]],
					],
				],
				[
					'codigo' => 'II', 'nombre' => 'PAP', 'indicador' => 'Tamizaje vigente', 'meta_nacional' => 80, 'peso' => 25,
					'numerador' => ['fuente' => 'rem-p', 'periodo' => ['desde' => "$anio-01", 'hasta' => "$anio-12"], 'sumar' => [['codigos' => ['P1206010', 'P1206020', 'P1206030', 'P1206040', 'P1206050', 'P1206060', 'P1206070', 'P1206080'], 'columnas' => ['col01', 'col02']]]],
					'denominador' => ['poblacion' => [['desde' => 25, 'hasta' => 64, 'sexo' => 'mujeres']]],
				],
				[
					'codigo' => 'C', 'nombre' => 'Comprometida', 'indicador' => 'Contra lo comprometido', 'meta_nacional' => 50, 'peso' => 25,
					'numerador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => "$anio-01", 'hasta' => "$anio-12"], 'sumar' => [['codigos' => ['02010420'], 'columnas' => ['col08']]]],
					'denominador' => ['comprometido' => true],
				],
				['codigo' => 'VIII', 'nombre' => 'Participación', 'indicador' => 'Plan de participación', 'meta_nacional' => 100, 'peso' => 25, 'manual' => true],
			],
		];
	}

	private function window(): YearWindow {
		return new YearWindow(Server::get(IAppConfig::class), $this->clock);
	}

	private function goals(): Goals {
		return new Goals($this->db(), Server::get(EstablishmentConfig::class), $this->window(), $this->clock);
	}

	private function evaluator(): GoalEvaluator {
		return new GoalEvaluator(
			$this->goals(),
			Server::get(QueryExecutor::class),
			Server::get(Population::class),
			Server::get(Register::class),
			new Sources(Server::get(IngestStateMapper::class), Server::get(Catalog::class), Server::get(Register::class), $this->window(), Server::get(PivImporter::class)),
			$this->clock,
		);
	}

	/** @return array<string, array<string, mixed>> 2099's metas for this install's establishment, by codigo */
	private function evaluate(array $traits = ['tipo']): array {
		return array_column($this->evaluator()->evaluate(2099, Server::get(EstablishmentConfig::class)->get(), $traits)['indicadores'], null, 'codigo');
	}

	/** FONASA's file, loaded through the importer the door uses. */
	private function fonasa(string $periodo): void {
		Server::get(PivImporter::class)->import(ZipFixture::file(PivFixture::csv([
			PivFixture::row($periodo, 900001, 'A', '20 a 29 años', 'Mujeres', 100),
			PivFixture::row($periodo, 900001, 'B', '30 a 39 años', 'Mujeres', 50),
			PivFixture::row($periodo, 900001, 'A', '40 a 49 años', 'Mujeres', 50),
			PivFixture::row($periodo, 900001, 'A', '50 a 59 años', 'Mujeres', 50),
			PivFixture::row($periodo, 900001, 'A', '60 a 69 años', 'Mujeres', 100),
			PivFixture::row($periodo, 900001, 'A', '30 a 39 años', 'Hombres', 999),
			PivFixture::row($periodo, 900002, 'A', '30 a 39 años', 'Mujeres', 1000),
		])));
	}

	/** @param list<mixed> $commitments */
	private function commit(array $commitments): void {
		$this->goals()->commit(2099, $commitments);
	}

	/**
	 * a13: Meta I for a closed month is the hand-sum of its Anexo 3 route.
	 * December: numerador 3 + 1 + 3 = 7; denominador (10 + 5) − (1 + 2) = 12,
	 * October to September, so October's 100 never counts.
	 */
	public function testMetaIIsTheHandSumOfItsRoute(): void {
		$meses = $this->evaluate()['I']['meses'];

		self::assertCount(12, $meses);
		self::assertSame(['2099-12', 7.0, 12.0, 58.33], [$meses[11]['mes'], $meses[11]['numerador'], $meses[11]['denominador'], $meses[11]['porcentaje']]);
		// Month by month: January 4 ÷ (10 − 1) = 44.44 %; February 4 ÷ 12 = 33.33 %; March 7 ÷ 12.
		self::assertSame([[4.0, 9.0, 44.44], [4.0, 12.0, 33.33], [7.0, 12.0, 58.33]], array_map(
			static fn (array $mes): array => [$mes['numerador'], $mes['denominador'], $mes['porcentaje']],
			array_slice($meses, 0, 3),
		));
		// The country: December (2 + 1 + 1 + 3 + 10 + 1 = 18) ÷ (10 + 20 − 1 + 5 − 2 = 32) = 56.25 %; January 14 ÷ 29.
		self::assertSame([56.25, 48.28], [$meses[11]['nacional'], $meses[0]['nacional']]);
		// The CESFAM peers leave out 900003's December 1: 17 ÷ 32 = 53.13 %.
		self::assertSame(53.13, $meses[11]['pares']);
		self::assertSame(-31.67, $meses[11]['brecha_nacional'], '58.33 − 90');
		self::assertNull($meses[11]['brecha_comprometida'], 'nothing committed yet');
	}

	/**
	 * a13, a11: Meta II is P12 col01 + col02 over FONASA's mujeres 25–64 for the
	 * corte of 31-12-2098: December 45 ÷ 250 = 18 %; June 40 ÷ 250 = 16 %.
	 */
	public function testMetaIIIsItsP12CorteOverTheInscritos(): void {
		$this->fonasa('31-12-2098');

		$ii = $this->evaluate()['II'];

		self::assertSame(['comprometida' => false, 'corte' => '2098-12-31', 'factor' => 1.0, 'prorrateado' => true], $ii['poblacion'], '25–29 and 60–64 are half-bands: «estimado por prorrateo»');
		self::assertSame([null, null], [$ii['meses'][4]['numerador'], $ii['meses'][4]['porcentaje']], 'before June there is no corte of the year');
		self::assertSame([40.0, 250.0, 16.0], [$ii['meses'][5]['numerador'], $ii['meses'][5]['denominador'], $ii['meses'][5]['porcentaje']]);
		self::assertSame([40.0, 16.0], [$ii['meses'][10]['numerador'], $ii['meses'][10]['porcentaje']], 'June\'s corte stands until December\'s');
		self::assertSame([45.0, 250.0, 18.0], [$ii['meses'][11]['numerador'], $ii['meses'][11]['denominador'], $ii['meses'][11]['porcentaje']]);
		// The country: June (40 + 300) ÷ 1,250 = 27.2 %, December 45 ÷ 1,250 = 3.6 %; the CESFAM peers are the same two.
		self::assertSame([27.2, 3.6, 27.2, 3.6], [$ii['meses'][5]['nacional'], $ii['meses'][11]['nacional'], $ii['meses'][5]['pares'], $ii['meses'][11]['pares']]);
	}

	/**
	 * a11: a year with no corte of its own reads the latest earlier one × 1.03,
	 * unless a committed value is set. 250 × 1.03 = 257.5, so December is
	 * 45 ÷ 257.5 = 17.48 %; committed at 200, it is 22.5 %, and the country
	 * stays FONASA's.
	 */
	public function testAMissingCorteGrowsByThreePercentUnlessADenominatorIsCommitted(): void {
		$this->fonasa('31-12-2097');

		$grown = $this->evaluate()['II'];
		self::assertSame(['comprometida' => false, 'corte' => '2097-12-31', 'factor' => 1.03, 'prorrateado' => true], $grown['poblacion']);
		self::assertSame([257.5, 17.48], [$grown['meses'][11]['denominador'], $grown['meses'][11]['porcentaje']]);

		$this->commit([['codigo' => 'II', 'denominador' => 200]]);

		$committed = $this->evaluate()['II'];
		self::assertSame([200.0, true], [$committed['denominador_comprometido'], $committed['poblacion']['comprometida']]);
		self::assertSame([200.0, 22.5], [$committed['meses'][11]['denominador'], $committed['meses'][11]['porcentaje']]);
		// 45 ÷ (1,250 × 1.03) = 3.5 %.
		self::assertSame(3.5, $committed['meses'][11]['nacional'], 'the country is never bent by one install\'s commitment');
	}

	/**
	 * a12: the gaps are to the meta comprometida and to the meta nacional; the
	 * country and the peers are absent exactly where the denominator is not
	 * nationally computable; Meta VIII is its typed cumplimiento.
	 */
	public function testTheCommitmentsAndWhatIsNationallyComputable(): void {
		$this->commit([
			['codigo' => 'I', 'meta' => 60],
			['codigo' => 'C', 'meta' => 30, 'denominador' => 8],
			['codigo' => 'VIII', 'meta' => 100, 'cumplimiento' => 100],
		]);

		$metas = $this->evaluate();

		self::assertSame([60.0, -1.67], [$metas['I']['meta_comprometida'], $metas['I']['meses'][11]['brecha_comprometida']], '58.33 − 60');
		// Meta C: January 900001 col08 = 2, over the 8 committed = 25 %, and no country to compare.
		self::assertSame([2.0, 8.0, 25.0, -5.0, null, null], [
			$metas['C']['meses'][0]['numerador'], $metas['C']['meses'][0]['denominador'], $metas['C']['meses'][0]['porcentaje'],
			$metas['C']['meses'][0]['brecha_comprometida'], $metas['C']['meses'][0]['nacional'], $metas['C']['meses'][0]['pares'],
		]);
		self::assertSame([true, 100.0, [], null], [$metas['VIII']['manual'], $metas['VIII']['cumplimiento'], $metas['VIII']['meses'], $metas['VIII']['poblacion']]);

		// With no FONASA corte, Meta II has no denominator anywhere: no % and no national one.
		self::assertSame([null, null, null], [$metas['II']['meses'][11]['porcentaje'], $metas['II']['meses'][11]['nacional'], $metas['II']['meses'][11]['pares']]);
		self::assertNull($metas['II']['poblacion']['corte']);
	}

	/** The peers are «like ours» by the traits asked for, or none, and the answer says why. */
	public function testThePeersAreTheRegistersEstablishmentsLikeThisOne(): void {
		$answer = $this->evaluator()->evaluate(2099, Establishment::of('900001'), ['servicio']);
		self::assertSame(['servicio' => 'Servicio de Salud Uno'], $answer['pares']);
		// 900001 and 900003 share Servicio Uno: December (7 + 1) ÷ 12 = 66.67 %.
		self::assertSame(66.67, array_column($answer['indicadores'], null, 'codigo')['I']['meses'][11]['pares']);

		$none = $this->evaluator()->evaluate(2099, Establishment::of('900001'), []);
		self::assertSame([null, null], [$none['pares'], $none['aviso']]);
		self::assertNull(array_column($none['indicadores'], null, 'codigo')['I']['meses'][11]['pares']);

		$unknown = $this->evaluator()->evaluate(2099, Establishment::of('900009'), ['tipo']);
		self::assertNull($unknown['pares']);
		self::assertStringContainsString('900009 no está en la Base de Establecimientos', (string)$unknown['aviso']);
		self::assertSame(['2099', '900009'], [(string)$unknown['anio'], $unknown['establecimiento']]);
	}

	/** Without an establishment there is no local series and nothing to commit. */
	public function testAnInstallWithNoEstablishment(): void {
		$metas = array_column($this->evaluator()->evaluate(2099, Establishment::none(), ['tipo'])['indicadores'], null, 'codigo');

		self::assertSame([null, null, 56.25], [$metas['I']['meses'][11]['numerador'], $metas['I']['meses'][11]['porcentaje'], $metas['I']['meses'][11]['nacional']]);
		self::assertNull($metas['I']['meses'][11]['pares'], 'no «ours», so no peers like it');
	}

	/**
	 * a10: the commitments are a delegated admin write, through the section's
	 * controller; each refusal is Spanish and writes nothing. The delegation
	 * itself (a delegate 200, an outsider 403) is smoke's: only core's
	 * middleware on a real request decides it.
	 */
	public function testTheCommitmentsAreWrittenThroughTheAdminSection(): void {
		$admin = $this->admin();

		$saved = $admin->goals(2099, [['codigo' => 'I', 'meta' => 75.5]]);
		self::assertSame(Http::STATUS_OK, $saved->getStatus());
		self::assertContains(['anio' => 2099, 'codigo' => 'I', 'meta' => 75.5, 'denominador' => null, 'cumplimiento' => null], $saved->getData()['local']['commitments']);

		foreach ([
			'no metas that year' => [2098, [['codigo' => 'I', 'meta' => 50]], 'No hay Metas Sanitarias cargadas para 2098.'],
			'a meta the year lacks' => [2099, [['codigo' => 'IX', 'meta' => 50]], 'Cada compromiso nombra una meta de 2099: I, II, C, VIII.'],
			'a REM denominator' => [2099, [['codigo' => 'I', 'denominador' => 10]], 'El denominador de la Meta I sale de las cifras REM: no se compromete.'],
			'a computed meta\'s cumplimiento' => [2099, [['codigo' => 'II', 'cumplimiento' => 80]], 'La Meta II se calcula de las cifras: su cumplimiento no se escribe a mano.'],
			'a manual meta\'s denominator' => [2099, [['codigo' => 'VIII', 'denominador' => 1]], 'La Meta VIII se informa a mano: no tiene denominador.'],
			'a percentage over 100' => [2099, [['codigo' => 'I', 'meta' => 101]], 'La meta comprometida de la Meta I es un porcentaje de 0 a 100.'],
			'a figure as text' => [2099, [['codigo' => 'II', 'denominador' => '200']], 'El denominador comprometido de la Meta II es un número de personas, de cero para arriba.'],
			'a meta twice' => [2099, [['codigo' => 'I', 'meta' => 1], ['codigo' => 'I', 'meta' => 2]], 'El compromiso de la Meta I viene dos veces.'],
		] as $case => [$year, $commitments, $sentence]) {
			$refused = $admin->goals($year, $commitments);
			self::assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, $sentence], [$refused->getStatus(), $refused->getData()['message']], $case);
		}
		self::assertSame(['I' => ['meta' => 75.5, 'denominador' => null, 'cumplimiento' => null]], $this->goals()->commitments(2099, 900001), 'every refusal left the commitment as it was');

		$admin->goals(2099, []);
		self::assertSame([], $this->goals()->commitments(2099, 900001), 'an empty list clears the year');

		$this->occ('config:app:delete', 'estadistica', EstablishmentConfig::KEY_DEIS);
		$this->appConfig()->clearCache(true);
		$refused = $admin->goals(2099, [['codigo' => 'I', 'meta' => 50]]);
		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		self::assertStringContainsString('no se ha configurado el establecimiento', $refused->getData()['message']);
	}

	/**
	 * a9: the app ships 2026's metas, loaded when it was enabled; running the
	 * step again changes nothing, and a year left without them is loaded anew.
	 */
	public function testTheShippedMetasAreLoadedOnceAndAgainWhenMissing(): void {
		$goals = Server::get(Goals::class);
		$shipped = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/seeds/metas-2026.json'), true, 64, JSON_THROW_ON_ERROR);
		if ($goals->version(2026) !== $shipped['version']) {
			self::markTestSkipped('this instance holds 2026 metas of another version than the shipped file; run the integration suite on a throwaway instance');
		}
		$loadedAt = static fn (IDBConnection $db): int => (int)$db->executeQuery('SELECT loaded_at FROM *PREFIX*estadistica_goal WHERE anio = 2026')->fetchOne();
		$before = $loadedAt($this->db());
		self::assertSame(['I', 'II', 'III.A', 'III.B', 'IV.A', 'IV.B', 'V', 'VI', 'VII', 'VIII'], array_column($goals->get(2026)['indicadores'], 'codigo'));

		// A mark no load writes, so a second load in the same second still shows.
		$this->db()->executeStatement('UPDATE *PREFIX*estadistica_goal SET loaded_at = 1 WHERE anio = 2026');
		$step = Server::get(EnsureSeedData::class);
		$step->run($this->createStub(IOutput::class));
		self::assertSame(1, $loadedAt($this->db()), 'the same version is not loaded again');
		$this->db()->executeStatement('UPDATE *PREFIX*estadistica_goal SET loaded_at = ? WHERE anio = 2026', [$before]);

		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_goal WHERE anio = 2026');
		$step->run($this->createStub(IOutput::class));
		self::assertSame($shipped['version'], $goals->version(2026), 'a year without metas gets the shipped ones');
	}

	/**
	 * a9: a later year's metas are a file an admin picks in Files, through the
	 * section's door, with no code change: next year's, as they are fixed in
	 * October.
	 */
	public function testALaterYearsMetasArriveThroughTheDoor(): void {
		$next = (int)gmdate('Y') + 1;
		if (Server::get(Goals::class)->version($next) !== null) {
			self::markTestSkipped("this instance already holds metas for $next");
		}
		$this->putInFiles('admin', self::DROPPED, ZipFixture::file(json_encode(self::seed($next), JSON_THROW_ON_ERROR)));
		$this->droppedYear = $next;

		$response = $this->admin()->drop('/' . self::DROPPED);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['metas' => ['anio' => $next, 'version' => 1, 'indicadores' => 4]], $response->getData());
		self::assertSame(['I', 'II', 'C', 'VIII'], array_column(Server::get(Goals::class)->get($next)['indicadores'], 'codigo'));
		self::assertContains($next, array_column($this->admin()->ingests()->getData()['metas']['years'], 'anio'), 'the section lists the new year');

		// A second file for the year replaces the first whole.
		$corrected = self::seed($next);
		$corrected['version'] = 2;
		array_pop($corrected['indicadores']);
		Server::get(IRootFolder::class)->getUserFolder('admin')->get(self::DROPPED)->delete();
		$this->putInFiles('admin', self::DROPPED, ZipFixture::file(json_encode($corrected, JSON_THROW_ON_ERROR)));
		self::assertSame(['metas' => ['anio' => $next, 'version' => 2, 'indicadores' => 3]], $this->admin()->drop('/' . self::DROPPED)->getData());
		self::assertSame(['I', 'II', 'C'], array_column(Server::get(Goals::class)->get($next)['indicadores'], 'codigo'));
	}

	/** A metas file the seed cannot read is refused whole, in Spanish, and the stored year stays. */
	public function testAMetasFileItCannotReadIsRefusedInSpanish(): void {
		$next = (int)gmdate('Y') + 1;
		$broken = self::seed($next);
		$broken['indicadores'][0]['numerador']['sumar'][0]['columnas'] = ['col51'];
		$this->putInFiles('admin', self::DROPPED, ZipFixture::file(json_encode($broken, JSON_THROW_ON_ERROR)));

		$response = $this->admin()->drop('/' . self::DROPPED);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringStartsWith('Meta I, numerador: «col51» no es una columna del REM', $response->getData()['message']);
	}

	/** The admin section's controller, acting for admin through the same door. */
	private function admin(): AdminController {
		return new AdminController(
			Server::get(IRequest::class),
			Server::get(YearWindow::class),
			$this->dropsFor('admin'),
			Server::get(IngestStateMapper::class),
			Server::get(DeisRepository::class),
			Server::get(Catalog::class),
			Server::get(Register::class),
			Server::get(PivImporter::class),
			$this->goals(),
		);
	}
}
