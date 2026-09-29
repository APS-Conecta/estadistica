<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Catalog;

use OCA\Estadistica\Catalog\DictionaryParser;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * The DICCIONARIO CODIGOS rules, over XlsxFixture::dictionary(), a workbook
 * of DEIS's shape with invented labels (acceptance a2, a6). The rules
 * themselves were measured against the 2017–2026 dictionaries (design,
 * 2026-09-28), which never ship.
 */
class DictionaryParserTest extends TestCase {
	protected function tearDown(): void {
		XlsxFixture::cleanup();
		ZipFixture::cleanup();
	}

	/** @return array<string, array{codigo: string, hoja: string, seccion: string, prestacion: string, columnas: array<int, string>}> the fixture's entries by código */
	private static function entries(): array {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::dictionary()));

		return array_column($parsed['entries'], null, 'codigo');
	}

	/** @return array<string, array{string, ?string}> */
	public static function memberNames(): array {
		return [
			'2026' => ['Diccionarios/DICCIONARIO CODIGOS SA_26_V1.2.xlsm', 'A'],
			'2026 P' => ['Diccionarios/DICCIONARIO CODIGOS SP_26_V1.2.xlsm', 'P'],
			'2023' => ['Diccionarios/DICCIONARIO CODIGOS SA_23_V1.4.xlsm', 'A'],
			'2020, .xlsx' => ['DICCIONARIOS/SA_20_V1.2-CODIGOS.xlsx', 'A'],
			'2020 P' => ['DICCIONARIOS/SP_20_V1.0 CODIGOS.xlsm', 'P'],
			'2017' => ['DICCIONARIOS/SA-17_V1.5_CODIGOS.xlsm', 'A'],
			'SBS' => ['Diccionarios/DICCIONARIO CODIGOS SBS_26_V1.1.xlsm', null],
			'SBM' => ['Diccionarios/DICCIONARIO CODIGO SBM_23_V1.3.xlsm', null],
			'SD' => ['Diccionarios/DICCIONARIO CODIGOS SD_26_V1.0.xlsm', null],
			'BM 2017' => ['DICCIONARIOS/BM-17_V1.2 CODIGOS.xlsm', null],
			'BIFF, 2016' => ['SERIE_REM_2016/DICCIONARIOS/SA_16_V1.2-CODIGOS.xls', null],
			'outside the folder' => ['SA_26_V1.2.xlsm', null],
		];
	}

	/** @dataProvider memberNames */
	public function testTheDictionaryIsFoundUnderEveryNameDeisHasUsedAndNoOther(string $name, ?string $serie): void {
		$zip = ZipDirectory::read(new LocalRange(ZipFixture::zip([$name => 'x', 'Datos/SerieA2026.csv' => 'a'])));

		foreach (['A', 'P'] as $asked) {
			$member = DictionaryParser::member($zip, $asked);
			self::assertSame($asked === $serie ? $name : null, $member['name'] ?? null, "Serie $asked");
		}
	}

	public function testTwoDictionariesOfOneSerieAreRefusedRatherThanGuessedBetween(): void {
		$zip = ZipDirectory::read(new LocalRange(ZipFixture::zip([
			'Diccionarios/DICCIONARIO CODIGOS SA_26_V1.2.xlsm' => 'x',
			'Diccionarios/DICCIONARIO CODIGOS SA_26_V1.3.xlsm' => 'x',
		])));

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('más de un diccionario de la Serie A');

		DictionaryParser::member($zip, 'A');
	}

	public function testEachCodigoReadsItsHojaSeccionPrestacionAndColumns(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::dictionary()));

		self::assertSame(['A03' => 'REM-A03. HOJA DE PRUEBA'], $parsed['hojas'], 'NOMBRE and Control are not hojas');
		self::assertSame(['02010420', '03500366', '06900300', '03500400'], array_column($parsed['entries'], 'codigo'), 'in the planilla\'s order');
		self::assertSame([
			'codigo' => '02010420',
			'hoja' => 'A03',
			'seccion' => 'SECCIÓN A: PRIMERA SECCIÓN',
			'prestacion' => 'Evaluación › Normal',
			'columnas' => [
				1 => 'TOTAL › Ambos sexos',
				2 => 'TOTAL › Hombres',
				3 => 'TOTAL › Mujeres',
				4 => 'POR EDAD › Menos de 1 año › Hombres',
				5 => 'POR EDAD › Menos de 1 año › Mujeres',
				6 => 'POR EDAD › 1 año › Hombres',
				7 => 'POR EDAD › 1 año › Mujeres',
			],
		], $parsed['entries'][0]);
	}

	public function testALabelMergedDownBelongsToEveryRowItSpans(): void {
		self::assertSame('Evaluación › Alterada', self::entries()['03500366']['prestacion']);
	}

	public function testAGroupRowNamesTheRowsBelowItAndATotalRowIsNotOne(): void {
		$consulta = self::entries()['06900300'];

		self::assertSame('Controles de fin de semana › Consulta', $consulta['prestacion']);
		self::assertSame([1 => 'TOTAL › Ambos sexos', 2 => 'TOTAL › Hombres'], $consulta['columnas'], 'the section\'s header still holds below the group');
	}

	public function testACodigoTypedAsANumberGetsItsLeadingZeroBack(): void {
		self::assertArrayHasKey('06900300', self::entries());
	}

	public function testACodigoListedTwiceKeepsItsFirstRow(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::dictionary()));

		self::assertSame(1, $parsed['duplicates']);
		self::assertSame('Evaluación › Normal', self::entries()['02010420']['prestacion']);
	}

	public function testANewSectionReadsItsOwnHeaderAndMarkers(): void {
		self::assertSame(
			['codigo' => '03500400', 'hoja' => 'A03', 'seccion' => 'SECCIÓN B: SEGUNDA SECCIÓN', 'prestacion' => 'Visita', 'columnas' => [1 => 'Realizadas']],
			self::entries()['03500400'],
		);
	}

	public function testAnEliminatedCodigoIsNotALabel(): void {
		self::assertArrayNotHasKey('09999999', self::entries(), 'listed under CODIGOS ELIMINADOS, with no column');
	}

	/**
	 * SA A04 §D's shape: a group row just above a section's first data row
	 * names those rows (it is not a header, having no text over the markers),
	 * a group row between blocks names the next block, and a «Total» row
	 * ends the group rather than naming what follows it.
	 */
	public function testGroupRowsAboveTheFirstRowsAndBetweenBlocksNameThemAndATotalRowEndsAGroup(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::workbook(['A04' => [
			2 => ['B' => 'SECCIÓN D: SECCIÓN DE PRUEBA'],
			3 => ['B' => 'TIPO JORNADA', 'C' => 'TOTAL'],
			4 => ['C' => 'Ambos sexos', 'D' => 'Hombres'],
			5 => ['B' => 'Jornada continua', 'C' => 0, 'D' => 0],
			6 => ['A' => '04021101', 'B' => 'Consulta', 'C' => 'COL01', 'D' => 'COL02'],
			7 => ['B' => 'Fin de semana', 'C' => 0, 'D' => 0],
			8 => ['A' => '04021102', 'B' => 'Consulta', 'C' => 'COL01', 'D' => 'COL02'],
			9 => ['B' => 'Total', 'C' => 0],
			10 => ['A' => '04021103', 'B' => 'Otra consulta', 'C' => 'COL01'],
		]], ['A04' => ['C3:D3']])));
		$entries = array_column($parsed['entries'], null, 'codigo');

		self::assertSame('Jornada continua › Consulta', $entries['04021101']['prestacion']);
		self::assertSame([1 => 'TOTAL › Ambos sexos', 2 => 'TOTAL › Hombres'], $entries['04021101']['columnas'], 'the group row is not part of the header');
		self::assertSame('Fin de semana › Consulta', $entries['04021102']['prestacion'], 'the two consultas stay apart');
		self::assertSame('Otra consulta', $entries['04021103']['prestacion'], 'a Total row names nothing below it');
	}

	/**
	 * SP 2020's P6: after a block of rows, text over the markers' own columns
	 * is a header for the rows below it, which may have more columns.
	 */
	public function testTextOverTheMarkersColumnsIsANewHeaderForTheRowsBelow(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::workbook(['P6' => [
			2 => ['B' => 'SECCION A.3: SECCIÓN DE PRUEBA'],
			3 => ['B' => 'CONCEPTO', 'D' => 'TOTAL'],
			4 => ['A' => 'P6230300', 'B' => 'Primera', 'D' => 'COL01'],
			5 => ['B' => 'B. OTRA PARTE'],
			6 => ['B' => 'CONCEPTO', 'D' => 'T O T A L', 'E' => 'Gestantes'],
			7 => ['A' => 'P6223090', 'B' => 'Segunda', 'D' => 'COL01', 'E' => 'COL02'],
		]])));

		self::assertSame([1 => 'TOTAL'], $parsed['entries'][0]['columnas']);
		self::assertSame([1 => 'T O T A L', 2 => 'Gestantes'], $parsed['entries'][1]['columnas']);
		self::assertSame('Segunda', $parsed['entries'][1]['prestacion'], 'the new header starts over: no group carries across it');
	}

	/** One hoja, one section: a header row, then $rows. */
	private static function hoja(array $rows): array {
		return [1 => ['B' => 'SECCIÓN A: PRIMERA'], 2 => ['B' => 'PRESTACIÓN', 'D' => 'Total'], ...$rows];
	}

	/**
	 * A row with markers whose column A holds something that is not a código
	 * is counted rather than dropped unseen. A row with no código at all is
	 * DEIS's «reports nothing» row, and is not counted.
	 */
	public function testARowWhoseCodigoCannotBeReadIsCounted(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::workbook(['A03' => self::hoja([
			3 => ['A' => '02010420', 'B' => 'Evaluación', 'D' => 'COL01'],
			4 => ['A' => '0201-0421', 'B' => 'Mal escrito', 'D' => 'COL01'],
			5 => ['B' => 'Sin código', 'D' => 'COL01'],
		])])));

		self::assertSame(['02010420'], array_column($parsed['entries'], 'codigo'));
		self::assertSame(1, $parsed['unreadable']);
	}

	/**
	 * estadistica_code.hoja holds 16 characters. A sheet named like a hoja
	 * but longer is skipped and counted, so its códigos read «sin
	 * descripción» instead of the whole dictionary failing at the INSERT.
	 */
	public function testAHojaNameTooLongToStoreIsSkippedAndCounted(): void {
		$parsed = DictionaryParser::parse(XlsxReader::open(XlsxFixture::workbook([
			'A03' => self::hoja([3 => ['A' => '02010420', 'B' => 'Evaluación', 'D' => 'COL01']]),
			'A04 versión anterior' => self::hoja([3 => ['A' => '03500366', 'B' => 'Consulta', 'D' => 'COL01']]),
		])));

		self::assertSame(['A03'], array_values(array_unique(array_column($parsed['entries'], 'hoja'))));
		self::assertSame(['A03'], array_keys($parsed['hojas']));
		self::assertSame(1, $parsed['skippedSheets']);
	}

	public function testAWorkbookWithoutCodigosIsRefusedInSpanish(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no tiene la forma de un DICCIONARIO CODIGOS');

		DictionaryParser::parse(XlsxReader::open(XlsxFixture::register([XlsxFixture::establishment(900001)])));
	}
}
