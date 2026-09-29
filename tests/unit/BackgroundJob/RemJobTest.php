<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\BackgroundJob;

use OCA\Estadistica\BackgroundJob\RemJob;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Rem\HttpRange;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\RemImporter;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * What one hourly tick does: forget a year the window let go, else load one
 * queued pair (from its drop, else from DEIS), else run the daily check when
 * it is due. Each test pins one branch, and that nothing after it ran.
 */
class RemJobTest extends TestCase {
	private IngestStateMapper&MockObject $states;

	private DroppedFiles&MockObject $drops;

	private RemImporter&MockObject $importer;

	private DeisRepository&MockObject $deis;

	protected function setUp(): void {
		$this->states = $this->createMock(IngestStateMapper::class);
		$this->drops = $this->createMock(DroppedFiles::class);
		$this->importer = $this->createMock(RemImporter::class);
		$this->deis = $this->createMock(DeisRepository::class);
	}

	private function job(): RemJob {
		$window = $this->createMock(YearWindow::class);
		$window->method('firstYear')->willReturn(2023);

		return new RemJob($this->createMock(ITimeFactory::class), $this->states, $this->drops, $this->importer, $this->deis, $window);
	}

	private function tick(): void {
		$job = $this->job();
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	private static function state(int $anio, string $serie): IngestState {
		$state = new IngestState();
		$state->setAnio($anio);
		$state->setSerie($serie);

		return $state;
	}

	/** One queued pair, 2026 Serie A, inside the 2023–2026 window. */
	private function queue(): void {
		$this->states->method('findAll')->willReturn([self::state(2026, 'A')]);
		$this->states->method('nextPending')->willReturn(self::state(2026, 'A'));
	}

	public function testTheJobIsHourlyTimeSensitiveAndNeverRunsTwiceAtOnce(): void {
		$job = $this->job();

		self::assertSame(3600, $job->getInterval());
		self::assertTrue($job->isTimeSensitive(), 'decided at the class\'s creation: Nextcloud ratchets it');
		self::assertFalse($job->getAllowParallelRuns());
	}

	public function testAYearTheWindowLetGoIsForgottenBeforeAnythingElse(): void {
		$this->states->method('findAll')->willReturn([self::state(2026, 'A'), self::state(2021, 'A'), self::state(2020, 'P')]);
		$this->importer->expects(self::once())->method('forget')->with(2020);
		$this->deis->expects(self::once())->method('forget')->with(2020);
		$this->drops->expects(self::once())->method('remove')->with(2020);
		$this->states->expects(self::never())->method('nextPending');
		$this->importer->expects(self::never())->method('import');
		$this->deis->expects(self::never())->method('check');

		$this->tick();
	}

	/**
	 * The rows go last: they are what makes the year «outside the window», so
	 * while they stand the next tick forgets it again, whole.
	 */
	public function testAForgottenYearLosesItsEtagAndItsDropBeforeItsRows(): void {
		$calls = [];
		$this->states->method('findAll')->willReturn([self::state(2020, 'P')]);
		$this->deis->method('forget')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'etag';
		});
		$this->drops->method('remove')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'drop';
		});
		$this->importer->method('forget')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'rows';
		});

		$this->tick();

		self::assertSame(['etag', 'drop', 'rows'], $calls);
	}

	/**
	 * A tick that dies part-way keeps the rows, so the year is still outside
	 * the window next time. Forgetting the rows first stranded the kept ETag:
	 * nothing offered the year again, and widened back it read as unchanged.
	 */
	public function testAForgetThatFailsPartWayKeepsTheRowsSoTheNextTickRetriesIt(): void {
		$this->states->method('findAll')->willReturn([self::state(2020, 'P')]);
		$this->drops->method('remove')->willThrowException(new \RuntimeException('storage unavailable'));
		$this->importer->expects(self::never())->method('forget');

		$this->expectException(\RuntimeException::class);
		$this->tick();
	}

	public function testAQueuedPairWithADropIsLoadedFromTheDrop(): void {
		$this->queue();
		$local = (string)tempnam(sys_get_temp_dir(), 'estadistica-');
		$this->drops->method('localCopyOf')->with(2026)->willReturn($local);
		$this->states->method('hasPending')->with(2026)->willReturn(false);
		$this->importer->expects(self::once())->method('import')
			->with(self::isInstanceOf(LocalRange::class), 2026, 'A', IngestState::ORIGIN_FILE);
		$this->deis->expects(self::never())->method('zip');
		$this->drops->expects(self::once())->method('remove')->with(2026);

		$this->tick();

		self::assertFileDoesNotExist($local, 'the local copy is deleted after the load');
	}

	private static function failed(int $anio, string $serie, string $error): IngestState {
		$state = self::state($anio, $serie);
		$state->setLastError($error);

		return $state;
	}

	/** @return array<string, array{string}> */
	public static function brokeOnOurSide(): array {
		return [
			'an internal error' => [RemImporter::INTERNAL],
			'a load that never finished' => [RemImporter::UNFINISHED],
		];
	}

	/**
	 * A load that broke on our side keeps the admin's file, so the next queue
	 * of its year retries it rather than falling back to DEIS's copy.
	 *
	 * @dataProvider brokeOnOurSide
	 */
	public function testADropWhoseLoadBrokeOnOurSideIsKeptForTheRetry(string $error): void {
		$this->states->method('findAll')->willReturn([self::failed(2026, 'A', $error)]);
		$this->states->method('nextPending')->willReturn(self::state(2026, 'A'));
		$this->drops->method('localCopyOf')->willReturn((string)tempnam(sys_get_temp_dir(), 'estadistica-'));
		$this->states->method('hasPending')->willReturn(false);
		$this->importer->method('import')->willReturn(self::failed(2026, 'A', $error));
		$this->drops->expects(self::never())->method('remove');

		$this->tick();
	}

	/** The other serie of the year loading cleanly does not take the file its broken sibling needs. */
	public function testASiblingThatBrokeKeepsTheDropWhenTheOtherSerieLoads(): void {
		$this->states->method('findAll')->willReturn([self::failed(2026, 'A', RemImporter::INTERNAL), self::state(2026, 'P')]);
		$this->states->method('nextPending')->willReturn(self::state(2026, 'P'));
		$this->drops->method('localCopyOf')->willReturn((string)tempnam(sys_get_temp_dir(), 'estadistica-'));
		$this->states->method('hasPending')->willReturn(false);
		$this->importer->method('import')->willReturn(self::state(2026, 'P'));
		$this->drops->expects(self::never())->method('remove');

		$this->tick();
	}

	/** A file the importer refused is no use again: it goes, as a loaded one does. */
	public function testARefusedDropIsRemoved(): void {
		$refusal = 'El archivo no es un zip, o está dañado.';
		$this->states->method('findAll')->willReturn([self::failed(2026, 'A', $refusal)]);
		$this->states->method('nextPending')->willReturn(self::state(2026, 'A'));
		$this->drops->method('localCopyOf')->willReturn((string)tempnam(sys_get_temp_dir(), 'estadistica-'));
		$this->states->method('hasPending')->willReturn(false);
		$this->importer->method('import')->willReturn(self::failed(2026, 'A', $refusal));
		$this->drops->expects(self::once())->method('remove')->with(2026);

		$this->tick();
	}

	/** a3: the same importer the drop uses, over DEIS's zip. */
	public function testAQueuedPairWithNoDropIsLoadedFromDeis(): void {
		$this->queue();
		$this->drops->method('localCopyOf')->willReturn(null);
		$zip = new HttpRange($this->createMock(IClient::class), 'https://repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_2026.zip', DeisRepository::USER_AGENT, new NullLogger());
		$this->deis->method('zip')->with(2026)->willReturn($zip);
		$this->importer->expects(self::once())->method('import')
			->with(self::identicalTo($zip), 2026, 'A', IngestState::ORIGIN_DEIS);
		$this->importer->expects(self::never())->method('fail');
		$this->drops->expects(self::never())->method('remove');

		$this->tick();
	}

	public function testAPairThatCannotReachDeisSaysWhyOnItsRow(): void {
		$this->queue();
		$this->drops->method('localCopyOf')->willReturn(null);
		$this->deis->method('zip')->willThrowException(new RefusedException('Esta instalación está configurada sin acceso a internet, así que no consulta el DEIS.'));
		$this->importer->expects(self::once())->method('fail')->with(2026, 'A', self::stringContains('sin acceso a internet'));
		$this->importer->expects(self::never())->method('import');

		$this->tick();
	}

	/** @return array<string, array{bool}> */
	public static function due(): array {
		return ['the day is up' => [true], 'checked today' => [false]];
	}

	/** @dataProvider due */
	public function testAnIdleTickChecksDeisOnlyWhenTheDayIsUp(bool $due): void {
		$this->states->method('findAll')->willReturn([]);
		$this->states->method('nextPending')->willReturn(null);
		$this->deis->method('checkDue')->willReturn($due);
		$this->drops->expects(self::once())->method('removeStale');
		$this->deis->expects($due ? self::once() : self::never())->method('check');
		$this->importer->expects(self::never())->method('import');

		$this->tick();
	}
}
