<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Register;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * The Base de Establecimientos, header-locked by name, over generated
 * registers. Every establishment is fabricated (900001 and up, región 99).
 */
class RegisterParserTest extends TestCase {
	protected function tearDown(): void {
		XlsxFixture::cleanup();
	}

	/**
	 * @param list<array<string, string|int|null>> $establishments
	 * @param list<string> $leaveOut
	 * @return array{rows: list<array<string, int|string|null>>, skipped: int}
	 */
	private static function parse(array $establishments, array $leaveOut = []): array {
		return RegisterParser::parse(XlsxReader::open(XlsxFixture::register($establishments, $leaveOut)));
	}

	public function testEachColumnIsFoundByItsNameWhereverItSits(): void {
		$parsed = self::parse([XlsxFixture::establishment(900001)]);

		self::assertSame([[
			'estab' => 900001,
			'nombre' => 'Establecimiento 900001',
			'tipo' => 'Centro de Salud Familiar (CESFAM)',
			'dependencia' => 'Municipal',
			'nivel' => 'Primario',
			'comuna_cut' => '99001',
			'comuna' => 'Comuna Uno',
			'servicio_codigo' => '91',
			'servicio' => 'Servicio de Salud Uno',
			'region_codigo' => '99',
			'region' => 'Región Uno',
			'estado' => 'Vigente en Operación Habitual',
		]], $parsed['rows']);
		self::assertSame(0, $parsed['skipped']);
	}

	public function testCodesExcelTypedAsNumbersGetTheirZerosBack(): void {
		$row = self::parse([XlsxFixture::establishment(900002, ['comuna_cut' => 9901, 'region_codigo' => 9, 'servicio_codigo' => 7])])['rows'][0];

		self::assertSame(['09901', '09', '07'], [$row['comuna_cut'], $row['region_codigo'], $row['servicio_codigo']]);
	}

	public function testAnEmptyCellIsNullAndACodeThatIsNotOneIsLeftOut(): void {
		$row = self::parse([XlsxFixture::establishment(900003, ['nivel' => null, 'servicio_codigo' => 'No Aplica'])])['rows'][0];

		self::assertNull($row['nivel']);
		self::assertNull($row['servicio_codigo']);
	}

	public function testARowWithoutASixDigitCodeAndARepeatedCodeAreSkippedAndCounted(): void {
		$parsed = self::parse([
			XlsxFixture::establishment(900004),
			XlsxFixture::establishment(90000, ['nombre' => 'código de cinco dígitos']),
			XlsxFixture::establishment(900004, ['nombre' => 'repetido']),
		]);

		self::assertSame([900004], array_column($parsed['rows'], 'estab'));
		self::assertSame('Establecimiento 900004', $parsed['rows'][0]['nombre'], 'the first row of a code holds');
		self::assertSame(2, $parsed['skipped']);
	}

	public function testAMissingColumnRefusesTheWholeFileByName(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no trae TipoEstablecimientoGlosa');

		self::parse([XlsxFixture::establishment(900005)], ['tipo']);
	}

	/** The 2019 and 2024 files: a title row, then a header in words. */
	public function testARegisterOfAnotherLayoutIsRefusedInSpanish(): void {
		$path = XlsxFixture::workbook(['Establecimientos Vigentes' => [
			1 => ['A' => 'BASE_ESTABLECIMIENTO AÑO_31-12-2099'],
			2 => ['A' => 'Código Antiguo', 'B' => 'Código Vigente', 'C' => 'Nombre Oficial'],
			3 => ['A' => '99-001', 'B' => 900006, 'C' => 'Establecimiento 900006'],
		]]);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no es la Base de Establecimientos del DEIS');

		RegisterParser::parse(XlsxReader::open($path));
	}

	/** A header with no rows under it (a summary tab) is not the register: the next sheet is read. */
	public function testASheetWithTheHeaderButNoRowsGivesWayToTheNextSheet(): void {
		$parsed = RegisterParser::parse(XlsxReader::open(XlsxFixture::register([XlsxFixture::establishment(900001)], headerOnlyFirst: true)));

		self::assertSame([900001], array_column($parsed['rows'], 'estab'));
	}

	public function testARegisterWithNoEstablishmentsIsRefused(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no trae establecimientos');

		self::parse([]);
	}
}
