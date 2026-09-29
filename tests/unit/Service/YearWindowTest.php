<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Service;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class YearWindowTest extends TestCase {
	/** @var array<string, int> the app-config double's store */
	private array $stored = [];

	/** A window whose "now" is mid-2026 and whose app config is $this->stored. */
	private function window(string $now = '2026-07-15 12:00:00'): YearWindow {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => $this->stored[$key] ?? $default,
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->stored[$key] = $value;
				return true;
			},
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime($now . ' UTC'));

		return new YearWindow($config, $time);
	}

	public function testNeverChosenItIsTheCurrentYearAndTheThreeBefore(): void {
		$window = $this->window();

		self::assertNull($window->chosen());
		self::assertSame([2023, 2024, 2025, 2026], $window->years());
	}

	public function testTheDefaultSlidesWithTheCalendar(): void {
		self::assertSame(2024, $this->window('2027-01-02 00:00:00')->firstYear());
	}

	public function testAChosenYearStaysWhereItWasPut(): void {
		$this->window()->choose(2009);

		$later = $this->window('2030-03-01 00:00:00');
		self::assertSame(2009, $later->chosen());
		self::assertSame(2009, $later->firstYear());
		self::assertCount(22, $later->years());
	}

	public function testAStoredYearOutsideTheRangeIsClampedNotTrusted(): void {
		$this->stored[YearWindow::KEY] = 1999;
		self::assertSame(2009, $this->window()->firstYear());

		$this->stored[YearWindow::KEY] = 2031;
		self::assertSame([2026], $this->window()->years());
	}

	/** @return array<string, array{int}> */
	public static function outOfRange(): array {
		return ['before DEIS publishes' => [2008], 'the future' => [2027], 'nothing sent' => [0]];
	}

	/** @dataProvider outOfRange */
	public function testChoosingOutsideTheRangeIsRefusedInSpanishAndStoresNothing(int $year): void {
		try {
			$this->window()->choose($year);
			self::fail('the year was accepted');
		} catch (RefusedException $e) {
			self::assertSame('El primer año debe estar entre 2009 y 2026.', $e->getMessage());
		}

		self::assertSame([], $this->stored);
	}

	public function testToArrayIsWhatTheSectionRenders(): void {
		self::assertSame(
			['firstYear' => 2023, 'chosen' => null, 'earliest' => 2009, 'currentYear' => 2026],
			$this->window()->toArray(),
		);
	}
}
