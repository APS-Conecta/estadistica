<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Domain;

use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Fabricated codes only (900001, 99001): no real establishment or comuna may
 * appear in tests (acceptance a2) — both lie outside the DEIS register and the
 * CUT range.
 */
class EstablishmentTest extends TestCase {
	public function testAConfiguredEstablishmentCarriesItsThreeFacts(): void {
		$establishment = Establishment::of(' 900001 ', ' CESFAM ', '99001');

		self::assertTrue($establishment->isConfigured());
		self::assertSame(
			['deisCode' => '900001', 'type' => 'CESFAM', 'comunaCut' => '99001', 'configured' => true],
			$establishment->jsonSerialize(),
		);
	}

	public function testNoneIsNotConfigured(): void {
		self::assertFalse(Establishment::none()->isConfigured());
		self::assertSame(
			['deisCode' => '', 'type' => '', 'comunaCut' => '', 'configured' => false],
			Establishment::none()->jsonSerialize(),
		);
	}

	public function testTheCutIsOptional(): void {
		self::assertSame('', Establishment::of('900001')->comunaCut);
	}

	/** @return array<string, array{string}> */
	public static function notADeisCode(): array {
		return [
			'five digits' => ['90000'],
			'seven digits' => ['9000011'],
			'the pre-2014 SS-LLL form' => ['99-001'],
			'letters' => ['CESFAM'],
			'empty' => [''],
		];
	}

	/** @dataProvider notADeisCode */
	public function testAnythingButSixDigitsIsRefusedInSpanish(string $code): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no es un código DEIS de establecimiento');

		Establishment::of($code);
	}

	public function testAMalformedCutIsRefusedInSpanish(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no es un código CUT de comuna');

		Establishment::of('900001', 'CESFAM', '9901');
	}
}
