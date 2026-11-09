<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Goals;

use OCA\Estadistica\Goals\Nota;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The nota (CONTEXT.md): a model's text about what changed, kept only when
 * every number in it is one the stored Resumen shows, as the screen writes
 * it (es-CL: «.» for thousands, «,» for decimals, «%»), or a year or month
 * the Resumen names. The model is Nextcloud's TaskProcessing, faked here at
 * its interface.
 */
final class NotaTest extends TestCase {
	private const FINGERPRINT = 'a3f1c0de';

	/**
	 * Meta V 2099 at October: 45.2 % (1,234 of 2,730), its meta comprometida
	 * 55.5, its meta mínima 62.0 from 2098's cierre of 47.81, which the screen
	 * writes 47,8.
	 *
	 * @return array<string, mixed>
	 */
	private static function resumen(float $porcentaje = 45.2, float $numerador = 1234.0, string $datosAl = '2099-11'): array {
		$month = static fn (string $mes, float $n, float $d, float $p): array => ['mes' => $mes, 'numerador' => $n, 'denominador' => $d, 'porcentaje' => $p, 'origen' => 'datos públicos DEIS', 'fecha_corte' => '2099-11'];

		return [
			'anio' => 2099,
			'nombre' => 'Metas Sanitarias 2099',
			'referencia' => 'Res. Exenta 1',
			'establecimiento' => '900001',
			'metas' => [[
				'codigo' => 'V',
				'nombre' => 'Cobertura de examen en personas de 25 a 64 años',
				'indicador' => 'Personas con examen vigente',
				'meta_nacional' => 60.0,
				'meta_comprometida' => 55.5,
				'manual' => false,
				'estado' => null,
				'mes' => '2099-10',
				'datos_al' => $datosAl,
				'numerador' => $numerador,
				'denominador' => 2730.0,
				'porcentaje' => $porcentaje,
				'cumple' => false,
				'historico' => [
					['anio' => 2098, 'estado' => null, 'cierre' => 47.81, 'meses' => [$month('2098-12', 1300.0, 2719.0, 47.81)], 'ruta' => ['numerador' => null, 'denominador' => null]],
					['anio' => 2099, 'estado' => null, 'cierre' => null, 'meses' => [$month('2099-10', $numerador, 2730.0, $porcentaje)], 'ruta' => ['numerador' => null, 'denominador' => null]],
				],
				'origen' => 'datos públicos DEIS',
				'fecha_corte' => '2099-11',
				'meta_minima' => ['porcentaje' => 62.0, 'base' => 2098, 'motivo' => null],
			]],
		];
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function answers(): array {
		return [
			'thousands' => ['La Meta V 2099 suma 1.234 de 2.730.', true],
			'thousands unwritten' => ['La Meta V 2099 suma 1234 de 2730.', false],
			'a decimal percentage' => ['La Meta V 2099 llegó a 45,2 % en noviembre de 2099.', true],
			'a percentage written close' => ['La Meta V 2099 llegó a 45,2%.', true],
			'a percentage written as a count' => ['La Meta V 2099 llegó a 45,2 puntos.', false],
			'a meta and a meta mínima' => ['Su meta comprometida es 55,5 % y su meta mínima, 62,0 %.', true],
			'a cierre and its years' => ['Cerró 2098 en 47,8 % y en 2099 va en 45,2 %.', true],
			'a month it names' => ['Datos al 11/2099.', true],
			'a year it does not name' => ['Es la cifra más alta desde 2090.', false],
			'an invented number' => ['La Meta V 2099 llegó a 45,2 %, 3 puntos menos que en 2098.', false],
			'a number in other digits' => ['La Meta V 2099 suma ١٢٣٤.', false],
			'rounded otherwise' => ['Cerró 2098 en 47,81 %.', false],
			'rounded to a whole' => ['La Meta V 2099 llegó a 45 %.', false],
			'written with a trailing zero' => ['La Meta V 2099 llegó a 45,20 %.', false],
			'invalid UTF-8' => ["La Meta V 2099 llegó a 99 % \xff", false],
		];
	}

	#[DataProvider('answers')]
	public function testANotaIsKeptOnlyWhenEveryNumberIsOneTheResumenShows(string $answer, bool $kept): void {
		self::assertSame($kept ? $answer : null, Nota::check("  $answer\n", self::resumen()));
	}

	/** @return array<string, array{0: string, 1: bool}> */
	public static function answersOverAFullYear(): array {
		return [
			'points it invents' => ['La Meta V 2099 llegó a 45,2 %, 3 puntos menos que en septiembre.', false],
			'people it invents' => ['Subió 7 puntos y suma 10 personas más.', false],
			'the month of its data' => ['Datos al 11/2099.', true],
		];
	}

	/** A real histórico names every month, so a month alone is no figure: only month/year is. */
	#[DataProvider('answersOverAFullYear')]
	public function testAMonthItNamesPassesOnlyWithItsYear(string $answer, bool $kept): void {
		$resumen = self::resumen();
		$december = $resumen['metas'][0]['historico'][0]['meses'][0];
		$resumen['metas'][0]['historico'][0]['meses'] = array_map(
			static fn (int $month): array => ['mes' => sprintf('2098-%02d', $month)] + $december,
			range(1, 12),
		);
		self::assertSame($kept ? $answer : null, Nota::check($answer, $resumen));
	}

	public function testAnEmptyAnswerIsNoNota(): void {
		self::assertNull(Nota::check(" \n ", self::resumen()));
	}

	/** The task carries the fixed instruction and only the figures that changed, as the screen writes them. */
	public function testAChangeAsksOneTaskWithOnlyTheFiguresThatChanged(): void {
		$scheduled = [];
		(new Nota($this->manager(['core:text2text'], $scheduled), new NullLogger()))
			->request(self::resumen(40.1, 1100.0, '2099-10'), self::resumen(), self::FINGERPRINT);

		self::assertCount(1, $scheduled);
		[$task] = $scheduled;
		self::assertSame(['core:text2text', 'estadistica', null, self::FINGERPRINT], [$task->getTaskTypeId(), $task->getAppId(), $task->getUserId(), $task->getCustomId()]);
		self::assertSame(['input' => Nota::INSTRUCTION . "\n\n"
			. "- Meta V 2099, porcentaje: antes 40,1 %, ahora 45,2 %.\n"
			. "- Meta V 2099, numerador: antes 1.100, ahora 1.234.\n"
			. '- Meta V 2099, datos al: antes octubre de 2099, ahora noviembre de 2099.'], $task->getInput());
	}

	public function testNoProviderAsksNothing(): void {
		$scheduled = [];
		(new Nota($this->manager(['core:text2image'], $scheduled), new NullLogger()))
			->request(self::resumen(40.1), self::resumen(), self::FINGERPRINT);

		self::assertSame([], $scheduled);
	}

	public function testNothingChangedAsksNothing(): void {
		$scheduled = [];
		(new Nota($this->manager(['core:text2text'], $scheduled), new NullLogger()))
			->request(self::resumen(), self::resumen(), self::FINGERPRINT);

		self::assertSame([], $scheduled);
	}

	public function testAProviderThatFailsIsNoNotaAndNoFailure(): void {
		$manager = $this->createStub(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn(['core:text2text']);
		$manager->method('scheduleTask')->willThrowException(new RuntimeException('the provider is down'));

		(new Nota($manager, new NullLogger()))->request(self::resumen(40.1), self::resumen(), self::FINGERPRINT);

		$this->addToAssertionCount(1);
	}

	/**
	 * Nextcloud's TaskProcessing with these task types available, keeping
	 * each task it is asked to schedule in $scheduled.
	 *
	 * @param list<string> $types
	 * @param list<Task> $scheduled
	 */
	private function manager(array $types, array &$scheduled): IManager {
		$manager = $this->createStub(IManager::class);
		$manager->method('getAvailableTaskTypeIds')->willReturn($types);
		$manager->method('scheduleTask')->willReturnCallback(static function (Task $task) use (&$scheduled): void {
			$scheduled[] = $task;
		});

		return $manager;
	}
}
