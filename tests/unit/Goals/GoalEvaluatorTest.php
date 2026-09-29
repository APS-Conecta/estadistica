<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Goals;

use OCA\Estadistica\Goals\GoalEvaluator;
use OCA\Estadistica\Goals\GoalSeed;
use OCA\Estadistica\Query\QuerySpec;
use PHPUnit\Framework\TestCase;

/**
 * The metas' month rules (acceptance a12), in bare PHP: what a month shows for
 * a Serie A route and a Serie P one, how a route's terms add up, how people are
 * counted from FONASA's bands, and a whole meta from its routes' answers.
 * Every expected figure is worked out by hand in the comment beside it.
 */
final class GoalEvaluatorTest extends TestCase {
	/** @return list<array{grupo: list<string>, valor: float, informantes: int}> */
	private static function points(array $byMonth): array {
		$points = [];
		foreach ($byMonth as $mes => $valor) {
			$points[] = ['grupo' => [$mes], 'valor' => (float)$valor, 'informantes' => 1];
		}

		return $points;
	}

	/** Meta I's denominator: October to September, month by month, and nothing past the data. */
	public function testASerieARouteAddsUpToEachMonthWithinItsPeriodo(): void {
		$points = self::points(['2025-09' => 1000, '2025-10' => 4, '2025-12' => 1, '2026-02' => 3, '2026-05' => 2, '2026-10' => 100]);

		// Jan: 4 + 1; Feb: + 3; May: + 2. Figures reach August, so September has none yet.
		self::assertSame(
			[5.0, 8.0, 8.0, 8.0, 10.0, 10.0, 10.0, 10.0, null, null, null, null],
			GoalEvaluator::monthly($points, 'A', 202510, 202609, 2026, 12, 202608),
		);
		// With December loaded the window still stops in September: October's 100 never counts, nor 2025-09's 1000.
		self::assertSame(
			[5.0, 8.0, 8.0, 8.0, 10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 10.0, 10.0],
			GoalEvaluator::monthly($points, 'A', 202510, 202609, 2026, 12, 202612),
		);
		self::assertSame([null, null, null], GoalEvaluator::monthly([], 'A', 202601, 202612, 2026, 3, 202603), 'nothing reported is no figure, not 0');
		self::assertSame([null, null], GoalEvaluator::monthly($points, 'A', 202510, 202609, 2026, 2, null), 'a meta year not loaded has no figure');
	}

	/** Serie P is a corte: June's until December's, and nothing before June. */
	public function testASeriePRouteShowsTheLatestCorte(): void {
		$points = self::points(['2025-12' => 50, '2026-06' => 7, '2026-12' => 9]);

		self::assertSame(
			[null, null, null, null, null, 7.0, 7.0, 7.0, 7.0, 7.0, 7.0, 9.0],
			GoalEvaluator::monthly($points, 'P', 202601, 202612, 2026, 12, 202603),
			'the previous year\'s December corte is not this year\'s progress, and Serie A\'s fecha de corte does not bound Serie P',
		);
	}

	public function testATermsSumsAndTakesAway(): void {
		// Month 1: 1 − 1; month 2: nothing added; month 3: 3 + 1, and a restar with no figure takes nothing.
		self::assertSame([0.0, null, 4.0], GoalEvaluator::combine([[1.0, null, 3.0], [null, null, 1.0]], [[1.0, 1.0, null]], 3));
		self::assertSame([2.0], GoalEvaluator::combine([[2.0]], [], 1));
	}

	public function testPercentAndGapInPoints(): void {
		self::assertSame(75.0, GoalEvaluator::percent(3.0, 4.0));
		self::assertSame(33.33, GoalEvaluator::percent(1.0, 3.0));
		self::assertNull(GoalEvaluator::percent(1.0, 0.0), 'no one to count against is no %');
		self::assertNull(GoalEvaluator::percent(null, 4.0));
		self::assertNull(GoalEvaluator::percent(3.0, null));
		self::assertSame(-15.0, GoalEvaluator::gap(75.0, 90.0));
		self::assertNull(GoalEvaluator::gap(null, 90.0));
		self::assertNull(GoalEvaluator::gap(75.0, null), 'no meta comprometida is no gap to it');
	}

	public function testTheMonthsThatHavePassed(): void {
		self::assertSame([12, 9, 0], [GoalEvaluator::months(2025, 2026, 9), GoalEvaluator::months(2026, 2026, 9), GoalEvaluator::months(2027, 2026, 9)]);
		self::assertSame([2026, 2027, null], [
			GoalEvaluator::defaultYear([2027, 2026], 2026),
			GoalEvaluator::defaultYear([2027], 2026),
			GoalEvaluator::defaultYear([], 2026),
		]);
	}

	/** IV.A's shape: each group counted by the band rule, times its prevalence, then summed. */
	public function testPeopleAreCountedByGroupAndPrevalence(): void {
		$inscritos = ['corte' => '2025-12-31', 'factor' => 1.0, 'manual' => false, 'bands' => [10 => ['M' => 100, 'H' => 100], 20 => ['M' => 300, 'H' => 100]]];

		// 15–24: half of 10–19 (100) + half of 20–29 (200) = 300, × 10 % = 30.
		// 25 and over: half of 20–29 = 200, × 50 % = 100.
		self::assertSame(['valor' => 130.0, 'prorrateado' => true], GoalEvaluator::people([
			['desde' => 15, 'hasta' => 24, 'sexo' => null, 'prevalencia' => 10.0],
			['desde' => 25, 'hasta' => null, 'sexo' => null, 'prevalencia' => 50.0],
		], $inscritos));
		// Mujeres 20–29, whole: 300, grown × 1.03 when the corte is a year old.
		self::assertSame(['valor' => 309.0, 'prorrateado' => false], GoalEvaluator::people(
			[['desde' => 20, 'hasta' => 29, 'sexo' => 'M', 'prevalencia' => 100.0]],
			['factor' => 1.03] + $inscritos,
		));
		self::assertNull(GoalEvaluator::people([['desde' => 0, 'hasta' => 9, 'sexo' => null, 'prevalencia' => 100.0]], null));
	}

	/** @return array<string, mixed> one year of three metas: a REM one, a people one and a manual one */
	private static function metas(): array {
		return GoalSeed::parse([
			'anio' => 2026,
			'version' => 1,
			'nombre' => 'Prueba',
			'referencia' => 'Prueba',
			'diccionarios' => ['rem-a' => 'SA', 'rem-p' => 'SP'],
			'indicadores' => [
				[
					'codigo' => 'I', 'nombre' => 'Uno', 'indicador' => 'Uno', 'meta_nacional' => 90, 'peso' => 40,
					'numerador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => '2026-01', 'hasta' => '2026-12'], 'sumar' => [['codigos' => ['N'], 'columnas' => ['col01']]]],
					'denominador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => '2025-10', 'hasta' => '2026-09'], 'sumar' => [['codigos' => ['D'], 'columnas' => ['col01']]], 'restar' => [['codigos' => ['R'], 'columnas' => ['col01']]]],
				],
				[
					'codigo' => 'II', 'nombre' => 'Dos', 'indicador' => 'Dos', 'meta_nacional' => 80, 'peso' => 40,
					'numerador' => ['fuente' => 'rem-p', 'periodo' => ['desde' => '2026-01', 'hasta' => '2026-12'], 'sumar' => [['codigos' => ['P'], 'columnas' => ['col01']]]],
					'denominador' => ['poblacion' => [['desde' => 20, 'hasta' => 29, 'sexo' => 'mujeres']]],
				],
				['codigo' => 'VIII', 'nombre' => 'Ocho', 'indicador' => 'Ocho', 'meta_nacional' => 100, 'peso' => 20, 'manual' => true],
			],
		], 2026);
	}

	/**
	 * @param array<string, mixed> $part
	 * @param array<string, array<string, ?list<mixed>>> $byCode código → scope → points
	 * @return array<int, array<string, ?list<mixed>>>
	 */
	private static function answers(array $part, array $byCode): array {
		$answers = [];
		foreach ([...$part['sumar'], ...$part['restar']] as $spec) {
			self::assertInstanceOf(QuerySpec::class, $spec);
			$answers[spl_object_id($spec)] = $byCode[$spec->codigos[0]];
		}

		return $answers;
	}

	/** a12: a REM meta, month by month, local beside the country; no peers were asked. */
	public function testARemMetaMonthByMonth(): void {
		$meta = self::metas()['indicadores'][0];
		$answers = self::answers($meta['numerador'], ['N' => ['local' => self::points(['2026-01' => 3, '2026-02' => 3]), 'nacional' => self::points(['2026-01' => 30, '2026-02' => 60]), 'pares' => null]])
			+ self::answers($meta['denominador'], [
				'D' => ['local' => self::points(['2025-11' => 10]), 'nacional' => self::points(['2025-11' => 200]), 'pares' => null],
				'R' => ['local' => self::points(['2025-11' => 2]), 'nacional' => [], 'pares' => null],
			]);

		$indicator = GoalEvaluator::indicator($meta, ['meta' => 70.0, 'denominador' => null, 'cumplimiento' => null], $answers, [], true, 2026, 2, 202602);

		// January: 3 ÷ (10 − 2) = 37.5 %; February: 6 ÷ 8 = 75 %. The country: 30 ÷ 200, then 90 ÷ 200.
		self::assertSame([
			['mes' => '2026-01', 'numerador' => 3.0, 'denominador' => 8.0, 'porcentaje' => 37.5, 'brecha_comprometida' => -32.5, 'brecha_nacional' => -52.5, 'nacional' => 15.0, 'pares' => null],
			['mes' => '2026-02', 'numerador' => 6.0, 'denominador' => 8.0, 'porcentaje' => 75.0, 'brecha_comprometida' => 5.0, 'brecha_nacional' => -15.0, 'nacional' => 45.0, 'pares' => null],
		], $indicator['meses']);
		self::assertSame([70.0, null, null, null], [$indicator['meta_comprometida'], $indicator['cumplimiento'], $indicator['denominador_comprometido'], $indicator['poblacion']]);
		self::assertSame('rem-a', $indicator['numerador']['fuente'], 'the route is served as the seed wrote it');
	}

	/** a11, a12: a people meta. A committed denominator wins for the local series; the country and the peers stay FONASA's. */
	public function testAPeopleMetaTakesTheCommittedDenominatorLocallyOnly(): void {
		$meta = self::metas()['indicadores'][1];
		$answers = self::answers($meta['numerador'], ['P' => [
			'local' => self::points(['2026-06' => 40]),
			'nacional' => self::points(['2026-06' => 3000]),
			'pares' => self::points(['2026-06' => 500]),
		]]);
		$band = static fn (int $mujeres): array => ['corte' => '2025-12-31', 'factor' => 1.0, 'manual' => false, 'bands' => [20 => ['M' => $mujeres]]];
		$inscritos = ['local' => $band(100), 'nacional' => $band(10000), 'pares' => $band(1000)];

		$fonasa = GoalEvaluator::indicator($meta, ['meta' => null, 'denominador' => null, 'cumplimiento' => null], $answers, $inscritos, true, 2026, 6, 202606);
		self::assertSame(['comprometida' => false, 'corte' => '2025-12-31', 'factor' => 1.0, 'prorrateado' => false], $fonasa['poblacion']);
		// June: 40 ÷ 100 = 40 %, the country 3000 ÷ 10000 = 30 %, the peers 500 ÷ 1000 = 50 %. May has no corte yet.
		self::assertSame(['mes' => '2026-06', 'numerador' => 40.0, 'denominador' => 100.0, 'porcentaje' => 40.0, 'brecha_comprometida' => null, 'brecha_nacional' => -40.0, 'nacional' => 30.0, 'pares' => 50.0], $fonasa['meses'][5]);
		self::assertSame([null, 100.0, null, null, null], [$fonasa['meses'][4]['numerador'], $fonasa['meses'][4]['denominador'], $fonasa['meses'][4]['porcentaje'], $fonasa['meses'][4]['nacional'], $fonasa['meses'][4]['pares']]);

		$committed = GoalEvaluator::indicator($meta, ['meta' => null, 'denominador' => 80.0, 'cumplimiento' => null], $answers, $inscritos, true, 2026, 6, 202606);
		self::assertSame(['comprometida' => true, 'corte' => null, 'factor' => null, 'prorrateado' => false], $committed['poblacion']);
		self::assertSame([80.0, 80.0, 50.0, 30.0, 50.0], [$committed['denominador_comprometido'], $committed['meses'][5]['denominador'], $committed['meses'][5]['porcentaje'], $committed['meses'][5]['nacional'], $committed['meses'][5]['pares']]);

		$noCorte = GoalEvaluator::indicator($meta, ['meta' => null, 'denominador' => null, 'cumplimiento' => null], $answers, ['local' => null, 'nacional' => null, 'pares' => null], true, 2026, 6, 202606);
		self::assertSame([null, null, null], [$noCorte['meses'][5]['denominador'], $noCorte['meses'][5]['nacional'], $noCorte['meses'][5]['pares']], 'no corte serves the year: no % anywhere');
	}

	/** An install with no establishment: no local series, and a commitment has no one to belong to. */
	public function testNoEstablishmentMeansNoLocalSeries(): void {
		$meta = self::metas()['indicadores'][1];
		$answers = self::answers($meta['numerador'], ['P' => ['local' => null, 'nacional' => self::points(['2026-06' => 3000]), 'pares' => null]]);
		$inscritos = ['local' => null, 'nacional' => ['corte' => '2024-12-31', 'factor' => 1.03, 'manual' => false, 'bands' => [20 => ['M' => 10000]]], 'pares' => null];

		$indicator = GoalEvaluator::indicator($meta, ['meta' => null, 'denominador' => null, 'cumplimiento' => null], $answers, $inscritos, false, 2026, 6, null);

		self::assertSame(['comprometida' => false, 'corte' => '2024-12-31', 'factor' => 1.03, 'prorrateado' => false], $indicator['poblacion'], 'the country\'s corte, grown a year');
		// 3000 ÷ (10000 × 1.03) = 29.13 %.
		self::assertSame([null, null, null, 29.13, null], [$indicator['meses'][5]['numerador'], $indicator['meses'][5]['denominador'], $indicator['meses'][5]['porcentaje'], $indicator['meses'][5]['nacional'], $indicator['meses'][5]['pares']]);
	}

	/** a12: Meta VIII is its typed cumplimiento, with no series. */
	public function testAManualMetaIsItsCumplimiento(): void {
		$meta = self::metas()['indicadores'][2];

		$indicator = GoalEvaluator::indicator($meta, ['meta' => 100.0, 'denominador' => null, 'cumplimiento' => 100.0], [], [], true, 2026, 9, 202609);

		self::assertSame([true, 100.0, [], null, null], [$indicator['manual'], $indicator['cumplimiento'], $indicator['meses'], $indicator['numerador'], $indicator['denominador']]);
	}
}
