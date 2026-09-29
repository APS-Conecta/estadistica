<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Piv;

use LogicException;
use OCA\Estadistica\Piv\Population;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two PIV rules that decide a denominator (docs/adr/0007), each against a
 * value computed by hand: which corte a meta year reads, and how an age range
 * is cut out of FONASA's decade bands.
 */
class PopulationTest extends TestCase {
	/** One scope's inscritos by band and sex, as Population reads them. */
	private const INSCRITOS = [
		'corte' => '2025-12-31',
		'factor' => 1.0,
		'manual' => false,
		'bands' => [
			0 => ['M' => 100, 'H' => 110, 'S' => 1],
			10 => ['M' => 150, 'H' => 140],
			20 => ['M' => 200, 'H' => 180],
			30 => ['M' => 300, 'H' => 290],
			40 => ['M' => 400],
			50 => ['M' => 500],
			60 => ['M' => 600, 'H' => 580],
			70 => ['M' => 700],
			80 => ['M' => 800, 'H' => 700],
		],
	];

	/** @return array<string, array{int, list<string>, ?array{corte: string, factor: float}}> */
	public static function cortes(): array {
		return [
			'the corte of the year before, as stored' => [2026, ['2024-12-31', '2025-12-31'], ['corte' => '2025-12-31', 'factor' => 1.0]],
			'missing: the latest earlier one × 1.03' => [2026, ['2023-12-31', '2024-12-31'], ['corte' => '2024-12-31', 'factor' => 1.03]],
			'missing: an earlier corte not at year end' => [2026, ['2025-06-30'], ['corte' => '2025-06-30', 'factor' => 1.03]],
			'a later corte never stands in' => [2025, ['2025-12-31'], null],
			'a later corte is passed over for an earlier one' => [2025, ['2023-12-31', '2025-12-31'], ['corte' => '2023-12-31', 'factor' => 1.03]],
			'no corte at all' => [2026, [], null],
		];
	}

	/**
	 * @param list<string> $cortes
	 * @param ?array{corte: string, factor: float} $expected
	 */
	#[DataProvider('cortes')]
	public function testAMetaYearReadsTheCorteOfTheYearBeforeElseTheLastOneGrown(int $year, array $cortes, ?array $expected): void {
		self::assertSame($expected, Population::pick($year, $cortes));
	}

	public function testABandWhollyInsideTheRangeIsNotProrated(): void {
		self::assertSame(['valor' => 211.0, 'prorrateado' => false], Population::count(self::INSCRITOS, 0, 9));
		self::assertSame(['valor' => 1500.0, 'prorrateado' => false], Population::count(self::INSCRITOS, 80, null), '«80 a más» is 80–89, so 80+ is the whole band');
	}

	/** Meta II: mujeres 25–64 = 200 × 5/10 + 300 + 400 + 500 + 600 × 5/10. */
	public function testMetaTwoWomen25To64CutsTheTwentiesAndTheSixtiesInHalf(): void {
		self::assertSame(['valor' => 1600.0, 'prorrateado' => true], Population::count(self::INSCRITOS, 25, 64, 'M'));
	}

	/** Meta III.B: age 6 alone is a tenth of 0–9, all three sexes: 211 / 10. */
	public function testASingleAgeIsATenthOfItsBand(): void {
		$count = Population::count(self::INSCRITOS, 6, 6);

		self::assertEqualsWithDelta(21.1, $count['valor'], 1e-9);
		self::assertTrue($count['prorrateado']);
	}

	/** 65 and over, mujeres: 600 × 5/10 + 700 + 800. */
	public function testAnOpenRangeRunsToTheLastBand(): void {
		self::assertSame(['valor' => 1800.0, 'prorrateado' => true], Population::count(self::INSCRITOS, 65, null, 'M'));
	}

	public function testOneSexCountsOnlyItsOwnAndABandWithoutItCountsNothing(): void {
		self::assertSame(['valor' => 110.0, 'prorrateado' => false], Population::count(self::INSCRITOS, 0, 9, 'H'));
		self::assertSame(['valor' => 0.0, 'prorrateado' => false], Population::count(self::INSCRITOS, 70, 79, 'H'));
	}

	/** A band with no one in it is not an estimate, whatever the range cuts. */
	public function testACutBandWithNoOneInItIsNotAnEstimate(): void {
		self::assertSame(['valor' => 0.0, 'prorrateado' => false], Population::count(self::INSCRITOS, 45, 54, 'H'));
	}

	public function testTheCortesFactorIsAppliedLast(): void {
		$grown = ['factor' => Population::GROWTH] + self::INSCRITOS;

		self::assertEqualsWithDelta(103.0, Population::count($grown, 0, 9, 'M')['valor'], 1e-9);
		self::assertEqualsWithDelta(1648.0, Population::count($grown, 25, 64, 'M')['valor'], 1e-9);
	}

	public function testARangeThatEndsBeforeItStartsIsABug(): void {
		$this->expectException(LogicException::class);

		Population::count(self::INSCRITOS, 64, 25);
	}
}
