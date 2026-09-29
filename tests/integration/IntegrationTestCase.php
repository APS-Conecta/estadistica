<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests against the throwaway instance's real PostgreSQL
 * (make deps && make instance-up, then make integration): app config written
 * the way occ writes it. Each test class cleans up the rows it makes.
 */
abstract class IntegrationTestCase extends TestCase {
	/** The instance's occ, beside the lib/base.php the bootstrap requires. */
	private const OCC = '/var/www/html/occ';

	/** @var array<string, ?string> app-config keys this test wrote, with what to put back (null = absent) */
	private array $restore = [];

	final protected function db(): IDBConnection {
		return Server::get(IDBConnection::class);
	}

	final protected function appConfig(): IAppConfig {
		return Server::get(IAppConfig::class);
	}

	/**
	 * Writes a key exactly the way gestion's phase 16 does: `occ config:app:set`
	 * with no --type, which stores VALUE_MIXED (core SetConfig, "VALUE_MIXED as
	 * default"). Through occ itself, because OCP's IAppConfig has no mixed
	 * setter, and an app that only read keys it had typed itself would pass a
	 * setValueString test and fail on every provisioned install. The in-process
	 * cache is then reloaded, so the read that follows sees what occ wrote.
	 */
	final protected function provision(string $key, string $value): void {
		$config = $this->appConfig();
		if (!array_key_exists($key, $this->restore)) {
			$this->restore[$key] = $config->hasKey(Application::APP_ID, $key)
				? (string)$config->getDetails(Application::APP_ID, $key)['value']
				: null;
		}
		$this->occ('config:app:set', Application::APP_ID, $key, '--value=' . $value);
		$config->clearCache(true);
	}

	protected function tearDown(): void {
		foreach ($this->restore as $key => $value) {
			if ($value === null) {
				$this->occ('config:app:delete', Application::APP_ID, $key);
			} else {
				$this->occ('config:app:set', Application::APP_ID, $key, '--value=' . $value);
			}
		}
		if ($this->restore !== []) {
			$this->appConfig()->clearCache(true);
		}
		$this->restore = [];
		parent::tearDown();
	}

	/** The instance's occ, as www-data runs it; a failure fails the test with occ's own output. */
	private function occ(string ...$arguments): void {
		exec(
			'php ' . escapeshellarg(self::OCC) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
			$output,
			$status,
		);
		self::assertSame(0, $status, 'occ ' . implode(' ', $arguments) . ' failed: ' . implode("\n", $output));
	}
}
