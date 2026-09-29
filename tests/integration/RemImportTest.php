<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\BackgroundJob\RemJob;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RangeReader;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Server;
use ReflectionMethod;

/**
 * The importer and the drop door against the instance's real PostgreSQL:
 * acceptance a3 (the drop half), a4 and a5, with the #74 negatives first
 * (epidemiologia 3f07b81), and a year the window lets go. Nothing here
 * reaches DEIS: the job only ever runs with a pair queued from a drop, so it
 * never gets as far as its daily check (DeisRepositoryTest covers that, over
 * a fake server).
 *
 * The years 2097–2099 belong to these tests. DEIS will not publish them in
 * this app's lifetime, so no real load is ever touched. The drop cases need a
 * year inside the window, so they use the current year. They skip themselves
 * on an instance that already knows that year (the a21 manual run's data).
 *
 * The user is `admin`, the only account compose.dev.yaml creates (territorio's
 * FilesImportTest).
 */
class RemImportTest extends IntegrationTestCase {
	private const UID = 'admin';

	private const DROPPED = 'estadistica-rem-import-test.zip';

	/** @var list<int> years whose rows and state this test removes */
	private array $years = [2097, 2098, 2099];

	protected function tearDown(): void {
		foreach ($this->years as $year) {
			foreach (['estadistica_rem', 'estadistica_ingest'] as $table) {
				$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE anio = ?", [$year]);
			}
			Server::get(DroppedFiles::class)->remove($year);
		}
		try {
			Server::get(IRootFolder::class)->getUserFolder(self::UID)->get(self::DROPPED)->delete();
		} catch (NotFoundException) {
			// The usual case: this test put nothing in Files.
		}
		ZipFixture::cleanup();
		parent::tearDown();
	}

	/**
	 * The drop door as the app wires it, acting for $uid, the way the
	 * FilePicker drives it. The session is the one thing stubbed; everything
	 * else is the instance's own (territorio's FilesImportTest).
	 */
	private function dropsFor(string $uid): DroppedFiles {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DroppedFiles(
			Server::get(IRootFolder::class),
			$session,
			Server::get(IAppDataFactory::class),
			Server::get(ITempManager::class),
			Server::get(ITimeFactory::class),
			Server::get(YearWindow::class),
			Server::get(IngestStateMapper::class),
		);
	}

	/** A local file put in $uid's Files as $name, for dropsFor() to be handed. The caller deletes it. */
	private function putInFiles(string $uid, string $name, string $local): void {
		Server::get(IRootFolder::class)->getUserFolder($uid)->newFile($name, fopen($local, 'rb'));
	}

	private function import(string $zip, int $year, string $serie = 'A'): IngestState {
		return Server::get(RemImporter::class)->import(new LocalRange($zip), $year, $serie, IngestState::ORIGIN_FILE);
	}

	private function state(int $year, string $serie = 'A'): IngestState {
		return Server::get(IngestStateMapper::class)->findOrNew($year, $serie);
	}

	/** @return array{rows: int, total: float} what a reader of (año, serie) sees */
	private function snapshot(int $year, string $serie = 'A'): array {
		$row = $this->db()->executeQuery(
			'SELECT count(*) AS n, COALESCE(sum(col01), 0) AS total FROM *PREFIX*estadistica_rem WHERE anio = ? AND serie = ?',
			[$year, $serie],
		)->fetch();

		return ['rows' => (int)$row['n'], 'total' => (float)$row['total']];
	}

	/** A complete load of 2098 Serie A to protect: 10 establishments in servicio 91, 10 in 92. */
	private function loadComplete2098(): array {
		$establishments = ZipFixture::establishments(20);
		$servicioOf = array_combine($establishments, [...array_fill(0, 10, '91'), ...array_fill(0, 10, '92')]);
		$state = $this->import(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::member(2098, $establishments, servicioOf: $servicioOf)]), 2098);
		self::assertNull($state->getLastError(), 'the baseline load must succeed');

		return $this->snapshot(2098);
	}

	/** The current year, claimed for this test, unless the instance already holds it. */
	private function dropYear(): int {
		$year = Server::get(YearWindow::class)->currentYear();
		$known = (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_ingest WHERE anio = ?', [$year])->fetchOne();
		if ($known > 0) {
			self::markTestSkipped("this instance already holds REM state for $year; run the integration suite on a throwaway instance");
		}
		$this->years[] = $year;

		return $year;
	}

	/** a5: the whole national member, every establishment and month; the center's share read through config. */
	public function testAFreshYearLoadsTheWholeNationalMember(): void {
		$this->provision(EstablishmentConfig::KEY_DEIS, '900002');
		$member = ZipFixture::member(2099, ZipFixture::establishments(5));

		$state = $this->import(ZipFixture::zip(['SerieA2099.csv' => $member]), 2099);

		self::assertNull($state->getLastError());
		self::assertSame(20, $state->getRowCount(), '5 establishments × 2 months × 2 códigos');
		self::assertSame('2099-02', $state->getFechaCorte());
		self::assertFalse($state->isIncomplete());
		self::assertSame(IngestState::ORIGIN_FILE, $state->getOrigin());
		self::assertSame(['rows' => 20, 'total' => 30.0], $this->snapshot(2099), 'col01 = month: 5 × 2 × (1 + 2)');
		self::assertSame(0, (int)$this->db()->executeQuery(
			'SELECT count(*) FROM *PREFIX*estadistica_rem WHERE anio = ? AND col03 IS NOT NULL',
			[2099],
		)->fetchOne(), 'an empty cell is stored as NULL, never 0');

		$distinct = (int)$this->db()->executeQuery(
			'SELECT count(DISTINCT estab) FROM *PREFIX*estadistica_rem WHERE anio = ? AND serie = ?',
			[2099, 'A'],
		)->fetchOne();
		self::assertGreaterThan(1, $distinct, 'the national extract, not one establishment');

		$center = Server::get(EstablishmentConfig::class)->get()->deisCode;
		$ours = (int)$this->db()->executeQuery(
			'SELECT count(*) FROM *PREFIX*estadistica_rem WHERE anio = ? AND serie = ? AND estab = ?',
			[2099, 'A', (int)$center],
		)->fetchOne();
		self::assertSame(preg_match_all('/;2099;' . $center . ';/', $member), $ours, "the configured center's rows are all there");
	}

	/** #74 and a4: the garbage 200, as a whole file and as a member. */
	public function testGarbageLeavesTheLastGoodLoadAndSaysWhy(): void {
		$before = $this->loadComplete2098();
		$loaded = $this->state(2098)->getLastSuccess();

		$notAZip = $this->import(ZipFixture::file(ZipFixture::GARBAGE), 2098);
		self::assertStringContainsString('no es un ZIP', (string)$notAZip->getLastError());

		$notRem = $this->import(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::GARBAGE]), 2098);
		self::assertStringContainsString('encabezado de una serie REM', (string)$notRem->getLastError());

		self::assertSame($before, $this->snapshot(2098));
		self::assertSame($loaded, $this->state(2098)->getLastSuccess());
		self::assertSame($before['rows'], $this->state(2098)->getRowCount());
	}

	/** a4: a member cut mid-row over a complete load keeps the complete one. */
	public function testATruncatedMemberOverACompleteLoadKeepsTheCompleteOne(): void {
		$before = $this->loadComplete2098();
		$cut = ZipFixture::member(2098, ZipFixture::establishments(20)) . '3;91;2098;9000';

		$state = $this->import(ZipFixture::zip(['SerieA2098.csv' => $cut]), 2098);

		self::assertSame($before, $this->snapshot(2098));
		self::assertStringStartsWith('Se mantiene la carga anterior', (string)$state->getLastError());
		self::assertStringContainsString('cortada', (string)$state->getLastError());
		self::assertFalse($this->state(2098)->isIncomplete(), 'the kept load is the complete one');
		self::assertNotNull($this->state(2098)->getMemberCrc(), 'judged on its content, so the same bytes are not read again');
	}

	/**
	 * The owner's decision for 2025 Serie A: with no complete copy stored, the
	 * cut member loads, flagged «incompleto», and says what is missing.
	 */
	public function testAMemberWithNoCompleteCopyLoadsFlaggedIncompleto(): void {
		$this->loadComplete2098();
		$establishments = [...ZipFixture::establishments(2), ...ZipFixture::establishments(10, 900011)];
		$servicioOf = array_combine($establishments, [...array_fill(0, 2, '91'), ...array_fill(0, 10, '92')]);
		$member = ZipFixture::member(2099, $establishments, servicioOf: $servicioOf) . '3;92;2099;9000';

		$state = $this->import(ZipFixture::zip(['SerieA2099.csv' => $member]), 2099);

		self::assertNull($state->getLastError());
		self::assertTrue($state->isIncomplete());
		self::assertStringContainsString('cortada', (string)$state->getDetail());
		self::assertStringContainsString('Servicio de salud 91: informan 2 establecimientos, frente a 10 en 2098.', (string)$state->getDetail());
		self::assertSame(48, $state->getRowCount(), '12 establishments × 2 months × 2 códigos; the fragment is not a row');
		self::assertSame(48, $this->snapshot(2099)['rows']);
	}

	/** #74 and a4: the fetch that fails halfway through the member. */
	public function testATransferCutMidMemberLeavesTheLastGoodLoad(): void {
		$before = $this->loadComplete2098();
		$range = new LocalRange(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::member(2098, ZipFixture::establishments(20), months: 12)]));
		$halfway = new class($range) implements RangeReader {
			public function __construct(private RangeReader $inner) {
			}

			public function size(): int {
				return $this->inner->size();
			}

			public function read(int $offset, int $length): string {
				return $this->inner->read($offset, $length);
			}

			public function stream(int $offset, int $length) {
				$cut = fopen('php://memory', 'w+b');
				fwrite($cut, $this->inner->read($offset, intdiv($length, 2)));
				rewind($cut);

				return $cut;
			}
		};

		$state = Server::get(RemImporter::class)->import($halfway, 2098, 'A', IngestState::ORIGIN_DEIS);

		self::assertSame($before, $this->snapshot(2098));
		self::assertStringContainsString('llegó cortado', (string)$state->getLastError());
		self::assertSame(IngestState::ORIGIN_FILE, $this->state(2098)->getOrigin(), 'the kept load still says where it came from');
	}

	/** #74: one (año, serie) failing does not stop another. */
	public function testOneYearFailingLeavesAnotherLoaded(): void {
		$failed = $this->import(ZipFixture::zip(['SerieA2097.csv' => ZipFixture::GARBAGE]), 2097);
		$loaded = $this->import(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::member(2098, ZipFixture::establishments(3))]), 2098);

		self::assertNotNull($failed->getLastError());
		self::assertSame(0, $this->snapshot(2097)['rows']);
		self::assertNull($loaded->getLastError());
		self::assertSame(12, $this->snapshot(2098)['rows']);
	}

	/** #74: a header with nothing under it is a failure, never an empty year. */
	public function testAHeaderOnlyMemberIsAFailureNotAnEmptyYear(): void {
		$before = $this->loadComplete2098();

		$state = $this->import(ZipFixture::zip(['SerieA2098.csv' => ZipFixture::header()]), 2098);

		self::assertSame($before, $this->snapshot(2098));
		self::assertStringContainsString('no trae filas de datos', (string)$state->getLastError());
	}

	/**
	 * The Window decision: a year the window let go leaves with its state, in
	 * one transaction, and no other year is touched.
	 */
	public function testForgettingAYearDropsItsRowsAndItsStateAndNothingElse(): void {
		$this->import(ZipFixture::zip(['SerieA2097.csv' => ZipFixture::member(2097, ZipFixture::establishments(2))]), 2097);
		$this->import(ZipFixture::zip(['SerieP2097.csv' => ZipFixture::member(2097, ZipFixture::establishments(2), ['P1206010'], 1)]), 2097, 'P');
		self::assertSame(8, $this->snapshot(2097)['rows'], 'the year to forget was loaded');
		$kept = $this->loadComplete2098();

		Server::get(RemImporter::class)->forget(2097);

		self::assertSame(0, $this->snapshot(2097)['rows']);
		self::assertSame(0, $this->snapshot(2097, 'P')['rows']);
		self::assertNull($this->state(2097)->getId(), 'no state row still says 2097 Serie A is loaded');
		self::assertNull($this->state(2097, 'P')->getId(), 'nor Serie P');
		self::assertSame($kept, $this->snapshot(2098), 'another year is untouched');
		self::assertSame($kept['rows'], $this->state(2098)->getRowCount());
	}

	/**
	 * a3, the drop half: a zip in the admin's Files, picked by path, lands in
	 * IAppData, and the job loads it with the importer the job always calls,
	 * one serie per run, newest year first, then lets the drop go.
	 */
	public function testTheJobLoadsADroppedZipOneSeriePerRunThenLetsItGo(): void {
		$year = $this->dropYear();
		$this->putInFiles(self::UID, self::DROPPED, ZipFixture::zip([
			"SERIE_REM_$year/SerieA$year.csv" => ZipFixture::member($year, ZipFixture::establishments(3)),
			"SERIE_REM_$year/SerieBS$year.csv" => 'never read',
			"SERIE_REM_$year/SerieP$year.csv" => ZipFixture::member($year, ZipFixture::establishments(2), ['P1206010'], 1),
		]));

		self::assertSame(['anio' => $year, 'series' => ['A', 'P']], $this->dropsFor(self::UID)->accept('/' . self::DROPPED));
		self::assertTrue(Server::get(IAppDataFactory::class)->get('estadistica')->getFolder('drops')->fileExists("$year.zip"));
		self::assertTrue($this->state($year, 'A')->getPending());
		self::assertTrue($this->state($year, 'P')->getPending());

		$job = Server::get(RemJob::class);
		self::assertTrue($job->isTimeSensitive(), 'decided at the class\'s creation: Nextcloud ratchets it');
		self::assertSame(3600, $job->getInterval());
		$run = new ReflectionMethod($job, 'run');

		$run->invoke($job, null);
		self::assertSame(12, $this->state($year, 'A')->getRowCount());
		self::assertTrue($this->state($year, 'P')->getPending(), 'one serie per run');
		self::assertTrue(Server::get(DroppedFiles::class)->localCopyOf($year) !== null, 'the drop stays while a serie of it waits');

		$run->invoke($job, null);
		self::assertSame(2, $this->state($year, 'P')->getRowCount());
		self::assertSame(IngestState::ORIGIN_FILE, $this->state($year, 'P')->getOrigin());
		self::assertNull(Server::get(DroppedFiles::class)->localCopyOf($year), 'the drop is let go once both series are loaded');
	}

	public function testADropThatIsNotAZipIsRefusedAndQueuesNothing(): void {
		$year = $this->dropYear();
		$this->putInFiles(self::UID, self::DROPPED, ZipFixture::file('esto no es un zip'));

		try {
			$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
			self::fail('a text file was accepted as a zip');
		} catch (RefusedException $e) {
			self::assertStringContainsString('no es un ZIP', $e->getMessage());
		}
		self::assertFalse(Server::get(IngestStateMapper::class)->hasPending($year));
	}

	public function testADropOutsideTheWindowIsRefusedAndQueuesNothing(): void {
		$this->putInFiles(self::UID, self::DROPPED, ZipFixture::zip(['SerieA2099.csv' => ZipFixture::member(2099, ZipFixture::establishments(2))]));

		try {
			$this->dropsFor(self::UID)->accept('/' . self::DROPPED);
			self::fail('a year outside the window was accepted');
		} catch (RefusedException $e) {
			self::assertStringContainsString('fuera de los años que guarda esta instalación', $e->getMessage());
		}
		self::assertFalse(Server::get(IngestStateMapper::class)->hasPending(2099));
		self::assertNull(Server::get(DroppedFiles::class)->localCopyOf(2099));
	}
}
