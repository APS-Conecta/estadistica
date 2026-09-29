<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Piv;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Piv\PivReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FONASA's «Inscritos APS» export, header-locked (a11), over generated files
 * in the shape research measured. Every center is fabricated (900001 and up).
 */
class PivReaderTest extends TestCase {
	/** @return array{rows: list<array<string, int|string>>, lines: int, unknownAge: int} */
	private static function read(string $csv): array {
		return PivReader::read(PivFixture::stream($csv));
	}

	private static function refusal(string $csv): string {
		try {
			self::read($csv);
		} catch (RefusedException $e) {
			return $e->getMessage();
		}
		self::fail('the file was read, and should have been refused');
	}

	/** FONASA's columns, with $line's cells in reverse order: the same set, somewhere else. */
	private static function reversed(string $line): string {
		return implode(',', array_reverse(str_getcsv($line, ',', '"', '')));
	}

	public function testFonasasExportIsSummedOverTheIncomeTramoAndTheUnagedAreCounted(): void {
		$read = self::read(PivFixture::csv([
			PivFixture::row('31-12-2025', 900001, 'A', '20 a 29 años', 'Mujeres', 10),
			PivFixture::row('31-12-2025', 900001, 'B', '20 a 29 años', 'Mujeres', 5),
			PivFixture::row('31-12-2025', 900001, 'X', '20 a 29 años', 'Hombres', 7),
			PivFixture::row('31-12-2025', 900002, 'D', '80 a más años', 'Mujeres', 3),
			PivFixture::row('31-12-2025', 900001, 'C', 'Sin información', 'Hombres', 4),
			PivFixture::row('31-12-2025', 900001, 'A', '0 a 9 años', 'Sin información', 2),
		]));

		self::assertSame([
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 20, 'sexo' => 'M', 'inscritos' => 15],
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 20, 'sexo' => 'H', 'inscritos' => 7],
			['corte' => '2025-12-31', 'estab' => 900002, 'edad' => 80, 'sexo' => 'M', 'inscritos' => 3],
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 0, 'sexo' => 'S', 'inscritos' => 2],
		], $read['rows']);
		self::assertSame(6, $read['lines']);
		self::assertSame(4, $read['unknownAge'], 'no age band fits no meta: counted, not stored');
	}

	public function testEveryBandFonasaWritesIsItsFirstYear(): void {
		$rows = [];
		foreach (array_keys(PivReader::BANDS) as $band) {
			$rows[] = PivFixture::row('31-12-2025', 900001, 'A', $band, 'Mujeres', 1);
		}

		self::assertSame([0, 10, 20, 30, 40, 50, 60, 70, 80], array_column(self::read(PivFixture::csv($rows))['rows'], 'edad'));
	}

	public function testTheColumnsAreFoundByNameInAnyOrderAndABomIsNoColumn(): void {
		$row = PivFixture::row('31-12-2025', 900001, 'A', '30 a 39 años', 'Hombres', 9);
		$csv = "\u{FEFF}" . self::reversed(PivFixture::HEADER) . "\r\n" . self::reversed($row) . "\r\n";

		self::assertSame(
			[['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 30, 'sexo' => 'H', 'inscritos' => 9]],
			self::read($csv)['rows'],
		);
	}

	/** Excel in es-CL saves with `;`, in Windows-1252: REGIÓN, «años» and «más» must still read. */
	public function testAFileExcelSavedInSpanishReadsTheSame(): void {
		$csv = PivFixture::csv([
			PivFixture::row('31/12/2025', 900001, 'A', '80 a más años', 'Mujeres', 6),
			PivFixture::row('31/12/2025', 900001, 'A', '10 a 19 años', 'Sin información', 1),
		]);
		$excel = (string)mb_convert_encoding(str_replace(',', ';', $csv), 'Windows-1252', 'UTF-8');
		self::assertFalse(mb_check_encoding($excel, 'UTF-8'), 'the fixture must really be Windows-1252');

		self::assertSame([
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 80, 'sexo' => 'M', 'inscritos' => 6],
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 10, 'sexo' => 'S', 'inscritos' => 1],
		], self::read($excel)['rows']);
	}

	public function testACorteIsReadAsFonasaOrASpreadsheetWritesItAndOneFileMayCarrySeveral(): void {
		$read = self::read(PivFixture::csv([
			PivFixture::row('31-12-2025', 900001, 'A', '0 a 9 años', 'Mujeres', 1),
			PivFixture::row('31/12/2025', 900001, 'B', '0 a 9 años', 'Mujeres', 2),
			PivFixture::row('2024-12-31', 900001, 'A', '0 a 9 años', 'Mujeres', 4),
		]));

		self::assertSame([
			['corte' => '2025-12-31', 'estab' => 900001, 'edad' => 0, 'sexo' => 'M', 'inscritos' => 3],
			['corte' => '2024-12-31', 'estab' => 900001, 'edad' => 0, 'sexo' => 'M', 'inscritos' => 4],
		], $read['rows']);
	}

	/** @return array<string, array{string, list<string>}> */
	public static function foreignHeaders(): array {
		$fonasa = PivFixture::HEADER;

		return [
			'a RUT added' => [$fonasa . ',"RUT"', ['Sobra: RUT.']],
			'a patient name added' => [$fonasa . ',"NOMBRE_PACIENTE"', ['Sobra: NOMBRE_PACIENTE.']],
			'a column left out' => [str_replace(',"SEXO"', '', $fonasa), ['Falta: SEXO.']],
			'a column renamed' => [str_replace('"SEXO"', '"GENERO"', $fonasa), ['Sobra: GENERO.', 'Falta: SEXO.']],
			'a column twice' => [$fonasa . ',"PERIODO"', ['Sobra: PERIODO.']],
			'a column with no name' => [$fonasa . ',', ['Sobra: (una columna sin nombre).']],
			'a note, not a CSV' => ['esto no es un zip', ['Sobra: esto no es un zip.', 'Falta: PERIODO, SERVICIO_SALUD']],
			'an empty file' => ['', ['Sobra: (una columna sin nombre).', 'Falta: PERIODO']],
		];
	}

	/**
	 * a11: any other set of columns is refused whole, naming what differs, and
	 * the sentence says what the door takes (DroppedFiles hands this reader
	 * every file that is not a zip).
	 *
	 * @param list<string> $fragments
	 */
	#[DataProvider('foreignHeaders')]
	public function testAnyOtherHeaderIsRefusedWhole(string $header, array $fragments): void {
		$message = self::refusal(PivFixture::csv([PivFixture::row('31-12-2025', 900001, 'A', '0 a 9 años', 'Mujeres', 1) . ',x'], $header));

		self::assertStringStartsWith('El archivo no es un ZIP del DEIS ni el CSV «Inscritos APS» de FONASA', $message);
		self::assertStringContainsString('PERIODO, SERVICIO_SALUD, REGIÓN, COMUNA, COD_CENTRO, NOMBRE_CENTRO, DEPENDENCIA_ADMINISTRATIVA, TRAMO_FONASA, EDAD_TRAMO, SEXO, TOTAL_INSCRITOS', $message);
		foreach ($fragments as $fragment) {
			self::assertStringContainsString($fragment, $message);
		}
		self::assertStringEndsWith('Nada se guardó.', $message);
	}

	public function testALongListOfForeignColumnsIsCut(): void {
		$message = self::refusal(PivFixture::HEADER . ',"A1","A2","A3","A4","A5","A6","A7"' . "\r\n");

		self::assertStringContainsString('Sobra: A1, A2, A3, A4, A5….', $message);
		self::assertStringNotContainsString('A6', $message);
	}

	/** @return array<string, array{string, string}> */
	public static function malformedRows(): array {
		$row = static fn (string $periodo = '31-12-2025', string $centro = '900001', string $edad = '0 a 9 años', string $sexo = 'Mujeres', string $inscritos = '1'): string
			=> PivFixture::row($periodo, $centro, 'A', $edad, $sexo, $inscritos);

		return [
			'a PERIODO that is no date' => [$row(periodo: '31-13-2025'), 'el PERIODO «31-13-2025» no es una fecha'],
			'a PERIODO in words' => [$row(periodo: 'diciembre 2025'), 'el PERIODO «diciembre 2025» no es una fecha'],
			'a center code with letters' => [$row(centro: 'DAS01'), 'el COD_CENTRO «DAS01» no es un código'],
			'a band FONASA does not write' => [$row(edad: '0 a 4 años'), 'el EDAD_TRAMO «0 a 4 años» no es un tramo de FONASA'],
			'a sex FONASA does not write' => [$row(sexo: 'Otro'), 'el SEXO «Otro» no es Mujeres, Hombres ni Sin información'],
			'a count with decimals' => [$row(inscritos: '1.5'), 'el TOTAL_INSCRITOS «1.5» no es un número entero'],
			'a negative count' => [$row(inscritos: '-3'), 'el TOTAL_INSCRITOS «-3» no es un número entero'],
			'a row with a cell less' => ['"31-12-2025","900001"', 'trae 2 columnas, y el encabezado 11'],
		];
	}

	/** A wrong row refuses the whole file, naming its line: the first data line is line 2. */
	#[DataProvider('malformedRows')]
	public function testAMalformedRowRefusesTheWholeFileNamingItsLine(string $bad, string $fragment): void {
		$message = self::refusal(PivFixture::csv([
			PivFixture::row('31-12-2025', 900001, 'A', '0 a 9 años', 'Mujeres', 1),
			$bad,
		]));

		self::assertStringStartsWith('Línea 3 del CSV «Inscritos APS»: ', $message);
		self::assertStringContainsString($fragment, $message);
		self::assertStringEndsWith('Nada se guardó.', $message);
	}

	public function testABlankLineIsNotARow(): void {
		$csv = PivFixture::HEADER . "\r\n\r\n" . PivFixture::row('31-12-2025', 900001, 'A', '0 a 9 años', 'Mujeres', 1) . "\r\n\r\n";

		self::assertSame(1, self::read($csv)['lines']);
	}

	public function testAFileWithNoAgedInscritosIsRefused(): void {
		self::assertSame(
			'El CSV «Inscritos APS» no trae inscritos con tramo de edad: no hay nada que cargar.',
			self::refusal(PivFixture::csv([])),
		);
		self::assertStringContainsString(
			'no trae inscritos con tramo de edad',
			self::refusal(PivFixture::csv([PivFixture::row('31-12-2025', 900001, 'A', 'Sin información', 'Mujeres', 1)])),
		);
	}
}
