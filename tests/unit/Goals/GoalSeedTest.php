<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Goals;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Goals\GoalSeed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The metas seed (acceptance a9): the shipped 2026 file reads into Metas
 * I–VIII with the OOTT's Anexo 3 routes, and a later year is another file,
 * judged by the same rules. Every way a file is refused says so in Spanish,
 * naming the meta.
 */
final class GoalSeedTest extends TestCase {
	private const SHIPPED = __DIR__ . '/../../../seeds/metas-2026.json';

	/** @return array<string, mixed> */
	private static function shipped(): array {
		return json_decode((string)file_get_contents(self::SHIPPED), true, 512, JSON_THROW_ON_ERROR);
	}

	/** @return array<string, mixed> the smallest seed that is one: a REM meta and a manual one */
	private static function minimal(): array {
		return [
			'anio' => 2026,
			'version' => 1,
			'nombre' => 'Metas de prueba',
			'referencia' => 'Una prueba',
			'diccionarios' => ['rem-a' => 'SA_26_V1.0'],
			'indicadores' => [
				[
					'codigo' => 'I',
					'nombre' => 'Uno',
					'indicador' => 'El uno',
					'meta_nacional' => 90,
					'peso' => 50,
					'numerador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => '2026-01', 'hasta' => '2026-12'], 'sumar' => [['codigos' => ['02010420'], 'columnas' => ['col08']]]],
					'denominador' => ['fuente' => 'rem-a', 'periodo' => ['desde' => '2025-10', 'hasta' => '2026-09'], 'sumar' => [['codigos' => ['02010321'], 'columnas' => ['col08']]]],
				],
				['codigo' => 'II', 'nombre' => 'Dos', 'indicador' => 'El dos', 'meta_nacional' => 100, 'peso' => 50, 'manual' => true],
			],
		];
	}

	/** a9: the shipped file is Metas I–VIII, with the metas nacionales and the pesos of the OOTT. */
	public function testTheShippedSeedIsMetasIToViii(): void {
		$metas = GoalSeed::parse(self::shipped(), 2026);

		self::assertSame([2026, 1], [$metas['anio'], $metas['version']]);
		self::assertSame(['rem-a' => 'SA_25_V1.5', 'rem-p' => 'SP_25_V1.2'], $metas['diccionarios'], 'the REM dictionaries Anexo 3 cites');
		self::assertStringContainsString('2025', (string)$metas['nota'], 'the pesos are flagged as 2025\'s');

		$byCode = array_column($metas['indicadores'], null, 'codigo');
		self::assertSame(['I', 'II', 'III.A', 'III.B', 'IV.A', 'IV.B', 'V', 'VI', 'VII', 'VIII'], array_keys($byCode));
		self::assertSame([90.0, 80.0, 48.0, 22.0, 29.0, 90.0, 45.0, 60.0, 15.0, 100.0], array_column($metas['indicadores'], 'meta_nacional'));
		self::assertSame([12.5, 12.5, 6.25, 6.25, 12.5, 12.5, 12.5, 12.5, 6.25, 6.25], array_column($metas['indicadores'], 'peso'));
		self::assertSame(100.0, array_sum(array_column($metas['indicadores'], 'peso')));
		self::assertSame(
			['rem', 'poblacion', 'poblacion', 'poblacion', 'poblacion', 'rem', 'poblacion', 'rem', 'poblacion', null],
			array_column($metas['indicadores'], 'base'),
		);
		self::assertTrue($byCode['VIII']['manual']);
		self::assertNull($byCode['VIII']['numerador']);
	}

	/** a9, a13: Meta I is A03 §A.2 over col08–col11, its denominator October to September less two códigos. */
	public function testMetaIIsItsAnexo3Route(): void {
		$meta = array_column(GoalSeed::parse(self::shipped(), 2026)['indicadores'], null, 'codigo')['I'];

		$numerador = $meta['numerador'];
		self::assertSame(['rem-a', 'A', 202601, 202612], [$numerador['fuente'], $numerador['serie'], $numerador['desde'], $numerador['hasta']]);
		self::assertCount(1, $numerador['sumar']);
		self::assertSame(['02010420', '03500366'], $numerador['sumar'][0]->codigos);
		self::assertSame([8, 9, 10, 11], $numerador['sumar'][0]->columnas);
		self::assertSame(['mes'], $numerador['sumar'][0]->agrupar, 'a route is asked month by month');
		self::assertSame([], $numerador['restar']);

		$denominador = $meta['denominador'];
		self::assertSame([202510, 202609], [$denominador['desde'], $denominador['hasta']], 'the window crosses the year');
		self::assertSame(['02010321'], $denominador['sumar'][0]->codigos);
		self::assertSame(['03500331', '03500334'], $denominador['restar'][0]->codigos, '(J23..M23) minus (J37..M37 + J40..M40)');
		self::assertSame([8, 9, 10, 11], $denominador['restar'][0]->columnas);
		self::assertSame('rem-a', $meta['ruta']['denominador']['fuente'], 'the route is kept as the file wrote it');
	}

	/** a9, a13: Meta II is P12 §A.1 col01 + col02 over the PIV's mujeres 25–64; VII adds two códigos with their own columns. */
	public function testTheSerieProutesAndThePopulationDenominators(): void {
		$byCode = array_column(GoalSeed::parse(self::shipped(), 2026)['indicadores'], null, 'codigo');

		$ii = $byCode['II'];
		self::assertSame(['P1206010', 'P1206020', 'P1206030', 'P1206040', 'P1206050', 'P1206060', 'P1206070', 'P1206080'], $ii['numerador']['sumar'][0]->codigos);
		self::assertSame([1, 2], $ii['numerador']['sumar'][0]->columnas);
		self::assertSame('P', $ii['numerador']['serie']);
		self::assertSame([['desde' => 25, 'hasta' => 64, 'sexo' => 'M', 'prevalencia' => 100.0]], $ii['poblacion']);

		self::assertSame([
			['desde' => 15, 'hasta' => 24, 'sexo' => null, 'prevalencia' => 1.8],
			['desde' => 25, 'hasta' => 44, 'sexo' => null, 'prevalencia' => 6.3],
			['desde' => 45, 'hasta' => 64, 'sexo' => null, 'prevalencia' => 18.3],
			['desde' => 65, 'hasta' => null, 'sexo' => null, 'prevalencia' => 30.6],
		], $byCode['IV.A']['poblacion'], 'ENS 2016-17 prevalences, «65 y más» with no upper age');
		self::assertSame([['desde' => 6, 'hasta' => 6, 'sexo' => null, 'prevalencia' => 100.0]], $byCode['III.B']['poblacion']);

		$vii = $byCode['VII']['numerador']['sumar'];
		self::assertSame([['P3161041'], ['P3161045']], [$vii[0]->codigos, $vii[1]->codigos]);
		self::assertSame([range(6, 37), [1]], [$vii[0]->columnas, $vii[1]->columnas], 'asma col06–col37, EPOC col01');

		// Research: all 19 SP códigos Anexo 3 cites are in SP_25_V1.2.
		$serieP = [];
		foreach ($byCode as $meta) {
			foreach (['numerador', 'denominador'] as $part) {
				if (($meta[$part]['serie'] ?? null) === 'P') {
					foreach ($meta[$part]['sumar'] as $spec) {
						$serieP = [...$serieP, ...$spec->codigos];
					}
				}
			}
		}
		self::assertCount(19, array_unique($serieP));
	}

	/** a9: a later year is another file. It may arrive in the year before, when the metas are fixed. */
	public function testALaterYearIsAnotherFileWithNoCodeChange(): void {
		$next = self::minimal();
		$next['anio'] = 2027;
		$next['indicadores'][0]['numerador']['periodo'] = ['desde' => '2027-01', 'hasta' => '2027-12'];
		$next['indicadores'][0]['denominador']['periodo'] = ['desde' => '2026-10', 'hasta' => '2027-09'];

		$metas = GoalSeed::parse($next, 2026);

		self::assertSame(2027, $metas['anio']);
		self::assertSame([202701, 202712], [$metas['indicadores'][0]['numerador']['desde'], $metas['indicadores'][0]['numerador']['hasta']]);

		$next['anio'] = 2028;
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('El año de las metas (anio) debe estar entre 2009 y 2027.');
		GoalSeed::parse($next, 2026);
	}

	public function testACommittedDenominatorAndAnOptionalNote(): void {
		$seed = self::minimal();
		$seed['indicadores'][0]['denominador'] = ['comprometido' => true];
		$seed['indicadores'][0]['nota'] = ' Solo el compromiso. ';

		$meta = GoalSeed::parse($seed, 2026)['indicadores'][0];

		self::assertSame([GoalSeed::BASE_COMMITTED, null, [], 'Solo el compromiso.'], [$meta['base'], $meta['denominador'], $meta['poblacion'], $meta['nota']]);
		self::assertNull(GoalSeed::parse(self::minimal(), 2026)['nota']);
	}

	/** @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}> */
	public static function refusals(): iterable {
		$meta = static fn (array $seed, array $changes): array => ['indicadores' => [$changes + $seed['indicadores'][0], $seed['indicadores'][1]]] + $seed;

		yield 'an unknown key' => [fn (array $s): array => $s + ['formula' => 'x'], 'El archivo de metas no admite «formula»'];
		yield 'a year before 2009' => [fn (array $s): array => ['anio' => 2008] + $s, 'debe estar entre 2009 y 2027'];
		yield 'a year as text' => [fn (array $s): array => ['anio' => '2026'] + $s, 'debe estar entre 2009 y 2027'];
		yield 'version 0' => [fn (array $s): array => ['version' => 0] + $s, 'un número entero desde 1'];
		yield 'a dictionary of another serie' => [fn (array $s): array => ['diccionarios' => ['rem-x' => 'SX']] + $s, 'diccionarios no admite «rem-x»'];
		yield 'no dictionaries' => [fn (array $s): array => ['diccionarios' => []] + $s, 'diccionarios dice qué diccionario'];
		yield 'no indicadores' => [fn (array $s): array => ['indicadores' => []] + $s, 'indicadores es la lista'];
		yield 'a meta twice' => [fn (array $s): array => ['indicadores' => [$s['indicadores'][0], $s['indicadores'][0]]] + $s, 'La Meta I viene dos veces.'];
		yield 'a codigo that is not one' => [fn (array $s): array => $meta($s, ['codigo' => 'I; DROP']), 'El codigo de un indicador'];
		yield 'an unknown key in a meta' => [fn (array $s): array => $meta($s, ['formula' => 'a/b']), 'Meta I no admite «formula»'];
		yield 'a meta nacional over 100' => [fn (array $s): array => $meta($s, ['meta_nacional' => 120]), 'Meta I: meta_nacional es un porcentaje de 0 a 100'];
		yield 'a peso as es-CL text' => [fn (array $s): array => $meta($s, ['peso' => '12,5']), 'Meta I: peso es un porcentaje'];
		yield 'no denominador' => [fn (array $s): array => $meta($s, ['denominador' => null]), 'Meta I necesita numerador y denominador'];
		yield 'a manual meta with a route' => [fn (array $s): array => ['indicadores' => [$s['indicadores'][0], ['numerador' => []] + $s['indicadores'][1]]] + $s, 'Meta II es manual'];
		yield 'a column past col50' => [fn (array $s): array => $meta($s, ['numerador' => ['sumar' => [['codigos' => ['02010420'], 'columnas' => ['col51']]]] + $s['indicadores'][0]['numerador']]), 'Meta I, numerador: «col51» no es una columna del REM'];
		yield 'a hoja instead of códigos' => [fn (array $s): array => $meta($s, ['numerador' => ['sumar' => [['hoja' => 'A03', 'columnas' => ['col08']]]] + $s['indicadores'][0]['numerador']]), 'Meta I, numerador, sumar no admite «hoja»'];
		yield 'a fuente that is not REM' => [fn (array $s): array => $meta($s, ['numerador' => ['fuente' => 'piv'] + $s['indicadores'][0]['numerador']]), 'Meta I, numerador: La fuente de una consulta es rem-a o rem-p.'];
		yield 'a periodo ending in another year' => [fn (array $s): array => $meta($s, ['numerador' => ['periodo' => ['desde' => '2025-01', 'hasta' => '2025-12']] + $s['indicadores'][0]['numerador']]), 'Meta I, numerador: el periodo debe terminar en 2026'];
		yield 'restar as one term' => [fn (array $s): array => $meta($s, ['denominador' => ['restar' => ['codigos' => ['1']]] + $s['indicadores'][0]['denominador']]), 'Meta I, denominador: sumar es una lista'];
		yield 'a sex FONASA does not write' => [fn (array $s): array => $meta($s, ['denominador' => ['poblacion' => [['desde' => 25, 'sexo' => 'otros']]]]), 'Meta I, denominador: sexo es "mujeres" o "hombres"'];
		yield 'an age range backwards' => [fn (array $s): array => $meta($s, ['denominador' => ['poblacion' => [['desde' => 64, 'hasta' => 25]]]]), 'un grupo va desde una edad hasta otra igual o mayor'];
		yield 'a prevalence of 0' => [fn (array $s): array => $meta($s, ['denominador' => ['poblacion' => [['desde' => 15, 'prevalencia' => 0]]]]), 'prevalencia es un porcentaje mayor que 0'];
		yield 'a population with a fuente too' => [fn (array $s): array => $meta($s, ['denominador' => ['poblacion' => [['desde' => 0]], 'fuente' => 'rem-a']]), 'Meta I, denominador no admite «fuente»'];
		yield 'comprometido false' => [fn (array $s): array => $meta($s, ['denominador' => ['comprometido' => false]]), '"comprometido" solo puede ser true'];
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $change */
	#[DataProvider('refusals')]
	public function testAFileItCannotReadIsRefusedInSpanish(callable $change, string $sentence): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage($sentence);
		GoalSeed::parse(array_filter($change(self::minimal()), static fn (mixed $value): bool => $value !== null), 2026);
	}
}
