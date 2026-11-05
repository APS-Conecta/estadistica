<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use OCA\Estadistica\Catalog\DictionaryParser;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\PlanillaReader;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * DEIS's own REM form, read through the 2026 diccionario's layout, over
 * synthetic forms and dictionaries in DEIS's 2026 A and P layouts
 * (XlsxFixture). No DEIS file ships.
 */
class PlanillaReaderTest extends TestCase {
	private const ESTABLISHMENT = '900001';

	protected function tearDown(): void {
		XlsxFixture::cleanup();
	}

	/**
	 * The diccionarios this install holds: 2026's, of both series, named as
	 * DEIS names them.
	 *
	 * @return callable(int, string): ?array{name: string, entries: list<array{codigo: string, hoja: string, fila: int, celdas: array<int, int>}>}
	 */
	private static function layouts(): callable {
		return static fn (int $anio, string $serie): ?array => $anio !== 2026 ? null : [
			'name' => "DICCIONARIO CODIGOS S{$serie}_26_V1.2.xlsm",
			'entries' => DictionaryParser::parse(XlsxReader::open(XlsxFixture::dictionary2026($serie)))['entries'],
		];
	}

	/** @return array{serie: string, anio: int, mes: int, estab: int, figures: array<string, array<int, string>>} */
	private static function read(string $path, string $establishment = self::ESTABLISHMENT): array {
		$planilla = PlanillaReader::read(XlsxReader::open($path), $establishment, self::layouts());

		$figures = [];
		foreach ($planilla['rows'] as $row) {
			self::assertSame([$planilla['mes'], $planilla['estab']], [$row[0], $row[1]]);
			$figures[$row[2]] = array_filter(
				array_combine(range(1, count($row) - 3), array_slice($row, 3)),
				static fn (?string $value): bool => $value !== null,
			);
		}
		unset($planilla['rows']);

		return [...$planilla, 'figures' => $figures];
	}

	public function testEachFigureIsReadFromTheCellTheSerieADiccionarioPutsIt(): void {
		$planilla = self::read(XlsxFixture::form2026('A', [
			'01010101' => [1 => 7, 2 => 3, 3 => 4],
			'05225303' => [1 => 12],
		]));

		self::assertSame(
			['serie' => 'A', 'anio' => 2026, 'mes' => 3, 'estab' => 900001, 'figures' => [
				'01010101' => [1 => '7', 2 => '3', 3 => '4'],
				'05225303' => [1 => '12'],
			]],
			$planilla,
		);
	}

	public function testASeriePFormIsReadThroughItsOwnDiccionario(): void {
		$planilla = self::read(XlsxFixture::form2026('P', ['P4150100' => [1 => 9, 2 => 4, 3 => 5]], ['B6' => 'JUNIO']));

		self::assertSame(
			['serie' => 'P', 'anio' => 2026, 'mes' => 6, 'estab' => 900001, 'figures' => ['P4150100' => [1 => '9', 2 => '4', 3 => '5']]],
			$planilla,
		);
	}

	public function testABlankCellIsSinInformarAndARowWithNoFigureIsNotKept(): void {
		$planilla = self::read(XlsxFixture::form2026('A', [
			'01010101' => [1 => 5, 2 => 5],
			// The form's totals are formulas: a row nobody filled still reads 0.
			'01010201' => [1 => 0, 2 => 0, 3 => 0],
		]));

		self::assertSame(['01010101' => [1 => '5', 2 => '5']], $planilla['figures'], 'col03 is blank, not 0, and 01010201 has no figure');
	}

	public function testMacrosNeverRun(): void {
		$figures = ['05225303' => [1 => 12]];
		// A macro project whose bytes no reader could make sense of: it is never opened.
		$macros = XlsxFixture::form2026('A', $figures, [], ['xl/vbaProject.bin' => random_bytes(512)]);

		self::assertSame(self::read(XlsxFixture::form2026('A', $figures)), self::read($macros));
	}

	/**
	 * Built in the test, not here: tearDown removes every fixture file.
	 *
	 * @return array<string, array{callable(): string, string, string}> the planilla → configured establishment → the refusal
	 */
	public static function refusals(): array {
		return [
			'another establishment\'s' => [static fn (): string => XlsxFixture::form2026('A', [], ['H3' => 2]), self::ESTABLISHMENT, 'es del establecimiento 900002, no del configurado en esta instalación (900001)'],
			'no establishment configured' => [static fn (): string => XlsxFixture::form2026('A', []), '', 'No hay establecimiento configurado'],
			'no code' => [static fn (): string => XlsxFixture::form2026('A', [], ['E3' => null]), self::ESTABLISHMENT, 'no trae el código del establecimiento'],
			'no month' => [static fn (): string => XlsxFixture::form2026('A', [], ['B6' => null]), self::ESTABLISHMENT, 'no dice su mes'],
			'another version of the form' => [static fn (): string => XlsxFixture::form2026('A', [], ['A9' => 'Versión 1.1: Enero 2026']), self::ESTABLISHMENT, 'es la versión 1.1 del formulario de 2026, y el diccionario de 2026 de la Serie A es la versión 1.2'],
			'a year with no diccionario' => [static fn (): string => XlsxFixture::form2026('P', [], ['B7' => 2025]), self::ESTABLISHMENT, 'No hay diccionario de 2025 de la Serie P'],
			'not a DEIS form' => [static fn (): string => XlsxFixture::workbook(['Hoja1' => [1 => ['A' => 'Atenciones', 'B' => 12]]]), self::ESTABLISHMENT, 'no es un formulario REM del DEIS'],
			'a NOMBRE that names no serie' => [static fn (): string => XlsxFixture::form2026('A', [], ['B17' => 'SERIE BS']), self::ESTABLISHMENT, 'no es un formulario REM del DEIS'],
			'unreadable' => [static fn (): string => XlsxFixture::raw(['notas.txt' => 'esto no es una planilla']), self::ESTABLISHMENT, 'no es una planilla Excel'],
		];
	}

	/** @dataProvider refusals */
	public function testAPlanillaIsRefusedWholeWithItsReason(callable $planilla, string $establishment, string $reason): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage($reason);

		self::read($planilla(), $establishment);
	}
}
