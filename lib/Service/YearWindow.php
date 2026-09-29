<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Exception\RefusedException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Which years of national REM history this install keeps: from first_year to
 * the current year, every establishment, every month.
 *
 * The owner's decision (research, 2026-09-28): by default the current year and
 * the three before it (≈ 27M rows, ≈ 4.3 GB), because the whole 2009–2026
 * history (≈ 13 GB) does not fit every host's disk or its whole-database
 * backups. A delegated admin widens it down to 2009 where the host has room.
 *
 * Never chosen means the window slides with the calendar; once chosen, it
 * stays where it was put.
 */
class YearWindow {
	public const KEY = 'first_year';

	/** The first year DEIS publishes Serie A and P for (SERIE_REM_2009.zip). */
	public const EARLIEST = 2009;

	/** The default: the current year and this many before it. */
	public const DEFAULT_SPAN = 3;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
	) {
	}

	public function currentYear(): int {
		return (int)gmdate('Y', $this->time->getTime());
	}

	/** The admin's choice, or null while the default slides. */
	public function chosen(): ?int {
		$stored = $this->appConfig->getValueInt(Application::APP_ID, self::KEY);
		return $stored === 0 ? null : $stored;
	}

	public function firstYear(): int {
		$current = $this->currentYear();
		$first = $this->chosen() ?? $current - self::DEFAULT_SPAN;
		return max(self::EARLIEST, min($first, $current));
	}

	/** @return list<int> */
	public function years(): array {
		return range($this->firstYear(), $this->currentYear());
	}

	/** @throws RefusedException */
	public function choose(int $firstYear): void {
		$current = $this->currentYear();
		if ($firstYear < self::EARLIEST || $firstYear > $current) {
			throw new RefusedException(sprintf(
				'El primer año debe estar entre %d y %d.',
				self::EARLIEST,
				$current,
			));
		}

		$this->appConfig->setValueInt(Application::APP_ID, self::KEY, $firstYear);
	}

	/** @return array{firstYear: int, chosen: ?int, earliest: int, currentYear: int} */
	public function toArray(): array {
		return [
			'firstYear' => $this->firstYear(),
			'chosen' => $this->chosen(),
			'earliest' => self::EARLIEST,
			'currentYear' => $this->currentYear(),
		];
	}
}
