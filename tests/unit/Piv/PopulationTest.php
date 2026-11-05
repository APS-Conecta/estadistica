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

	public function testARangeOfWholeBandsCountsFonasasFigures(): void {
		self::assertSame(211.0, Population::count(self::INSCRITOS, 0, 9));
		self::assertSame(2000.0, Population::count(self::INSCRITOS, 20, 69, 'M'));
		self::assertSame(2100.0, Population::count(self::INSCRITOS, 60, null, 'M'), 'an open range runs to the last band');
		self::assertSame(1500.0, Population::count(self::INSCRITOS, 80, null), '«80 a más» is 80–89, so 80+ is the whole band');
	}

	public function testOneSexCountsOnlyItsOwnAndABandWithoutItCountsNothing(): void {
		self::assertSame(110.0, Population::count(self::INSCRITOS, 0, 9, 'H'));
		self::assertSame(0.0, Population::count(self::INSCRITOS, 70, 79, 'H'));
	}

	/** Every figure is official (docs/adr/0007, amendment): a range that cuts a band has no figure, whoever is in the band. */
	public function testARangeThatCutsABandHasNoFigure(): void {
		self::assertNull(Population::count(self::INSCRITOS, 25, 64, 'M'), 'Meta II: mujeres 25–64');
		self::assertNull(Population::count(self::INSCRITOS, 6, 6), 'Meta III.B: age 6');
		self::assertNull(Population::count(self::INSCRITOS, 65, null, 'M'), '65 and over');
		self::assertNull(Population::count(self::INSCRITOS, 45, 54, 'H'), 'a cut band with no one in it');
	}

	public function testTheCortesFactorIsAppliedLast(): void {
		$grown = ['factor' => Population::GROWTH] + self::INSCRITOS;

		self::assertEqualsWithDelta(103.0, Population::count($grown, 0, 9, 'M'), 1e-9);
		self::assertEqualsWithDelta(2060.0, Population::count($grown, 20, 69, 'M'), 1e-9);
	}

	public function testARangeThatEndsBeforeItStartsIsABug(): void {
		$this->expectException(LogicException::class);

		Population::count(self::INSCRITOS, 64, 25);
	}
}
