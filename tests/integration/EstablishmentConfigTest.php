<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\Service\EstablishmentConfig;
use OCP\Server;

/**
 * The seam a1 rests on, end to end on a real instance: keys written the way
 * the suite's provisioner writes them are read back by the app. Fabricated codes
 * only (acceptance a2).
 */
class EstablishmentConfigTest extends IntegrationTestCase {
	private function read(): array {
		return Server::get(EstablishmentConfig::class)->get()->jsonSerialize();
	}

	public function testKeysWrittenTheWayPhase16WritesThemReadBack(): void {
		$this->provision(EstablishmentConfig::KEY_DEIS, '900001');
		$this->provision(EstablishmentConfig::KEY_TYPE, 'CESFAM');
		$this->provision(EstablishmentConfig::KEY_CUT, '99001');

		self::assertSame(
			['deisCode' => '900001', 'type' => 'CESFAM', 'comunaCut' => '99001', 'configured' => true],
			$this->read(),
		);
	}

	public function testAnEmptyCodeReadsAsNotConfigured(): void {
		$this->provision(EstablishmentConfig::KEY_DEIS, '');

		self::assertFalse($this->read()['configured']);
	}

	public function testAnUnusableCodeReadsAsNotConfiguredRatherThanBreakingThePage(): void {
		$this->provision(EstablishmentConfig::KEY_DEIS, '99-001');

		self::assertFalse($this->read()['configured']);
	}
}
