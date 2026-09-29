<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Db\DashboardMapper;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Db\QuestionMapper;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\SavedContent;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Server;
use OCP\Settings\IManager;
use PHPUnit\Framework\TestCase;

/**
 * Shared plumbing for tests against the throwaway instance's real PostgreSQL
 * (make deps && make instance-up, then make integration): app config written
 * the way occ writes it, the drop door driven the way the FilePicker drives
 * it, and saved content as one person saves it. Each test class cleans up the
 * rows it makes.
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
	 * The drop door as the app wires it, acting for $uid, the way the
	 * FilePicker drives it. The session is the one thing stubbed; everything
	 * else is the instance's own (territorio's FilesImportTest).
	 */
	final protected function dropsFor(string $uid): DroppedFiles {
		return new DroppedFiles(
			Server::get(IRootFolder::class),
			$this->sessionOf($uid),
			Server::get(IAppDataFactory::class),
			Server::get(ITempManager::class),
			Server::get(ITimeFactory::class),
			Server::get(YearWindow::class),
			Server::get(IngestStateMapper::class),
			Server::get(Register::class),
			Server::get(PivImporter::class),
		);
	}

	/**
	 * Preguntas and tableros as $uid saves and reads them. The session is the
	 * one thing stubbed: who administers Estadística is the instance's own
	 * answer (its admin group and its delegations). A new instance per call, so
	 * that answer is asked afresh.
	 */
	final protected function savedFor(string $uid): SavedContent {
		return new SavedContent(
			Server::get(QuestionMapper::class),
			Server::get(DashboardMapper::class),
			$this->sessionOf($uid),
			Server::get(IUserManager::class),
			Server::get(IGroupManager::class),
			Server::get(IManager::class),
			Server::get(ITimeFactory::class),
			Server::get(YearWindow::class),
		);
	}

	/** A local file put in $uid's Files as $name, for dropsFor() to be handed. The caller deletes it. */
	final protected function putInFiles(string $uid, string $name, string $local): void {
		Server::get(IRootFolder::class)->getUserFolder($uid)->newFile($name, fopen($local, 'rb'));
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

	private function sessionOf(string $uid): IUserSession {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/** The instance's occ, as www-data runs it; a failure fails the test with occ's own output. */
	final protected function occ(string ...$arguments): void {
		exec(
			'php ' . escapeshellarg(self::OCC) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1',
			$output,
			$status,
		);
		self::assertSame(0, $status, 'occ ' . implode(' ', $arguments) . ' failed: ' . implode("\n", $output));
	}
}
