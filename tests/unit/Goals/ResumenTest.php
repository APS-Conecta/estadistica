<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Goals;

use OCA\Estadistica\Goals\GoalEvaluator;
use OCA\Estadistica\Goals\GoalSeed;
use OCA\Estadistica\Goals\Resumen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Resumen's label rule (docs/adr/0009): a past year compares only when
 * its diccionario writes the ruta's labels as the current year's does, once
 * case, accents and plurals are folded. And its meta mínima rule (Res. Exenta
 * 732), as each meta's seed writes it, over a base year's cierre. The figures
 * are GoalsTest's.
 */
final class ResumenTest extends TestCase {
	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function labels(): array {
		return [
			'the same' => ['A03 › SECCIÓN A › Evaluación › Niños de 12 a 17 meses', 'A03 › SECCIÓN A › Evaluación › Niños de 12 a 17 meses', true],
			'capitals and accents' => ['Evaluación inicial', 'EVALUACION INICIAL', true],
			'plurals, either way' => ['Controles de salud', 'Control de saludes', true],
			'another word' => ['Evaluación inicial con rezago', 'Evaluación inicial con riesgo', false],
			'a word more' => ['Evaluación inicial', 'Evaluación inicial presencial', false],
			'another age' => ['Niños de 12 a 17 meses', 'Niños de 12 a 18 meses', false],
			'another hoja' => ['A03 › Evaluación', 'A04 › Evaluación', false],
		];
	}

	#[DataProvider('labels')]
	public function testALabelIsFoldedOnlyForCaseAccentsAndPlurals(string $a, string $b, bool $same): void {
		self::assertSame($same, Resumen::sameLabel($a, $b));
		self::assertSame($same, Resumen::sameLabel($b, $a));
	}

	/**
	 * Res. Exenta 732's rules, as the 2027 seed writes them, each below and
	 * at or above its meta nacional. Computed exactly, half up to one
	 * decimal: III's (40.1 + 65) ÷ 2 is 52.55, so 52.6.
	 *
	 * @return array<string, array{0: array<string, int>, 1: float, 2: float, 3: ?float, 4: array{porcentaje: ?float, base: ?int, motivo: ?string}}>
	 */
	public static function rules(): array {
		$i = ['brecha' => 30, 'piso' => 60];
		$ii = ['brecha' => 40, 'brecha_si_cumplio' => 30, 'piso' => 50];
		$shown = static fn (float $porcentaje, ?string $motivo = null): array => ['porcentaje' => $porcentaje, 'base' => 2025, 'motivo' => $motivo];

		return [
			'I closes 30 % of the gap' => [$i, 90, 70.0, null, $shown(76.0)],
			'I has a floor of 60 %' => [$i, 90, 30.0, null, $shown(60.0)],
			'at or above the meta nacional, the base is kept' => [$i, 90, 95.0, null, $shown(95.0)],
			'II reached its previous meta comprometida: 30 % of the gap to 80' => [$ii, 80, 60.0, 60.0, $shown(66.0)],
			'II did not reach it: 40 %' => [$ii, 80, 60.0, 60.01, $shown(68.0)],
			'II without one: 40 %, and why' => [$ii, 80, 60.0, null, $shown(68.0, 'sin la meta comprometida 2025, se cierra el 40 % de la brecha')],
			'II from 40 % or less commits to at least 50 %' => [$ii, 80, 30.0, 25.0, $shown(50.0)],
			'III to VI close half the gap, exactly' => [['brecha' => 50], 65, 40.1, null, $shown(52.6)],
			'VII is 80 %' => [['fija' => 80], 80, 70.0, null, $shown(80.0)],
			'VII over 80 % keeps the base' => [['fija' => 80], 80, 85.5, null, $shown(85.5)],
		];
	}

	/**
	 * @param array<string, int> $rule
	 * @param array{porcentaje: ?float, base: ?int, motivo: ?string} $expected
	 */
	#[DataProvider('rules')]
	public function testEachMetaMinimaRule(array $rule, float $nacional, float $cierre, ?float $previous, array $expected): void {
		self::assertSame($expected, self::minimum(['meta_minima' => $rule, 'meta_nacional' => $nacional], self::base(2025, $cierre), $previous));
	}

	/** Meta VIII is informed by hand, so it has no cierre: its minimum is 100 %, from no base year. */
	public function testAManualMetaHasItsFixedMinimum(): void {
		$viii = ['codigo' => 'VIII', 'nombre' => 'Ocho', 'indicador' => 'El ocho', 'meta_nacional' => 100, 'manual' => true, 'meta_minima' => ['fija' => 100]];

		self::assertSame(['porcentaje' => 100.0, 'base' => null, 'motivo' => null], self::minimum($viii, null, null));
	}

	/** No figure, no minimum, and why; a seed without the rule shows none (docs/adr/0008: keys are only added). */
	public function testAMetaWithNoFigureHasNoMinimum(): void {
		$rule = ['meta_minima' => ['brecha' => 50]];
		$none = static fn (?int $base, string $motivo): array => ['porcentaje' => null, 'base' => $base, 'motivo' => $motivo];

		self::assertSame($none(null, 'esperando ruta oficial'), self::minimum($rule + ['oficial' => false], self::base(2025, 50.0), null));
		self::assertSame($none(2025, 'falta el denominador oficial de 2025'), self::minimum($rule, self::base(2025, null, 'falta el denominador oficial de 2025'), null));
		self::assertSame($none(2025, 'no hay cierre comparable de 2025'), self::minimum($rule, self::base(2025, null, Resumen::NOT_COMPARABLE), null));
		self::assertSame($none(2025, 'no hay cierre comparable de 2025'), self::minimum($rule, self::base(2025, null), null), 'December sin informar');
		self::assertSame($none(null, 'no hay cierre comparable'), self::minimum($rule, null, null), 'no complete year in the ventana');
		self::assertNull(self::minimum([], self::base(2025, 50.0), null));
	}

	/**
	 * A meta of a 2026 seed with $changes, read by GoalSeed and counted by
	 * GoalEvaluator, and its meta mínima over $base.
	 *
	 * @param array<string, mixed> $changes the meta's keys, "oficial" for its numerador's
	 * @param ?array{anio: int, estado: ?string, cierre: ?float, meses: list<mixed>, ruta: array{numerador: null, denominador: null}} $base
	 * @return ?array{porcentaje: ?float, base: ?int, motivo: ?string}
	 */
	private static function minimum(array $changes, ?array $base, ?float $previous): ?array {
		$rem = ['fuente' => 'rem-a', 'periodo' => ['desde' => '2026-01', 'hasta' => '2026-12'], 'sumar' => [['codigos' => ['02010420'], 'columnas' => ['col08']]]];
		$oficial = array_key_exists('oficial', $changes) ? ['oficial' => $changes['oficial']] : [];
		unset($changes['oficial']);
		$rutas = ($changes['manual'] ?? false) ? [] : ['numerador' => $rem + $oficial, 'denominador' => $rem];
		$meta = $changes + ['codigo' => 'I', 'nombre' => 'Uno', 'indicador' => 'El uno', 'meta_nacional' => 90] + $rutas;
		$indicador = GoalSeed::parse(['anio' => 2026, 'version' => 1, 'nombre' => 'Metas de prueba', 'referencia' => 'Una prueba', 'diccionarios' => ['rem-a' => 'SA_26_V1.0'], 'indicadores' => [$meta]], 2026)['indicadores'][0];
		$counted = GoalEvaluator::indicator($indicador, ['meta' => null, 'denominador' => null, 'cumplimiento' => null], [], ['local' => null, 'nacional' => null, 'pares' => null], true, 2026, 12, 202612, 202612);

		return Resumen::minimum($indicador['meta_minima'], $counted, $base, $previous);
	}

	/** @return array{anio: int, estado: ?string, cierre: ?float, meses: list<mixed>, ruta: array{numerador: null, denominador: null}} a year of the histórico as Resumen::year serves it */
	private static function base(int $anio, ?float $cierre, ?string $estado = null): array {
		return ['anio' => $anio, 'estado' => $estado, 'cierre' => $cierre, 'meses' => [], 'ruta' => ['numerador' => null, 'denominador' => null]];
	}
}
