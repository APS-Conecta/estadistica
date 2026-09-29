<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Query;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QuerySpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The consulta spec's door (acceptance a7, a16): what it reads a spec as, and
 * every way a spec is refused in Spanish before any SQL exists.
 */
final class QuerySpecTest extends TestCase {
	private const YEAR = 2026;

	/** The smallest spec that is a question. */
	private const MINIMAL = [
		'fuente' => 'rem-a',
		'periodo' => ['desde' => '2026-01', 'hasta' => '2026-03'],
		'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col01']],
	];

	/** @param array<string, mixed> $changes merged into MINIMAL, one level deep; null removes the key */
	private static function spec(array $changes = []): array {
		$spec = self::MINIMAL;
		foreach ($changes as $key => $value) {
			if ($value === null) {
				unset($spec[$key]);
			} elseif (is_array($value) && is_array($spec[$key] ?? null) && !array_is_list($value)) {
				$spec[$key] = $value + $spec[$key];
			} else {
				$spec[$key] = $value;
			}
		}

		return $spec;
	}

	public function testAWholeSpecReadsIntoItsParts(): void {
		$spec = QuerySpec::from([
			'fuente' => 'rem-p',
			'periodo' => ['desde' => '2025-10', 'hasta' => '2026-09'],
			'filtros' => ['hoja' => ' A03 ', 'seccion' => 'SECCIÓN A.2', 'codigos' => ['02010420', '03500366', '02010420'], 'columnas' => ['col11', 'col08', 'col09', 'col08']],
			'agrupar' => ['mes', 'servicio'],
			'metrica' => 'promedio_establecimiento',
			'comparar' => ['nacional' => true, 'pares' => ['servicio' => ' Servicio de Salud Uno ', 'tipo' => true]],
		], self::YEAR);

		self::assertSame(['rem-p', 'P'], [$spec->fuente, $spec->serie]);
		self::assertSame([202510, 202609, 2025, 2026], [$spec->desde, $spec->hasta, $spec->firstYear(), $spec->lastYear()], 'Meta I\'s window crosses a year');
		self::assertSame(['A03', 'SECCIÓN A.2'], [$spec->hoja, $spec->seccion]);
		self::assertSame(['02010420', '03500366'], $spec->codigos, 'a código named twice is asked for once');
		self::assertSame([8, 9, 11], $spec->columnas, 'columns ascending and once, whatever order they came in');
		self::assertSame(['mes', 'servicio'], $spec->agrupar);
		self::assertSame('promedio_establecimiento', $spec->metrica);
		self::assertTrue($spec->nacional);
		self::assertSame(['tipo' => true, 'servicio' => 'Servicio de Salud Uno'], $spec->pares, 'traits in TRAITS order, trimmed');
	}

	public function testTheDefaultsAreASumWithNoGroupingAndNoComparison(): void {
		$spec = QuerySpec::from(self::MINIMAL, self::YEAR);

		self::assertSame([null, null, [], 'suma', false, []], [$spec->hoja, $spec->seccion, $spec->agrupar, $spec->metrica, $spec->nacional, $spec->pares]);
	}

	public function testAHojaAloneIsAQuestion(): void {
		$spec = QuerySpec::from(self::spec(['filtros' => ['hoja' => 'A03', 'codigos' => null]]), self::YEAR);

		self::assertSame(['A03', []], [$spec->hoja, $spec->codigos]);
	}

	public function testNullIsAnAbsentOptionalKey(): void {
		$spec = QuerySpec::from(self::spec(['agrupar' => null, 'metrica' => null, 'comparar' => ['nacional' => null, 'pares' => null]]), self::YEAR);

		self::assertSame([[], 'suma', false, []], [$spec->agrupar, $spec->metrica, $spec->nacional, $spec->pares]);
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function tampered(): array {
		return [
			// a16: an injected key, at every level
			'a key the spec does not have' => [self::spec(['sql' => 'DROP TABLE oc_estadistica_rem']), 'La consulta no admite «sql»'],
			'a key the filtros do not have' => [self::spec(['filtros' => ['estab' => '900001']]), 'filtros no admite «estab»'],
			'a key the periodo does not have' => [self::spec(['periodo' => ['anio' => '2026']]), 'periodo no admite «anio»'],
			'a key comparar does not have' => [self::spec(['comparar' => ['local' => true]]), 'comparar no admite «local»'],
			'a peer trait that is not one' => [self::spec(['comparar' => ['pares' => ['nombre' => 'x']]]), 'comparar.pares no admite «nombre»'],
			'a list where the spec is an object' => [[1, 2], 'La consulta no admite «0»'],
			// a16: an unknown column
			'col51' => [self::spec(['filtros' => ['columnas' => ['col51']]]), '«col51» no es una columna del REM'],
			'col00' => [self::spec(['filtros' => ['columnas' => ['col00']]]), '«col00» no es una columna del REM'],
			'col8' => [self::spec(['filtros' => ['columnas' => ['col8']]]), '«col8» no es una columna del REM'],
			'a column carrying SQL' => [self::spec(['filtros' => ['columnas' => ['col01; DELETE FROM oc_users']]]), 'no es una columna del REM'],
			'a column as a number' => [self::spec(['filtros' => ['columnas' => [1]]]), '«1» no es una columna del REM'],
			'no column' => [self::spec(['filtros' => ['columnas' => null]]), 'Elija al menos una columna'],
			'an empty column list' => [self::spec(['filtros' => ['columnas' => []]]), 'Elija al menos una columna'],
			// a16: a bad aggregate
			'max' => [self::spec(['metrica' => 'max']), 'La métrica «max» no existe: es suma o promedio_establecimiento'],
			'an aggregate carrying SQL' => [self::spec(['metrica' => 'sum(col01)) FROM oc_users --']), 'no existe'],
			'an aggregate as an object' => [self::spec(['metrica' => ['suma']]), 'no existe'],
			// a7: at most two dimensions
			'a third dimension' => [self::spec(['agrupar' => ['anio', 'mes', 'codigo']]), 'Se agrupa por 2 dimensiones como máximo'],
			'a dimension that is not one' => [self::spec(['agrupar' => ['estab']]), 'No se puede agrupar por «estab»'],
			'a dimension twice' => [self::spec(['agrupar' => ['mes', 'mes']]), 'agrupa dos veces'],
			'agrupar as a string' => [self::spec(['agrupar' => 'mes']), 'agrupar es una lista'],
			// the fuente
			'no fuente' => [self::spec(['fuente' => null]), 'La fuente de una consulta es rem-a o rem-p'],
			'the register as a fuente' => [self::spec(['fuente' => 'establecimientos']), 'La fuente de una consulta es rem-a o rem-p'],
			// the periodo
			'no periodo' => [self::spec(['periodo' => null]), 'La consulta debe decir su periodo'],
			'a month 13' => [self::spec(['periodo' => ['hasta' => '2026-13']]), 'cada uno como AAAA-MM'],
			'a date, not a month' => [self::spec(['periodo' => ['desde' => '2026-01-01']]), 'cada uno como AAAA-MM'],
			'a year before DEIS' => [self::spec(['periodo' => ['desde' => '2008-12']]), 'El año debe estar entre 2009 y 2026'],
			'a year not yet here' => [self::spec(['periodo' => ['hasta' => '2027-01']]), 'El año debe estar entre 2009 y 2026'],
			'a periodo backwards' => [self::spec(['periodo' => ['desde' => '2026-03', 'hasta' => '2026-01']]), 'termina antes de empezar'],
			// what is summed
			'no hoja and no código' => [self::spec(['filtros' => ['codigos' => null]]), 'Elija una hoja o al menos una prestación'],
			'a sección without its hoja' => [self::spec(['filtros' => ['seccion' => 'SECCIÓN A']]), 'falta la hoja'],
			'a blank hoja' => [self::spec(['filtros' => ['hoja' => '  ']]), 'La hoja es su nombre en el REM'],
			'a código as a number' => [self::spec(['filtros' => ['codigos' => [2010420]]]), '«2010420» no es un código de prestación'],
			'a código carrying SQL' => [self::spec(['filtros' => ['codigos' => ["02010420') OR ('1'='1"]]]), 'no es un código de prestación'],
			'códigos as an object' => [self::spec(['filtros' => ['codigos' => ['a' => '02010420']]]), 'son una lista de códigos'],
			'too many códigos' => [self::spec(['filtros' => ['codigos' => array_map(static fn (int $i): string => sprintf('%08d', $i), range(1, 1001))]]), 'hasta 1.000 prestaciones'],
			// the comparison
			'nacional as a word' => [self::spec(['comparar' => ['nacional' => 'si']]), 'comparar.nacional es true o false'],
			'no peer trait' => [self::spec(['comparar' => ['pares' => []]]), 'Elija en qué se parecen los pares'],
			'a peer value that is a number' => [self::spec(['comparar' => ['pares' => ['servicio' => 5]]]), 'pares.servicio es un valor de la Base de Establecimientos'],
			'a peer value that is false' => [self::spec(['comparar' => ['pares' => ['tipo' => false]]]), 'pares.tipo es un valor'],
		];
	}

	/** @param array<array-key, mixed> $raw */
	#[DataProvider('tampered')]
	public function testATamperedSpecIsRefusedInSpanish(array $raw, string $sentence): void {
		try {
			QuerySpec::from($raw, self::YEAR);
			self::fail('the spec was read');
		} catch (RefusedException $e) {
			self::assertStringContainsString($sentence, $e->getMessage());
			self::assertStringNotContainsString('SQLSTATE', $e->getMessage(), 'a refusal speaks Spanish, not the database');
		}
	}

	public function testARefusalNeverEchoesAPageOfInputBack(): void {
		try {
			QuerySpec::from(self::spec(['metrica' => str_repeat('x', 10_000)]), self::YEAR);
			self::fail('the spec was read');
		} catch (RefusedException $e) {
			self::assertLessThan(200, mb_strlen($e->getMessage()));
		}
	}
}
