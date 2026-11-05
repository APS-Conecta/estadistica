<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\BackgroundJob\ResumenJob;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Controller\AdminController;
use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Goals\GoalEvaluator;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Goals\Resumen;
use OCA\Estadistica\Goals\ResumenStore;
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
use OCA\Estadistica\Service\SavedContent;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Piv\PivFixture;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Migration\IOutput;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * The Metas Sanitarias against the instance's real PostgreSQL (acceptance a9–a13).
 * The figures are loaded the way the job loads them (RemImporter), FONASA's the
 * way the door loads them (PivImporter), and every expected number is summed by
 * hand from the rows below. The clock is set on 15 December 2099, so 2099 is the
 * meta year with its twelve months, and nothing here meets a real year.
 *
 * The metas of 2099 (this test's seed): Meta I and II on the 2026 routes,
 * Meta T, II's numerador over whole age bands, Meta C whose denominator only
 * a commitment gives, and Meta VIII, manual.
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
 *     900001  2099-10  02010321   08=100           past September: never; 900001's last month
 *     900001  2099-11  02010420   (every cell blank) no written number: not a reported month
 *     900002  2098-11  02010321   08=20            the country's denominador
 *     900002  2099-01  02010420   08=10            the country's numerador
 *     900003  2099-12  02010420   08=1             the country's; sets the corte at December
 *
 * Serie P, Metas II and T (col01 + col02): 900001 in June P1206010 30 + 2
 * and P1206020 8 = 40, in December P1206010 45; 900002 in June P1206010 300.
 *
 * Earlier years, for the Resumen's histórico (docs/adr/0009), all 900001's:
 *
 *     año-mes  código     cells   counts in, under the 2099 metas moved onto that year
 *     2096-05  02010420   08=2    Meta C 2096
 *     2097-11  02010321   08=4    Meta I 2098's denominador (2097-10 to 2098-09)
 *     2098-02  02010420   08=5    Meta I and Meta C 2098's numerador
 *     2098-03  02010321   08=6    Meta I 2098's denominador
 *     2098-12  P1206010   01=35   Meta II and T 2098's December corte
 *
 * Each serie's diccionario labels the routes' códigos in 2096, 2098 and 2099;
 * 2098's writes Serie A's labels in capitals, without accents and in the
 * plural. 2097 has none, so it borrows 2098's.
 *
 * FONASA: Meta II's mujeres 25–64 cut the 20–29 and 60–69 bands, so the PIV
 * gives it no figure (docs/adr/0007, amendment). Meta T's mujeres 20–69 are
 * whole bands: 900001 has 100 in 20–29, 50 in each of 30–39, 40–49 and
 * 50–59, and 100 in 60–69 = 350, and 999 hombres who never count; 900002 has
 * 1,000 mujeres 30–39. The country: 1,350.
 *
 * 900001 and 900002 are CESFAM, 900003 a PSR: the peers «of the same tipo»
 * are 900001 and 900002. The register is one whole table, so the tests skip
 * on an instance that holds one (EnrichmentTest's reasoning).
 */
class GoalsTest extends IntegrationTestCase {
	private const YEARS = [2096, 2097, 2098, 2099, 2100];

	private const CESFAM = 'Centro de Salud Familiar (CESFAM)';

	private const DROPPED = 'estadistica-metas-test.json';

	/** Serie A's diccionario: each route código's prestación. */
	private const LABELS_A = [
		'02010420' => 'Evaluación de recuperación',
		'03500366' => 'Reevaluación normal',
		'02010321' => 'Evaluación inicial con rezago',
		'03500331' => 'Traslado a otro centro',
		'03500334' => 'Egreso por fallecimiento',
	];

	/** LABELS_A as 2098's diccionario writes them: capitals, no accents, plurals. */
	private const FOLDED_A = [
		'02010420' => 'EVALUACIONES DE RECUPERACION',
		'03500366' => 'REEVALUACIONES NORMALES',
		'02010321' => 'EVALUACIONES INICIALES CON REZAGO',
		'03500331' => 'TRASLADOS A OTROS CENTROS',
		'03500334' => 'EGRESOS POR FALLECIMIENTO',
	];

	/** Serie P's diccionario: P12's age bands. */
	private const LABELS_P = [
		'P1206010' => 'De 25 a 29 años', 'P1206020' => 'De 30 a 34 años', 'P1206030' => 'De 35 a 39 años', 'P1206040' => 'De 40 a 44 años',
		'P1206050' => 'De 45 a 49 años', 'P1206060' => 'De 50 a 54 años', 'P1206070' => 'De 55 a 59 años', 'P1206080' => 'De 60 a 64 años',
	];

	private bool $ownsRegister = false;

	private ?int $droppedYear = null;

	private ITimeFactory $clock;

	protected function setUp(): void {
		parent::setUp();
		if ((int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_establishment')->fetchOne() > 0) {
			self::markTestSkipped('this instance already holds a Base de Establecimientos; run the integration suite on a throwaway instance');
		}
		$this->ownsRegister = true;
		$this->clock = $this->at(gmmktime(12, 0, 0, 12, 15, 2099));
		Server::get(IJobList::class)->remove(ResumenJob::class);

		Server::get(Register::class)->replace(RegisterParser::parse(XlsxReader::open(XlsxFixture::register([
			XlsxFixture::establishment(900001),
			XlsxFixture::establishment(900002, ['servicio_codigo' => '92', 'servicio' => 'Servicio de Salud Dos']),
			XlsxFixture::establishment(900003, ['tipo' => 'Posta de Salud Rural (PSR)']),
		])))['rows'], IngestState::ORIGIN_DEIS, 2098);

		foreach (self::loads() as [$year, $serie, $rows, $labels]) {
			$this->load($year, $serie, $rows, $labels);
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
			// A year the app ships metas for gets them back (2027's).
			Server::get(EnsureSeedData::class)->run($this->createStub(IOutput::class));
		}
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_commitment WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_piv WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_resumen WHERE estab BETWEEN ? AND ?', [900001, 900099]);
		Server::get(IJobList::class)->remove(ResumenJob::class);
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

	/** @return ?array<string, mixed> the metas the app ships for $anio, as the file writes them; null when it ships none */
	private static function shipped(int $anio): ?array {
		$file = dirname(__DIR__, 2) . "/seeds/metas-$anio.json";

		return is_file($file) ? json_decode((string)file_get_contents($file), true, 64, JSON_THROW_ON_ERROR) : null;
	}

	/** @return array<string, mixed> 2099's metas: I and II on the 2026 routes, T over whole bands, C counted only against a commitment, VIII manual */
	private static function seed(int $anio = 2099): array {
		$columns = ['col08', 'col09', 'col10', 'col11'];
		$previous = $anio - 1;
		$p12 = ['fuente' => 'rem-p', 'periodo' => ['desde' => "$anio-01", 'hasta' => "$anio-12"], 'sumar' => [['codigos' => ['P1206010', 'P1206020', 'P1206030', 'P1206040', 'P1206050', 'P1206060', 'P1206070', 'P1206080'], 'columnas' => ['col01', 'col02']]]];

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
					'numerador' => $p12,
					'denominador' => ['poblacion' => [['desde' => 25, 'hasta' => 64, 'sexo' => 'mujeres']]],
				],
				[
					'codigo' => 'T', 'nombre' => 'Tramos enteros', 'indicador' => 'Contra las mujeres de 20 a 69 años', 'meta_nacional' => 80, 'peso' => 25,
					'numerador' => $p12,
					'denominador' => ['poblacion' => [['desde' => 20, 'hasta' => 69, 'sexo' => 'mujeres']]],
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

	/**
	 * Each year and serie the setUp loads: its rows, and its diccionario's
	 * labels (null for none).
	 *
	 * @return list<array{0: int, 1: string, 2: string, 3: ?array<string, string>}>
	 */
	private static function loads(): array {
		$a = self::LABELS_A;
		$p = self::LABELS_P;

		return [
			[2096, 'A', ZipFixture::row(2096, 5, '900001', '02010420', [8 => '2']), $a],
			[2097, 'A', ZipFixture::row(2097, 11, '900001', '02010321', [8 => '4']), null],
			[2098, 'A', ZipFixture::row(2098, 2, '900001', '02010420', [8 => '5'])
				. ZipFixture::row(2098, 3, '900001', '02010321', [8 => '6'])
				. ZipFixture::row(2098, 10, '900001', '02010321', [8 => '6', 9 => '4'])
				. ZipFixture::row(2098, 11, '900002', '02010321', [8 => '20'])
				. ZipFixture::row(2098, 12, '900001', '03500331', [8 => '1']), self::FOLDED_A],
			[2098, 'P', ZipFixture::row(2098, 12, '900001', 'P1206010', [1 => '35']), $p],
			[2099, 'A', ZipFixture::row(2099, 1, '900001', '02010420', [8 => '2', 9 => '1'])
				. ZipFixture::row(2099, 1, '900001', '03500366', [10 => '1'])
				. ZipFixture::row(2099, 1, '900002', '02010420', [8 => '10'])
				. ZipFixture::row(2099, 2, '900001', '02010321', [10 => '5'])
				. ZipFixture::row(2099, 2, '900001', '03500334', [11 => '2'])
				. ZipFixture::row(2099, 3, '900001', '02010420', [11 => '3'])
				. ZipFixture::row(2099, 10, '900001', '02010321', [8 => '100'])
				. ZipFixture::row(2099, 11, '900001', '02010420')
				. ZipFixture::row(2099, 12, '900003', '02010420', [8 => '1']), $a],
			[2099, 'P', ZipFixture::row(2099, 6, '900001', 'P1206010', [1 => '30', 2 => '2'])
				. ZipFixture::row(2099, 6, '900001', 'P1206020', [1 => '8'])
				. ZipFixture::row(2099, 6, '900002', 'P1206010', [1 => '300'])
				. ZipFixture::row(2099, 12, '900001', 'P1206010', [1 => '45']), $p],
		];
	}

	/**
	 * One year and serie through the importer the job uses, with a
	 * DICCIONARIO CODIGOS in DEIS's layout when $labels names the prestación
	 * of each código: one hoja, one sección, the column headers above the
	 * COLnn markers (Serie A col08–col11, Serie P col01–col02).
	 *
	 * @param ?array<string, string> $labels código → prestación
	 */
	private function load(int $year, string $serie, string $rows, ?array $labels): void {
		$members = ["Serie{$serie}{$year}.csv" => ZipFixture::header() . $rows];
		if ($labels !== null) {
			$columns = $serie === 'A'
				? ['C' => ['COL08', 'Niños de 12 a 17 meses'], 'D' => ['COL09', 'Niñas de 12 a 17 meses'], 'E' => ['COL10', 'Niños de 18 a 23 meses'], 'F' => ['COL11', 'Niñas de 18 a 23 meses']]
				: ['C' => ['COL01', 'Mujeres'], 'D' => ['COL02', 'Personas trans masculinas']];
			$sheet = [
				6 => ['B' => "REM-{$serie}12. HOJA DE PRUEBA"],
				8 => ['B' => 'SECCIÓN A: SECCIÓN DE PRUEBA'],
				9 => ['B' => 'PRESTACIÓN', ...array_map(static fn (array $column): string => $column[1], $columns)],
			];
			foreach (array_keys($labels) as $i => $codigo) {
				$sheet[10 + $i] = ['A' => $codigo, 'B' => $labels[$codigo], ...array_map(static fn (array $column): string => $column[0], $columns)];
			}
			$members["Diccionarios/DICCIONARIO CODIGOS S{$serie}_99_V1.0.xlsm"] = (string)file_get_contents(XlsxFixture::workbook(["{$serie}12" => $sheet]));
		}
		$state = Server::get(RemImporter::class)->import(new LocalRange(ZipFixture::zip($members)), $year, $serie, IngestState::ORIGIN_FILE);
		self::assertGreaterThan(0, $state->getRowCount(), "the $year $serie fixture loads");
	}

	private function at(int $time): ITimeFactory {
		$clock = $this->createStub(ITimeFactory::class);
		$clock->method('getTime')->willReturn($time);

		return $clock;
	}

	private function window(?ITimeFactory $clock = null): YearWindow {
		return new YearWindow(Server::get(IAppConfig::class), $clock ?? $this->clock);
	}

	private function goals(?ITimeFactory $clock = null): Goals {
		return new Goals($this->db(), Server::get(EstablishmentConfig::class), $this->window($clock), $clock ?? $this->clock, Server::get(IJobList::class));
	}

	private function evaluator(?ITimeFactory $clock = null): GoalEvaluator {
		return new GoalEvaluator(
			$this->goals($clock),
			Server::get(QueryExecutor::class),
			Server::get(Population::class),
			Server::get(Register::class),
			$this->sources($clock),
			$clock ?? $this->clock,
		);
	}

	private function sources(?ITimeFactory $clock = null): Sources {
		return new Sources(Server::get(IngestStateMapper::class), Server::get(Catalog::class), Server::get(Register::class), $this->window($clock), Server::get(PivImporter::class));
	}

	/** The stored Resumen, on this test's clock unless $clock says otherwise. */
	private function resumen(?ITimeFactory $clock = null): ResumenStore {
		$clock ??= $this->clock;

		return new ResumenStore($this->db(), new Resumen($this->goals($clock), $this->evaluator($clock), Server::get(Catalog::class), $this->window($clock), $this->sources($clock)), Server::get(EstablishmentConfig::class), $clock, Server::get(LoggerInterface::class));
	}

	/** How many rebuilds wait in the background work. */
	private static function queued(): int {
		return iterator_count(Server::get(IJobList::class)->getJobsIterator(ResumenJob::class, null, 0));
	}

	/** The queued rebuild, run the way ResumenJob runs it, on $clock. */
	private function rebuild(?ITimeFactory $clock = null): void {
		Server::get(IJobList::class)->remove(ResumenJob::class);
		$this->resumen($clock)->rebuild();
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
	 * October, 900001's last month: numerador 3 + 1 + 3 = 7; denominador
	 * (10 + 5) − (1 + 2) = 12, October to September, so October's 100 never counts.
	 */
	public function testMetaIIsTheHandSumOfItsRoute(): void {
		$meses = $this->evaluate()['I']['meses'];

		self::assertCount(12, $meses);
		self::assertSame(['2099-10', 7.0, 12.0, 58.33], [$meses[9]['mes'], $meses[9]['numerador'], $meses[9]['denominador'], $meses[9]['porcentaje']]);
		// Month by month: January 4 ÷ (10 − 1) = 44.44 %; February 4 ÷ 12 = 33.33 %; March 7 ÷ 12.
		self::assertSame([[4.0, 9.0, 44.44], [4.0, 12.0, 33.33], [7.0, 12.0, 58.33]], array_map(
			static fn (array $mes): array => [$mes['numerador'], $mes['denominador'], $mes['porcentaje']],
			array_slice($meses, 0, 3),
		));
		// The country: December (2 + 1 + 1 + 3 + 10 + 1 = 18) ÷ (10 + 20 − 1 + 5 − 2 = 32) = 56.25 %; January 14 ÷ 29.
		self::assertSame([56.25, 48.28], [$meses[11]['nacional'], $meses[0]['nacional']]);
		// The CESFAM peers leave out 900003's December 1: 17 ÷ 32 = 53.13 %.
		self::assertSame(53.13, $meses[11]['pares']);
		self::assertSame(-31.67, $meses[9]['brecha_nacional'], '58.33 − 90');
		self::assertNull($meses[9]['brecha_comprometida'], 'nothing committed yet');
	}

	/**
	 * #25: only 900003 reported December, and 900001's own Serie A ends in
	 * October (its November row is blank), so its Meta I has no figure after
	 * October, while the country and the peers still reach December and the
	 * fuente's fecha de corte stays December's.
	 */
	public function testTheLocalSeriesEndsAtItsOwnLastReportedMonth(): void {
		$answer = $this->evaluator()->evaluate(2099, Server::get(EstablishmentConfig::class)->get(), ['tipo']);
		$meses = array_column($answer['indicadores'], null, 'codigo')['I']['meses'];

		self::assertSame([null, null, null], [$meses[10]['numerador'], $meses[10]['denominador'], $meses[10]['porcentaje']], 'November is past 900001\'s own last month');
		self::assertSame([null, null], [$meses[11]['numerador'], $meses[11]['porcentaje']]);
		self::assertSame([7.0, 12.0, 58.33], [$meses[9]['numerador'], $meses[9]['denominador'], $meses[9]['porcentaje']], 'October is its own');
		self::assertSame([56.25, 53.13], [$meses[11]['nacional'], $meses[11]['pares']], 'the country and the peers are unchanged');
		self::assertSame(['rem-a' => '2099-10', 'rem-p' => '2099-12'], $answer['fecha_corte_local']);
		self::assertSame('2099-12', $answer['fuentes'][0]['fecha_corte'], 'the country\'s fecha de corte stays');
	}

	/**
	 * a13, a11: Meta T is P12 col01 + col02 over FONASA's mujeres 20–69 for the
	 * corte of 31-12-2098: December 45 ÷ 350 = 12.86 %; June 40 ÷ 350 = 11.43 %.
	 */
	public function testAMetaOverWholeBandsIsItsP12CorteOverTheInscritos(): void {
		$this->fonasa('31-12-2098');

		$t = $this->evaluate()['T'];

		self::assertSame(['comprometida' => false, 'corte' => '2098-12-31', 'factor' => 1.0, 'falta_oficial' => false], $t['poblacion']);
		self::assertSame([null, null], [$t['meses'][4]['numerador'], $t['meses'][4]['porcentaje']], 'before June there is no corte of the year');
		self::assertSame([40.0, 350.0, 11.43], [$t['meses'][5]['numerador'], $t['meses'][5]['denominador'], $t['meses'][5]['porcentaje']]);
		self::assertSame([40.0, 11.43], [$t['meses'][10]['numerador'], $t['meses'][10]['porcentaje']], 'June\'s corte stands until December\'s');
		self::assertSame([45.0, 350.0, 12.86], [$t['meses'][11]['numerador'], $t['meses'][11]['denominador'], $t['meses'][11]['porcentaje']]);
		// The country: June (40 + 300) ÷ 1,350 = 25.19 %, December 45 ÷ 1,350 = 3.33 %; the CESFAM peers are the same two.
		self::assertSame([25.19, 3.33, 25.19, 3.33], [$t['meses'][5]['nacional'], $t['meses'][11]['nacional'], $t['meses'][5]['pares'], $t['meses'][11]['pares']]);
	}

	/**
	 * Every figure is official (docs/adr/0007, amendment): Meta II's mujeres
	 * 25–64 cut two bands, so it has no %, local, national or peer, until its
	 * denominador comprometido gives the local one: 45 ÷ 200 = 22.5 %.
	 */
	public function testAMetaWhoseAgesCutABandWaitsForItsOfficialDenominator(): void {
		$this->fonasa('31-12-2098');

		$ii = $this->evaluate()['II'];
		self::assertSame(['comprometida' => false, 'corte' => null, 'factor' => null, 'falta_oficial' => true], $ii['poblacion']);
		self::assertSame([45.0, null, null, null, null], [$ii['meses'][11]['numerador'], $ii['meses'][11]['denominador'], $ii['meses'][11]['porcentaje'], $ii['meses'][11]['nacional'], $ii['meses'][11]['pares']]);

		$this->commit([['codigo' => 'II', 'denominador' => 200]]);

		$committed = $this->evaluate()['II'];
		self::assertSame(['comprometida' => true, 'corte' => null, 'factor' => null, 'falta_oficial' => false], $committed['poblacion']);
		self::assertSame([200.0, 22.5], [$committed['meses'][11]['denominador'], $committed['meses'][11]['porcentaje']]);
		self::assertSame([null, null], [$committed['meses'][11]['nacional'], $committed['meses'][11]['pares']], 'the country and the peers have no official figure');
	}

	/**
	 * a11: a year with no corte of its own reads the latest earlier one × 1.03,
	 * unless a committed value is set. 350 × 1.03 = 360.5, so December is
	 * 45 ÷ 360.5 = 12.48 %; committed at 200, it is 22.5 %, and the country
	 * stays FONASA's.
	 */
	public function testAMissingCorteGrowsByThreePercentUnlessADenominatorIsCommitted(): void {
		$this->fonasa('31-12-2097');

		$grown = $this->evaluate()['T'];
		self::assertSame(['comprometida' => false, 'corte' => '2097-12-31', 'factor' => 1.03, 'falta_oficial' => false], $grown['poblacion']);
		self::assertSame([360.5, 12.48], [$grown['meses'][11]['denominador'], $grown['meses'][11]['porcentaje']]);

		$this->commit([['codigo' => 'T', 'denominador' => 200]]);

		$committed = $this->evaluate()['T'];
		self::assertSame([200.0, true], [$committed['denominador_comprometido'], $committed['poblacion']['comprometida']]);
		self::assertSame([200.0, 22.5], [$committed['meses'][11]['denominador'], $committed['meses'][11]['porcentaje']]);
		// 45 ÷ (1,350 × 1.03) = 3.24 %.
		self::assertSame(3.24, $committed['meses'][11]['nacional'], 'the country is never bent by one install\'s commitment');
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

		self::assertSame([60.0, -1.67], [$metas['I']['meta_comprometida'], $metas['I']['meses'][9]['brecha_comprometida']], '58.33 − 60');
		// Meta C: January 900001 col08 = 2, over the 8 committed = 25 %, and no country to compare.
		self::assertSame([2.0, 8.0, 25.0, -5.0, null, null], [
			$metas['C']['meses'][0]['numerador'], $metas['C']['meses'][0]['denominador'], $metas['C']['meses'][0]['porcentaje'],
			$metas['C']['meses'][0]['brecha_comprometida'], $metas['C']['meses'][0]['nacional'], $metas['C']['meses'][0]['pares'],
		]);
		self::assertSame([true, 100.0, [], null], [$metas['VIII']['manual'], $metas['VIII']['cumplimiento'], $metas['VIII']['meses'], $metas['VIII']['poblacion']]);

		// With no FONASA corte, Meta T has no denominator anywhere: no % and no national one.
		self::assertSame([null, null, null], [$metas['T']['meses'][11]['porcentaje'], $metas['T']['meses'][11]['nacional'], $metas['T']['meses'][11]['pares']]);
		self::assertSame([null, false], [$metas['T']['poblacion']['corte'], $metas['T']['poblacion']['falta_oficial']]);
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
	 * docs/adr/0009: each Resumen card is its meta's last month with a figure,
	 * with the N, D and % GET indicadores serves for that month's local series.
	 * Meta I is October, 7 de 12 = 58.33 %: November's row has no written
	 * number, so November has no figure, never 0. It cumple the 50 committed,
	 * though not the nacional 90. Meta T is December, 45 de 350 = 12.86 %,
	 * under its nacional 80. Meta II names its missing denominador. Meta VIII
	 * is its cumplimiento informado, which reaches its nacional 100. Each
	 * card's datos al is 900001's own last month in its meta's serie: October
	 * in REM-A, December in REM-P; a manual meta has none.
	 */
	public function testEachResumenCardIsItsMetasLastMonthWithAFigure(): void {
		$this->fonasa('31-12-2098');
		$this->commit([['codigo' => 'I', 'meta' => 50], ['codigo' => 'VIII', 'cumplimiento' => 100]]);

		$response = $this->api()->resumen();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$resumen = $response->getData();
		self::assertSame([2099, '900001'], [$resumen['anio'], $resumen['establecimiento']], 'the newest metas, for this install\'s establishment');
		$cards = array_column($resumen['metas'], null, 'codigo');
		self::assertSame(['2099-10', 7.0, 12.0, 58.33, true, null], self::card($cards['I']));
		self::assertSame(['2099-12', 45.0, 350.0, 12.86, false, null], self::card($cards['T']));
		self::assertSame([null, null, null, null, null, 'falta el denominador oficial de 2099'], self::card($cards['II']));
		self::assertSame([null, null, null, 100.0, true, null], self::card($cards['VIII']));
		self::assertSame([null, null, null, null, null, null], self::card($cards['C']), 'no commitment typed, so no denominador');
		self::assertSame(['I' => '2099-10', 'II' => '2099-12', 'T' => '2099-12', 'C' => '2099-10', 'VIII' => null], array_column($resumen['metas'], 'datos_al', 'codigo'));

		$indicadores = array_column($this->api()->indicadores(null, '')->getData()['indicadores'], null, 'codigo');
		foreach ($resumen['metas'] as $card) {
			$meses = array_column($indicadores[$card['codigo']]['meses'], null, 'mes');
			$figures = static fn (array $mes): array => [$mes['numerador'], $mes['denominador'], $mes['porcentaje']];
			if ($card['mes'] === null) {
				self::assertSame([], array_filter(array_map(static fn (array $mes): ?float => $mes['porcentaje'], $meses)), "Meta {$card['codigo']}: no month of its local series has a %");
				continue;
			}
			self::assertSame($figures($meses[$card['mes']]), $figures($card), "Meta {$card['codigo']}: the card is GET indicadores' local {$card['mes']}");
		}
	}

	/**
	 * docs/adr/0009: a meta with a ruta the OOTT have not confirmed shows
	 * «esperando ruta oficial» and no figure; a ruta marked oficial reads as
	 * one without the key.
	 */
	public function testAMetaWhoseRutaIsNotOficialShowsNoFigure(): void {
		$seed = self::seed();
		$seed['indicadores'][0]['denominador']['oficial'] = false;
		$seed['indicadores'][2]['numerador']['oficial'] = true;
		$this->goals()->store($seed);
		$this->fonasa('31-12-2098');

		$cards = array_column($this->api()->resumen()->getData()['metas'], null, 'codigo');

		self::assertSame([null, null, null, null, null, 'esperando ruta oficial'], self::card($cards['I']));
		self::assertSame(['2099-12', 45.0, 350.0, 12.86, false, null], self::card($cards['T']));
	}

	/**
	 * suite ADR-0009: another establishment's Resumen, named by its DEIS code.
	 * 900002's Meta I is January, its own last month: 10 de 20 = 50 %, against
	 * the nacional 90, since this install's commitments are not its own.
	 */
	public function testTheResumenOfAnotherEstablishment(): void {
		$this->commit([['codigo' => 'I', 'meta' => 40]]);

		$other = $this->api()->resumen('900002')->getData();

		self::assertSame('900002', $other['establecimiento']);
		$i = array_column($other['metas'], null, 'codigo')['I'];
		self::assertSame([null, ['2099-01', 10.0, 20.0, 50.0, false, null]], [$i['meta_comprometida'], self::card($i)]);
	}

	/**
	 * docs/adr/0009: the histórico asks the newest metas over each earlier
	 * year of the ventana, every periodo moved by whole years. Meta I 2098
	 * counts its numerador over 2098 and its denominador from October 2097 to
	 * September 2098: 4 + 6 = 10, so 2098-10's +10 (2099's window) never
	 * counts. January 2098 has no numerador yet, sin informar; February
	 * 5 ÷ 4 = 125 %; from March 5 ÷ 10 = 50 %, its cierre. Meta T is Serie
	 * P, drawn at its cortes: 2098's December 35 ÷ 350 = 10 %, over the
	 * corte of 31-12-2097. 2099 is not over, so it has no cierre yet, and the
	 * card is its October.
	 */
	public function testTheHistoricoMovesEachPeriodoByWholeYears(): void {
		$this->fonasa('31-12-2097');
		$this->fonasa('31-12-2098');

		$cards = array_column($this->api()->resumen()->getData()['metas'], null, 'codigo');

		$i = self::historico($cards['I']);
		self::assertSame([2096, 2097, 2098, 2099], array_keys($i), 'the ventana, oldest first, the current year last');
		$i2098 = array_column($i[2098]['meses'], null, 'mes');
		self::assertCount(12, $i2098);
		self::assertSame([[null, 4.0, null], [5.0, 4.0, 125.0], [5.0, 10.0, 50.0], [5.0, 10.0, 50.0]], array_map(
			static fn (string $mes): array => [$i2098[$mes]['numerador'], $i2098[$mes]['denominador'], $i2098[$mes]['porcentaje']],
			['2098-01', '2098-02', '2098-03', '2098-12'],
		));
		self::assertSame([null, 50.0, null], [$i[2098]['estado'], $i[2098]['cierre'], $i[2099]['cierre']]);
		self::assertSame([7.0, 12.0, 58.33], array_values(array_intersect_key(array_column($i[2099]['meses'], null, 'mes')['2099-10'], array_flip(['numerador', 'denominador', 'porcentaje']))), 'the card\'s own October');

		$t = self::historico($cards['T']);
		self::assertSame([['2098-06', null, 350.0, null], ['2098-12', 35.0, 350.0, 10.0]], array_map(
			static fn (array $mes): array => [$mes['mes'], $mes['numerador'], $mes['denominador'], $mes['porcentaje']],
			$t[2098]['meses'],
		));
		self::assertSame(10.0, $t[2098]['cierre']);
		self::assertSame([], self::historico($cards['VIII']), 'a manual meta has no figures to draw');
	}

	/**
	 * docs/adr/0009: a click on any figure shows where it comes from, as the
	 * stored Resumen keeps it. Each month carries its origen and the fecha de
	 * corte of its year's load, the card's own month too. Each year of the
	 * histórico carries the stored ruta, its periodos moved onto that year and
	 * each código × columna labelled by that year's diccionario as POST
	 * consultas labels the same consulta: 2098's in capitals. A denominador of
	 * FONASA's inscritos is the piv; one only a commitment gives has no ruta.
	 */
	public function testEachFigureCarriesItsSource(): void {
		$this->rebuild();
		$resumen = $this->api()->resumen()->getData();

		self::assertSame('GoalsTest', $resumen['referencia']);
		$cards = array_column($resumen['metas'], null, 'codigo');
		self::assertSame(['2099-10', 'datos públicos DEIS', '2099-12'], [$cards['I']['mes'], $cards['I']['origen'], $cards['I']['fecha_corte']], 'October comes from the 2099 zip, which 900003 cut in December');
		self::assertSame([null, null], [$cards['VIII']['origen'], $cards['VIII']['fecha_corte']], 'a manual meta has no month');
		$i = self::historico($cards['I']);
		$february = array_column($i[2098]['meses'], null, 'mes')['2098-02'];
		self::assertSame([125.0, 'datos públicos DEIS', '2098-12'], [$february['porcentaje'], $february['origen'], $february['fecha_corte']], 'a 2098 month comes from the 2098 zip');

		$numerador = $i[2099]['ruta']['numerador'];
		self::assertSame(['rem-a', 'REM Serie A', '2099-01', '2099-12', []], [$numerador['fuente'], $numerador['nombre'], $numerador['desde'], $numerador['hasta'], $numerador['restar']], 'named as GET fuentes names it');
		foreach (array_column($this->goals()->get(2099)['indicadores'], 'ruta', 'codigo')['I'] as $part => $stored) {
			$shown = $i[2099]['ruta'][$part];
			self::assertSame([$stored['periodo']['desde'], $stored['periodo']['hasta']], [$shown['desde'], $shown['hasta']], "the $part's periodo is the stored ruta's");
			foreach (['sumar', 'restar'] as $sign) {
				$labelled = array_map(fn (array $term): array => $this->api()->consultas(['fuente' => $stored['fuente'], 'periodo' => $stored['periodo'], 'filtros' => $term])->getData()['etiquetas']['codigos'], $stored[$sign] ?? []);
				self::assertSame($labelled, $shown[$sign], "the $part's $sign is the stored ruta's, labelled as the same consulta");
			}
		}
		$first = $numerador['sumar'][0][0];
		self::assertSame(['A12', 'SECCIÓN A: SECCIÓN DE PRUEBA', 'Evaluación de recuperación', 'Niños de 12 a 17 meses'], [$first['hoja'], $first['seccion'], $first['prestacion'], $first['columnas']['col08']]);
		$moved = $i[2098]['ruta'];
		self::assertSame('EVALUACIONES DE RECUPERACION', $moved['numerador']['sumar'][0][0]['prestacion'], 'by 2098\'s own diccionario');
		self::assertSame(
			['2097-10', '2098-09', ['02010321'], ['03500331', '03500334']],
			[$moved['denominador']['desde'], $moved['denominador']['hasta'], array_column($moved['denominador']['sumar'][0], 'codigo'), array_column($moved['denominador']['restar'][0], 'codigo')],
			'Meta I\'s window, moved onto 2098',
		);

		self::assertSame(['fuente' => 'piv', 'nombre' => 'Población inscrita validada (FONASA)', 'desde' => null, 'hasta' => null, 'sumar' => [], 'restar' => []], self::historico($cards['T'])[2099]['ruta']['denominador']);
		self::assertNull(self::historico($cards['C'])[2099]['ruta']['denominador'], 'only a commitment gives it');
	}

	/**
	 * docs/adr/0009: a year is comparable only when its own diccionario gives
	 * every código × columna of the ruta the current year's label, once case,
	 * accents and plurals are folded. 2098's capitals and plurals compare;
	 * 2097 borrows 2098's labels, so it never does; and Meta I's 2096 would
	 * need October 2095, outside the ventana. Relabelling one of Meta I's
	 * códigos in 2098 drops Meta I's 2098, and leaves Meta C's.
	 */
	public function testAYearIsComparableOnlyWithItsOwnDiccionarioAndTheSameLabels(): void {
		$estados = fn (): array => array_map(
			static fn (array $card): array => array_map(static fn (array $year): ?string => $year['estado'], self::historico($card)),
			array_column($this->api()->resumen()->getData()['metas'], null, 'codigo'),
		);
		$none = 'sin datos comparables';

		$before = $estados();
		self::assertSame([2096 => $none, 2097 => $none, 2098 => null, 2099 => null], $before['I']);
		self::assertSame([2096 => null, 2097 => $none, 2098 => null, 2099 => null], $before['C']);
		$i2096 = self::historico(array_column($this->api()->resumen()->getData()['metas'], null, 'codigo')['I'])[2096];
		self::assertSame([[], null], [$i2096['meses'], $i2096['cierre']], 'nothing is shown for a year that does not compare');

		[[$year, $serie, $rows]] = array_values(array_filter(self::loads(), static fn (array $load): bool => $load[0] === 2098 && $load[1] === 'A'));
		$this->load($year, $serie, $rows, ['03500331' => 'Derivación a especialidad'] + self::FOLDED_A);

		$after = $estados();
		self::assertSame($none, $after['I'][2098]);
		self::assertNull($after['C'][2098], 'Meta C names no relabelled código');
	}

	/**
	 * docs/adr/0009, ADR-0007 amendment: Meta II's ages cut a PIV band, so a
	 * past year shows its figures only where that year's denominador
	 * comprometido is typed, and names the year it is missing.
	 */
	public function testAPastYearOverPeopleWaitsForThatYearsOfficialDenominator(): void {
		$ii = self::historico(array_column($this->api()->resumen()->getData()['metas'], null, 'codigo')['II']);

		self::assertSame(['falta el denominador oficial de 2098', 'falta el denominador oficial de 2099'], [$ii[2098]['estado'], $ii[2099]['estado']]);
		self::assertSame([35.0, null], [$ii[2098]['meses'][1]['numerador'], $ii[2098]['meses'][1]['porcentaje']]);
	}

	/**
	 * #48, CONTEXT.md (Cierre, Sin informar): a Serie P point of the
	 * histórico is that month's own corte. 2098 reported June (21) and no
	 * December: Meta T's June is 21 ÷ 350 = 6 %, its December is sin
	 * informar rather than June's corte again, and the year has no cierre.
	 */
	public function testAPastYearWithoutItsDecemberCorteHasNoCierre(): void {
		$this->fonasa('31-12-2097');
		$this->load(2098, 'P', ZipFixture::row(2098, 6, '900001', 'P1206010', [1 => '21']), self::LABELS_P);

		$t = self::historico(array_column($this->api()->resumen()->getData()['metas'], null, 'codigo')['T'])[2098];

		self::assertSame([['2098-06', 21.0, 350.0, 6.0], ['2098-12', null, 350.0, null]], array_map(
			static fn (array $mes): array => [$mes['mes'], $mes['numerador'], $mes['denominador'], $mes['porcentaje']],
			$t['meses'],
		));
		self::assertNull($t['cierre']);
	}

	/**
	 * docs/adr/0009: the newest metas may come before any figures of their
	 * year (2100's, in October). The Resumen's current year is then the
	 * latest year with figures, 2099, with the 2100 rutas moved onto it.
	 */
	public function testNewerMetasAreCountedOverTheLatestYearWithFigures(): void {
		$this->goals()->store(self::seed(2100));

		$resumen = $this->api()->resumen()->getData();

		self::assertSame([2100, 'Metas de prueba 2100'], [$resumen['anio'], $resumen['nombre']]);
		$i = array_column($resumen['metas'], null, 'codigo')['I'];
		self::assertSame(['2099-10', 7.0, 12.0, 58.33, false, null], self::card($i));
		self::assertSame([2096, 2097, 2098, 2099], array_keys(self::historico($i)));
		self::assertSame('falta el denominador oficial de 2099', array_column($resumen['metas'], null, 'codigo')['II']['estado']);
	}

	/**
	 * #50: the shipped 2027 metas (Res. Exenta 732), as the newest, are the
	 * Resumen's «Meta … 2027» cards, counted over 2026, the latest year with
	 * figures: Meta I's datos al is 900001's own October 2026. Every REM meta
	 * waits for its ruta oficial, the OOTT 2027's, and shows no figure and
	 * no histórico; Meta VIII is its cumplimiento informado.
	 */
	public function testTheShipped2027MetasWaitForTheirRutasOficiales(): void {
		$this->db()->executeStatement('DELETE FROM *PREFIX*estadistica_goal WHERE anio = 2099');
		$this->goals()->store(self::shipped(2027));
		$this->goals()->commit(2027, [['codigo' => 'VIII', 'cumplimiento' => 100]]);
		$this->load(2026, 'A', ZipFixture::row(2026, 10, '900001', '02010420', [8 => '1']), null);

		try {
			$resumen = $this->api()->resumen()->getData();
		} finally {
			foreach (['estadistica_rem', 'estadistica_ingest', 'estadistica_code', 'estadistica_dictionary'] as $table) {
				$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE anio = 2026 AND serie = 'A'");
			}
		}

		self::assertSame([2027, 'Metas Sanitarias de Atención Primaria 2027 (Ley 19.813)'], [$resumen['anio'], $resumen['nombre']]);
		$cards = array_column($resumen['metas'], null, 'codigo');
		self::assertSame(['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'], array_keys($cards));
		self::assertSame('2026-10', $cards['I']['datos_al'], 'counted over 2026, the latest year with figures');
		foreach (['I', 'II', 'III', 'IV', 'V', 'VI', 'VII'] as $codigo) {
			self::assertSame([null, null, null, null, null, 'esperando ruta oficial', []], [...self::card($cards[$codigo]), $cards[$codigo]['historico']], "Meta $codigo 2027");
		}
		self::assertSame([true, null, null, null, 100.0, true, null], [$cards['VIII']['manual'], ...self::card($cards['VIII'])]);
	}

	/**
	 * @param array<string, mixed> $card
	 * @return array<int, array<string, mixed>> a card's histórico, by year
	 */
	private static function historico(array $card): array {
		return array_column($card['historico'], null, 'anio');
	}

	/**
	 * docs/adr/0009, computed once per change: a committed REM load, an admin
	 * write of the commitments or of the typed population (the PIV, the
	 * denominador of the metas that count people) and a new metas seed each
	 * queue one rebuild in the background work. FONASA's file, a refused
	 * write and a reading queue none.
	 */
	public function testEachChangeQueuesOneRebuildAndNothingElseDoes(): void {
		self::assertSame(1, self::queued(), 'the REM loads and the seed of setUp wait as one rebuild');
		$this->rebuild();

		$this->fonasa('31-12-2098');
		$this->admin()->goals(2099, [['codigo' => 'IX', 'meta' => 50]]);
		$this->admin()->piv(2098, [['edad' => 25, 'sexo' => 'M', 'inscritos' => 1]]);
		$this->api()->resumen();
		$this->api()->resumen('900002');
		self::assertSame(0, self::queued(), 'FONASA\'s file, a refused write and a reading are not changes the Resumen waits for');

		$seed = self::seed();
		$seed['version'] = 2;
		foreach ([
			'a REM load' => fn () => Server::get(RemImporter::class)->import(new LocalRange(ZipFixture::zip(['SerieA2097.csv' => ZipFixture::header() . ZipFixture::row(2097, 3, '900001', '02010420', [11 => '4'])])), 2097, 'A', IngestState::ORIGIN_FILE),
			'an admin write' => fn () => $this->admin()->goals(2099, [['codigo' => 'I', 'meta' => 50]]),
			'a typed population' => fn () => $this->admin()->piv(2098, [['edad' => 20, 'sexo' => 'M', 'inscritos' => 120]]),
			'a new metas seed' => fn () => $this->goals()->store($seed),
		] as $change => $make) {
			$make();
			self::assertSame(1, self::queued(), "$change queues one rebuild");
			$this->rebuild();
		}
	}

	/**
	 * docs/adr/0009: once stored, every reader sees the same Resumen until a
	 * change rebuilds it. FONASA's file gives Meta T its 350 mujeres, but the
	 * answer stays the stored one; the next rebuild, after the commitment,
	 * reads both.
	 */
	public function testTheStoredResumenStaysUntilAChangeRebuildsIt(): void {
		$this->rebuild();
		$stored = $this->api()->resumen()->getData();
		self::assertSame([null, null, null, null, null, null], self::card(array_column($stored['metas'], null, 'codigo')['T']), 'no corte yet, so no denominador');

		$this->fonasa('31-12-2098');
		self::assertSame($stored, $this->api()->resumen()->getData(), 'FONASA\'s file alone does not move the stored Resumen');
		$this->commit([['codigo' => 'I', 'meta' => 50]]);
		self::assertSame($stored, $this->api()->resumen()->getData(), 'the rebuild runs in the background work, not in the request');

		$this->rebuild();
		$cards = array_column($this->api()->resumen()->getData()['metas'], null, 'codigo');
		self::assertSame(['2099-12', 45.0, 350.0, 12.86, false, null], self::card($cards['T']));
		self::assertSame(50.0, $cards['I']['meta_comprometida']);
	}

	/**
	 * docs/adr/0009: two builds from the same inputs store the same bytes and
	 * the same fingerprint. The build time sits beside the document, in the
	 * admin section, and never in it.
	 */
	public function testTheSameInputsGiveTheSameDocument(): void {
		$stored = fn (): array => $this->db()->executeQuery('SELECT document, fingerprint FROM *PREFIX*estadistica_resumen WHERE estab = 900001')->fetchAll();
		$this->rebuild();
		$first = $stored();

		$this->rebuild($this->at(gmmktime(13, 0, 0, 12, 15, 2099)));

		self::assertCount(1, $first);
		self::assertSame($first, $stored());
		self::assertSame(['builtAt' => gmmktime(13, 0, 0, 12, 15, 2099), 'error' => null], $this->admin()->ingests()->getData()['resumen']);
	}

	/**
	 * A rebuild that fails keeps the stored Resumen serving, and the admin
	 * section shows why, in Spanish, until a rebuild succeeds. Here the
	 * metas themselves are refused: on a clock in 2097, 2099 cannot be a
	 * metas year.
	 */
	public function testAFailedRebuildKeepsTheStoredResumenAndSaysWhy(): void {
		$this->rebuild();
		$stored = $this->api()->resumen()->getData();
		$this->fonasa('31-12-2098');

		$this->rebuild($this->at(gmmktime(12, 0, 0, 12, 15, 2097)));

		self::assertSame($stored, $this->api()->resumen()->getData());
		self::assertSame(['builtAt' => gmmktime(12, 0, 0, 12, 15, 2099), 'error' => 'El año de las metas (anio) debe estar entre 2009 y 2098.'], $this->admin()->ingests()->getData()['resumen']);

		$this->rebuild();
		self::assertNull($this->admin()->ingests()->getData()['resumen']['error'], 'a rebuild that succeeds clears the reason');
		self::assertNotSame($stored, $this->api()->resumen()->getData());
	}

	/**
	 * suite ADR-0009: another establishment's Resumen is built on each
	 * request and never stored. FONASA's file reaches 900002's Meta T at
	 * once: its June 300 over its 1,000 mujeres.
	 */
	public function testAnotherEstablishmentIsBuiltOnRequestAndNeverStored(): void {
		$this->rebuild();
		$t = static fn (array $resumen): array => array_column($resumen['metas'], null, 'codigo')['T'];
		self::assertNull($t($this->api()->resumen('900002')->getData())['porcentaje']);

		$this->fonasa('31-12-2098');

		$other = $t($this->api()->resumen('900002')->getData());
		self::assertSame([300.0, 1000.0, 30.0], [$other['numerador'], $other['denominador'], $other['porcentaje']]);
		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_resumen WHERE estab = 900002')->fetchOne());
	}

	/**
	 * Only the configured establishment's Resumen is stored. A rebuild for
	 * 900002 drops 900001's, so switching back to 900001 builds its Resumen
	 * on request, with what changed meanwhile (FONASA's file gives Meta T its
	 * 350 mujeres), never its stale stored one. Without an establishment, a
	 * rebuild drops every row.
	 */
	public function testOnlyTheConfiguredEstablishmentsResumenIsStored(): void {
		$this->rebuild();
		$this->provision(EstablishmentConfig::KEY_DEIS, '900002');
		$this->fonasa('31-12-2098');
		$this->rebuild();

		$this->provision(EstablishmentConfig::KEY_DEIS, '900001');

		$t = array_column($this->api()->resumen()->getData()['metas'], null, 'codigo')['T'];
		self::assertSame(['2099-12', 45.0, 350.0, 12.86, false, null], self::card($t));
		self::assertNull($this->resumen()->status(), 'nothing stored for 900001');

		$this->provision(EstablishmentConfig::KEY_DEIS, '');
		$this->rebuild();
		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_resumen')->fetchOne());
	}

	/** @param array<string, mixed> $card @return list<mixed> what a Resumen card shows: mes, N, D, %, cumple and estado */
	private static function card(array $card): array {
		return [$card['mes'], $card['numerador'], $card['denominador'], $card['porcentaje'], $card['cumple'], $card['estado']];
	}

	/** The OCS routes, on this test's clock and fixtures; the wiring is smoke's (scripts/smoke.sh). */
	private function api(): ApiController {
		return new ApiController(
			Server::get(IRequest::class),
			Server::get(Sources::class),
			Server::get(QueryExecutor::class),
			Server::get(EstablishmentConfig::class),
			$this->window(),
			Server::get(SavedContent::class),
			$this->evaluator(),
			$this->resumen(),
		);
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
			'a meta the year lacks' => [2099, [['codigo' => 'IX', 'meta' => 50]], 'Cada compromiso nombra una meta de 2099: I, II, T, C, VIII.'],
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
	 * a9: the app ships 2026's and 2027's metas, loaded when it was enabled;
	 * running the step again changes nothing, and a year left without them is
	 * loaded anew.
	 */
	public function testTheShippedMetasAreLoadedOnceAndAgainWhenMissing(): void {
		$goals = Server::get(Goals::class);
		$shipped = self::shipped(2026);
		if ($goals->version(2026) !== $shipped['version'] || $goals->version(2027) !== self::shipped(2027)['version']) {
			self::markTestSkipped('this instance holds metas of another version than the shipped files; run the integration suite on a throwaway instance');
		}
		self::assertSame(['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII'], array_column($goals->get(2027)['indicadores'], 'codigo'), 'Res. Exenta 732\'s');
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
		if (Server::get(Goals::class)->version($next) !== (self::shipped($next)['version'] ?? null)) {
			self::markTestSkipped("this instance already holds metas for $next other than the shipped ones");
		}
		$this->putInFiles('admin', self::DROPPED, ZipFixture::file(json_encode(self::seed($next), JSON_THROW_ON_ERROR)));
		$this->droppedYear = $next;

		$response = $this->admin()->drop('/' . self::DROPPED);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['metas' => ['anio' => $next, 'version' => 1, 'indicadores' => 5]], $response->getData());
		self::assertSame(['I', 'II', 'T', 'C', 'VIII'], array_column(Server::get(Goals::class)->get($next)['indicadores'], 'codigo'));
		self::assertContains($next, array_column($this->admin()->ingests()->getData()['metas']['years'], 'anio'), 'the section lists the new year');

		// A second file for the year replaces the first whole.
		$corrected = self::seed($next);
		$corrected['version'] = 2;
		array_pop($corrected['indicadores']);
		Server::get(IRootFolder::class)->getUserFolder('admin')->get(self::DROPPED)->delete();
		$this->putInFiles('admin', self::DROPPED, ZipFixture::file(json_encode($corrected, JSON_THROW_ON_ERROR)));
		self::assertSame(['metas' => ['anio' => $next, 'version' => 2, 'indicadores' => 4]], $this->admin()->drop('/' . self::DROPPED)->getData());
		self::assertSame(['I', 'II', 'T', 'C'], array_column(Server::get(Goals::class)->get($next)['indicadores'], 'codigo'));
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

	/** The admin section's controller, acting for admin through the same door; the PIV's year window on this test's clock. */
	private function admin(): AdminController {
		return new AdminController(
			Server::get(IRequest::class),
			Server::get(YearWindow::class),
			$this->dropsFor('admin'),
			Server::get(IngestStateMapper::class),
			Server::get(DeisRepository::class),
			Server::get(Catalog::class),
			Server::get(Register::class),
			new PivImporter($this->db(), Server::get(EstablishmentConfig::class), $this->window(), $this->clock, Server::get(IJobList::class)),
			$this->goals(),
			$this->planillasFor('admin'),
			$this->resumen(),
		);
	}
}
