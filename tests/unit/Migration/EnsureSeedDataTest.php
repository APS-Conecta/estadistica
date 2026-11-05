<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Migration;

use OCA\Estadistica\Goals\GoalSeed;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Migration\EnsureSeedData;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A shipped seed that cannot be loaded is reported and skipped, whatever the
 * failure: the repair step runs on `occ app:enable`, and a seed must never
 * stop the app from enabling.
 */
final class EnsureSeedDataTest extends TestCase {
	public function testASeedTheDatabaseRefusesIsReportedAndDoesNotStopTheStep(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$goals = $this->createMock(Goals::class);
		$goals->method('version')->willReturn(null);
		$goals->method('store')->willThrowException(new RuntimeException('could not serialize access'));
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		(new EnsureSeedData($db, $goals))->run($output);

		self::assertSame([
			'Estadística: metas-2026.json was not loaded: could not serialize access',
			'Estadística: metas-2027.json was not loaded: could not serialize access',
		], $warnings, 'the first refusal did not stop the next seed');
	}

	/** #50: the step loads every seed the app ships, each judged whole by GoalSeed, so every term by the consulta spec. */
	public function testEveryShippedSeedLoadsAndIsJudgedWhole(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$goals = $this->createMock(Goals::class);
		$goals->method('version')->willReturn(null);
		$stored = [];
		$goals->method('store')->willReturnCallback(static function (array $raw) use (&$stored): array {
			$metas = GoalSeed::parse($raw, 2026);
			$stored[] = $metas['anio'];

			return ['anio' => $metas['anio'], 'version' => $metas['version'], 'indicadores' => count($metas['indicadores'])];
		});
		$output = $this->createMock(IOutput::class);
		$output->expects(self::never())->method('warning');

		(new EnsureSeedData($db, $goals))->run($output);

		self::assertSame([2026, 2027], $stored);
	}
}
