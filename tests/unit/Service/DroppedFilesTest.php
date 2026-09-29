<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Service;

use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The REM door: a dropped zip is stored in the app's storage, then its series
 * are queued for the job. Both halves have a second writer: the job removes a
 * year's zip, and a re-drop replaces it.
 */
class DroppedFilesTest extends TestCase {
	private IngestStateMapper&MockObject $states;

	private IAppData&MockObject $appData;

	private ISimpleFolder&MockObject $drops;

	protected function setUp(): void {
		$this->states = $this->createMock(IngestStateMapper::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->drops = $this->createMock(ISimpleFolder::class);
	}

	protected function tearDown(): void {
		ZipFixture::cleanup();
	}

	/** DEIS's 2026 zip, with both series. */
	private static function zip(): string {
		$establishments = ZipFixture::establishments(3);

		return ZipFixture::zip([
			'SERIE_REM_2026/SerieA2026.csv' => ZipFixture::member(2026, $establishments),
			'SERIE_REM_2026/SerieP2026.csv' => ZipFixture::member(2026, $establishments),
		]);
	}

	private function files(string $zip): DroppedFiles {
		$file = $this->createMock(File::class);
		$file->method('getSize')->willReturn((int)filesize($zip));
		$file->method('fopen')->willReturnCallback(static fn () => fopen($zip, 'rb'));
		$home = $this->createMock(Folder::class);
		$home->method('get')->willReturn($file);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($home);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('delegado');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturn($this->appData);
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => (string)tempnam(sys_get_temp_dir(), 'estadistica-'));

		$window = $this->createMock(YearWindow::class);
		$window->method('firstYear')->willReturn(2023);
		$window->method('currentYear')->willReturn(2026);

		return new DroppedFiles($root, $session, $factory, $temp, $this->createMock(ITimeFactory::class), $window,
			$this->states, $this->createMock(Register::class), $this->createMock(PivImporter::class), $this->createMock(Goals::class));
	}

	/**
	 * Both series are queued in one statement: a failure queues neither, so no
	 * serie of a stored drop is left unqueued while the job removes the file
	 * once the other serie has loaded.
	 */
	public function testAZipsSeriesAreQueuedTogetherInOneCall(): void {
		$this->appData->method('getFolder')->willReturn($this->drops);
		$this->states->expects(self::once())->method('markPending')->with(2026, 'A', 'P');

		self::assertSame(['anio' => 2026, 'series' => ['A', 'P']], $this->files(self::zip())->accept('/REM/SERIE_REM_2026.zip'));
	}

	/**
	 * A re-drop of a year the job is removing at that moment: the zip is written
	 * without looking first (newFile writes over), so there is no gap between a
	 * look and a write for the job's delete to fall into.
	 */
	public function testARedropStoresTheZipEvenIfTheJobJustRemovedTheOldOne(): void {
		$this->appData->method('getFolder')->willReturn($this->drops);
		$this->drops->method('fileExists')->willReturn(true);
		$this->drops->method('getFile')->willThrowException(new NotFoundException('removed by the job'));
		$this->drops->expects(self::once())->method('newFile')->with('2026.zip', self::anything())
			->willReturn($this->createMock(ISimpleFile::class));

		$this->files(self::zip())->accept('/REM/SERIE_REM_2026.zip');
	}

	/** Two first drops at once: the one that loses the race to create the folder uses the winner's. */
	public function testTheDropsFolderAnotherRequestJustCreatedIsUsed(): void {
		$this->appData->method('getFolder')->willReturnOnConsecutiveCalls(
			self::throwException(new NotFoundException('not yet')),
			$this->drops,
		);
		$this->appData->method('newFolder')->willThrowException(new NotPermittedException('already exists'));
		$this->drops->expects(self::once())->method('newFile')->with('2026.zip', self::anything())
			->willReturn($this->createMock(ISimpleFile::class));

		$this->files(self::zip())->accept('/REM/SERIE_REM_2026.zip');
	}
}
