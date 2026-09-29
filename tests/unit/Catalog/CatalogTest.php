<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Catalog;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Catalog\DictionaryParser;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCA\Estadistica\Xlsx\XlsxReader;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance a6, the decision half: which dictionary labels each
 * (año, código, col), and what is «sin descripción» and counted. The
 * candidates are what Catalog::labels reads from estadistica_code; here they
 * come from a synthetic dictionary parsed for two years (EnrichmentTest runs
 * the same through the database).
 */
class CatalogTest extends TestCase {
	protected function tearDown(): void {
		XlsxFixture::cleanup();
	}

	/**
	 * The fixture dictionary, as the candidate rows of the years given.
	 *
	 * @param list<int> $years
	 * @return array<string, list<array{anio: int, hoja: string, seccion: string, prestacion: string, columnas: array<int, string>}>>
	 */
	private static function candidates(array $years): array {
		$entries = DictionaryParser::parse(XlsxReader::open(XlsxFixture::dictionary()))['entries'];
		$candidates = [];
		foreach ($years as $anio) {
			foreach ($entries as $entry) {
				$candidates[$entry['codigo']][] = ['anio' => $anio] + array_diff_key($entry, ['codigo' => true]);
			}
		}

		return $candidates;
	}

	public function testAYearWithItsOwnDictionaryReadsTheWholeLabelOfEachCodigoAndColumn(): void {
		$labels = Catalog::resolve(2099, true, ['02010420'], self::candidates([2099]));

		self::assertSame(
			[
				'hoja' => 'A03',
				'seccion' => 'SECCIÓN A: PRIMERA SECCIÓN',
				'prestacion' => 'Evaluación › Normal',
				'columna' => 'POR EDAD › Menos de 1 año › Mujeres',
				'nota' => null,
			],
			Catalog::describe($labels['02010420'], 2099, 5),
		);
	}

	public function testAYearWithoutADictionaryTakesTheNearestLaterYearsLabelAndSaysSo(): void {
		$candidates = self::candidates([2098, 2099]);
		$candidates['02010420'][1]['prestacion'] = 'Etiqueta de un año más lejano';

		$label = Catalog::resolve(2097, false, ['02010420'], $candidates)['02010420'];

		self::assertSame(2098, $label['de']);
		self::assertSame('Evaluación › Normal', $label['prestacion'], 'the nearest later year, not the furthest');
		self::assertSame('etiqueta de 2098', Catalog::describe($label, 2097, 1)['nota']);
	}

	public function testAYearWithItsOwnDictionaryNeverBorrowsWhatItLacks(): void {
		$candidates = self::candidates([2099]);

		$label = Catalog::resolve(2098, true, ['02010420'], $candidates)['02010420'];

		self::assertNull($label['de'], 'códigos and columns change by year (DEIS): a later year\'s label may mean something else');
		self::assertSame(Catalog::NO_LABEL, $label['prestacion']);
	}

	/** a6: an unknown código is «sin descripción», and it is counted for the admin section. */
	public function testAnUnknownCodigoIsSinDescripcionAndCounted(): void {
		$labels = Catalog::resolve(2099, true, ['02010420', '03500366', '09000001'], self::candidates([2099]));

		self::assertSame(
			['hoja' => '', 'seccion' => '', 'prestacion' => 'sin descripción', 'columna' => 'sin descripción', 'nota' => null],
			Catalog::describe($labels['09000001'], 2099, 1),
		);
		self::assertSame(1, Catalog::unmapped($labels));
	}

	public function testAColumnTheDictionaryDoesNotNameIsSinDescripcion(): void {
		$label = Catalog::resolve(2099, true, ['06900300'], self::candidates([2099]))['06900300'];

		self::assertSame('TOTAL › Hombres', Catalog::describe($label, 2099, 2)['columna']);
		self::assertSame(Catalog::NO_LABEL, Catalog::describe($label, 2099, 3)['columna'], 'this row has no third figure');
	}
}
