<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Migration;

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
		$output = $this->createMock(IOutput::class);
		$output->expects(self::atLeastOnce())->method('warning')
			->with(self::stringContains('metas-2026.json was not loaded: could not serialize access'));

		(new EnsureSeedData($db, $goals))->run($output);
	}
}
