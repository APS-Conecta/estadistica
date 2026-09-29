<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Xlsx;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;

/** The stdlib workbook reader, over generated workbooks. */
class XlsxReaderTest extends TestCase {
	private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	protected function tearDown(): void {
		XlsxFixture::cleanup();
		ZipFixture::cleanup();
	}

	/**
	 * A one-sheet workbook of these raw parts: for the cell forms a writer
	 * chooses (inline strings, formulas, errors, rich text).
	 *
	 * @param array<string, string> $extra
	 */
	private static function raw(string $sheetData, string $strings = '', string $target = 'worksheets/sheet1.xml', array $extra = []): string {
		return XlsxFixture::raw([
			'xl/workbook.xml' => '<workbook xmlns="' . self::MAIN . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja" sheetId="1" r:id="rId1"/></sheets></workbook>',
			'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="' . $target . '"/></Relationships>',
			'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . self::MAIN . '"><sheetData>' . $sheetData . '</sheetData></worksheet>',
			...($strings === '' ? [] : ['xl/sharedStrings.xml' => '<sst xmlns="' . self::MAIN . '">' . $strings . '</sst>']),
			...$extra,
		]);
	}

	/** @return array<int, array<int, string>> */
	private static function rows(string $path, string $sheet = 'Hoja'): array {
		return iterator_to_array(XlsxReader::open($path)->rows($sheet));
	}

	public function testSheetsComeInWorkbookOrder(): void {
		$xlsx = XlsxReader::open(XlsxFixture::workbook(['Zeta' => [1 => ['A' => 'z']], 'Alfa' => [1 => ['A' => 'a']]]));

		self::assertSame(['Zeta', 'Alfa'], $xlsx->sheets());
	}

	public function testEveryCellFormReadsAsItsText(): void {
		$path = self::raw(
			'<row r="3">'
			. '<c r="A3" t="s"><v>0</v></c>'
			. '<c r="B3"><v>200658</v></c>'
			. '<c r="C3" t="inlineStr"><is><t>en línea</t></is></c>'
			. '<c r="D3" t="str"><f>A1&amp;B1</f><v>fórmula</v></c>'
			. '<c r="E3" t="b"><v>1</v></c>'
			. '<c r="F3"><v>-20.219150979999998</v></c>'
			. '</row>',
			'<si><t>compartida</t></si>',
		);

		self::assertSame(
			[3 => [1 => 'compartida', 2 => '200658', 3 => 'en línea', 4 => 'fórmula', 5 => '1', 6 => '-20.219150979999998']],
			self::rows($path),
		);
	}

	public function testRichTextJoinsItsRunsAndLeavesOutThePhoneticGuide(): void {
		$path = self::raw(
			'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>',
			'<si><r><t>Menor </t></r><r><t>de 15 años</t></r><rPh sb="0" eb="1"><t>guía</t></rPh></si><si/>',
		);

		self::assertSame([1 => [1 => 'Menor de 15 años', 2 => '']], self::rows($path));
	}

	public function testAnErrorCellAndAnEmptyCellReadAsNothing(): void {
		$path = self::raw('<row r="1"><c r="A1" t="e"><v>#VALUE!</v></c><c r="B1" s="4"/><c r="C1"><v>7</v></c></row><row r="2"/>');

		self::assertSame([1 => [3 => '7']], self::rows($path));
	}

	public function testACellWithoutAReferenceTakesTheNextColumnAndARowWithoutOneTheNextRow(): void {
		$path = self::raw('<row r="4"><c r="C4"><v>1</v></c><c><v>2</v></c></row><row><c><v>3</v></c></row>');

		self::assertSame([4 => [3 => '1', 4 => '2'], 5 => [1 => '3']], self::rows($path));
	}

	public function testMergedRangesAreRowsAndColumns(): void {
		$xlsx = XlsxReader::open(XlsxFixture::workbook(['Hoja' => [9 => ['B' => 'MÉTODOS']]], ['Hoja' => ['B9:C10', 'E9:Q9', 'AA3:AB4']]));

		self::assertSame([[9, 2, 10, 3], [9, 5, 9, 17], [3, 27, 4, 28]], $xlsx->merges('Hoja'));
	}

	public function testAnAbsoluteTargetIsFound(): void {
		self::assertSame([1 => [1 => '5']], self::rows(self::raw('<row r="1"><c r="A1"><v>5</v></c></row>', target: '/xl/worksheets/sheet1.xml')));
	}

	public function testAnEntityIsNeverExpanded(): void {
		$secret = (string)tempnam(sys_get_temp_dir(), 'estadistica-secret-');
		file_put_contents($secret, 'SECRETO');
		try {
			$path = XlsxFixture::raw([
				'xl/workbook.xml' => '<workbook xmlns="' . self::MAIN . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja" sheetId="1" r:id="rId1"/></sheets></workbook>',
				'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>',
				'xl/worksheets/sheet1.xml' => '<worksheet xmlns="' . self::MAIN . '"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
				'xl/sharedStrings.xml' => '<?xml version="1.0"?><!DOCTYPE sst [<!ENTITY x SYSTEM "file://' . $secret . '">]><sst xmlns="' . self::MAIN . '"><si><t>a&x;b</t></si></sst>',
			]);

			// Parsed, and the entity left unexpanded: not the file's content.
			self::assertSame([1 => [1 => 'ab']], self::rows($path));
		} finally {
			unlink($secret);
		}
	}

	/** @return array<string, array{string}> */
	public static function notAWorkbook(): array {
		return [
			'a text file' => ['text'],
			'a zip without a workbook' => ['zip'],
		];
	}

	/** @dataProvider notAWorkbook */
	public function testSomethingThatIsNotAWorkbookIsRefusedInSpanish(string $kind): void {
		$path = $kind === 'text' ? ZipFixture::file('esto no es una planilla') : ZipFixture::zip(['SerieA2099.csv' => ZipFixture::header()]);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no es una planilla Excel');

		XlsxReader::open($path);
	}

	public function testAMissingSheetIsRefusedInSpanish(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no trae la hoja «P1»');

		iterator_to_array(XlsxReader::open(XlsxFixture::workbook(['A01' => [1 => ['A' => 'x']]]))->rows('P1'));
	}
}
