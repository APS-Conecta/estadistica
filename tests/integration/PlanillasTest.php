<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Integration;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\Planillas;
use OCA\Estadistica\Tests\Unit\Rem\ZipFixture;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Server;

/**
 * The planilla folder against the instance's real Files and PostgreSQL:
 * chosen by path, read by the job's scan, each file's state, and the figures
 * stored apart from DEIS's (docs/adr/0010). The forms are XlsxFixture's, in
 * DEIS's 2026 Serie A layout, dated 2096: that year belongs to this test, and
 * its diccionario is stored the way a REM load stores it (Catalog::load).
 *
 * The user is `admin`, the only account compose.dev.yaml creates.
 */
final class PlanillasTest extends IntegrationTestCase {
	private const UID = 'admin';

	private const FOLDER = 'estadistica-planillas-test';

	private const YEAR = 2096;

	private Folder $folder;

	protected function setUp(): void {
		parent::setUp();
		$this->provision(EstablishmentConfig::KEY_DEIS, '900001');

		$zip = ZipFixture::zip(['Diccionarios/DICCIONARIO CODIGOS SA_26_V1.2.xlsm' => (string)file_get_contents(XlsxFixture::dictionary2026('A'))]);
		$range = new LocalRange($zip);
		Server::get(Catalog::class)->load($range, ZipDirectory::read($range), self::YEAR, 'A');

		$this->folder = Server::get(IRootFolder::class)->getUserFolder(self::UID)->newFolder(self::FOLDER);
		$this->planillas()->choose('/' . self::FOLDER);
	}

	protected function tearDown(): void {
		$this->folder->delete();
		$this->appConfig()->deleteKey(Application::APP_ID, Planillas::KEY_OWNER);
		$this->appConfig()->deleteKey(Application::APP_ID, Planillas::KEY_FOLDER);
		// No folder: the scan forgets every planilla it read.
		$this->planillas()->scan();
		foreach (['estadistica_code', 'estadistica_dictionary'] as $table) {
			$this->db()->executeStatement("DELETE FROM *PREFIX*$table WHERE anio = ?", [self::YEAR]);
		}
		XlsxFixture::cleanup();
		ZipFixture::cleanup();
		parent::tearDown();
	}

	private function planillas(): Planillas {
		return $this->planillasFor(self::UID);
	}

	/**
	 * A 2096 form of this establishment, saved in the folder as $name at $mtime.
	 *
	 * @param array<string, array<int, int>> $figures
	 * @param array<string, int|string|null> $nombre
	 */
	private function save(string $name, array $figures, int $mtime, array $nombre = []): File {
		$content = (string)file_get_contents(XlsxFixture::form2026('A', $figures, ['B7' => self::YEAR, ...$nombre]));
		$file = $this->folder->nodeExists($name) ? $this->folder->get($name) : $this->folder->newFile($name);
		self::assertInstanceOf(File::class, $file);
		$file->putContent($content);
		$file->touch($mtime);

		return $file;
	}

	/** @return list<array{string, string, ?string, ?int}> each file's name, state, serie and month, as the section lists them */
	private function states(): array {
		return array_map(
			static fn (array $file): array => [$file['name'], $file['state'], $file['serie'], $file['mes']],
			$this->planillas()->status()['files'],
		);
	}

	/** @return array<string, list<?string>> the stored figures of $file: código → col01…col03 */
	private function figures(File $file): array {
		$rows = $this->db()->executeQuery(
			'SELECT codigo, col01, col02, col03 FROM *PREFIX*estadistica_planilla_rem WHERE file_id = ? ORDER BY codigo',
			[$file->getId()],
		)->fetchAll();

		return array_combine(array_column($rows, 'codigo'), array_map(
			static fn (array $row): array => [$row['col01'], $row['col02'], $row['col03']],
			$rows,
		));
	}

	public function testAPlanillaSavedInTheFolderIsReadAndItsFiguresKeptApartFromDeis(): void {
		$marzo = $this->save('marzo.xlsm', ['01010101' => [1 => 7, 2 => 3, 3 => 4], '05225303' => [1 => 12]], 1790000000);

		$this->planillas()->scan();

		$status = $this->planillas()->status();
		self::assertSame(['/' . self::FOLDER, self::UID, null], [$status['folder'], $status['owner'], $status['error']]);
		self::assertSame([['marzo.xlsm', 'leido', 'A', 3]], $this->states());
		self::assertSame([
			'01010101' => ['7.000000', '3.000000', '4.000000'],
			'05225303' => ['12.000000', null, null],
		], $this->figures($marzo));
		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_rem WHERE anio = ?', [self::YEAR])->fetchOne(), 'DEIS\'s rows are not where a planilla goes');
	}

	public function testTheNewestPlanillaOfASerieAndMonthWinsAndARemovedOneIsForgotten(): void {
		$older = $this->save('marzo.xlsm', ['01010101' => [1 => 5]], 1790000000);
		$newer = $this->save('marzo corregida.xlsm', ['01010101' => [1 => 9]], 1790000060);

		$this->planillas()->scan();

		self::assertSame([['marzo corregida.xlsm', 'leido', 'A', 3], ['marzo.xlsm', 'reemplazado', 'A', 3]], $this->states());

		$newerId = $newer->getId();
		$newer->delete();
		$this->planillas()->scan();

		self::assertSame([['marzo.xlsm', 'leido', 'A', 3]], $this->states());
		self::assertSame(['01010101' => ['5.000000', null, null]], $this->figures($older));
		self::assertSame(0, (int)$this->db()->executeQuery('SELECT count(*) FROM *PREFIX*estadistica_planilla_rem WHERE file_id = ?', [$newerId])->fetchOne());
	}

	public function testAChangedPlanillaIsReadAgain(): void {
		$this->save('marzo.xlsm', ['01010101' => [1 => 5]], 1790000000);
		$this->planillas()->scan();

		$marzo = $this->save('marzo.xlsm', ['01010101' => [1 => 6]], 1790000060);
		$this->planillas()->scan();

		self::assertSame(['01010101' => ['6.000000', null, null]], $this->figures($marzo));
	}

	public function testARefusedPlanillaIsListedWithItsReasonAndNothingIsKept(): void {
		$ajena = $this->save('otro cesfam.xlsm', ['01010101' => [1 => 5]], 1790000000, ['H3' => 2]);

		$this->planillas()->scan();

		$file = $this->planillas()->status()['files'][0];
		self::assertSame(['otro cesfam.xlsm', 'rechazado'], [$file['name'], $file['state']]);
		self::assertStringContainsString('es del establecimiento 900002', (string)$file['error']);
		self::assertSame([], $this->figures($ajena));
	}

	/**
	 * A file Nextcloud cannot open (its storage is unavailable, its encryption
	 * fails) is ours to retry, and the files listed after it are still read.
	 */
	public function testAFileNextcloudCannotOpenIsRetriedAndTheFilesAfterItAreStillRead(): void {
		$marzo = $this->save('marzo.xlsm', ['01010101' => [1 => 7]], 1790000000);
		$unreadable = $this->createMock(File::class);
		$unreadable->method('getId')->willReturn(PHP_INT_MAX);
		$unreadable->method('getName')->willReturn('sin acceso.xlsm');
		$unreadable->method('getMTime')->willReturn(1790000000);
		$unreadable->method('fopen')->willThrowException(new \RuntimeException('storage unavailable'));
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn([$unreadable, $marzo]);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('getFirstNodeById')->willReturn($folder);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);

		$this->planillasFor(self::UID, $rootFolder)->scan();

		self::assertSame([['marzo.xlsm', 'leido', 'A', 3], ['sin acceso.xlsm', 'rechazado', null, null]], $this->states());
		self::assertSame('No se pudo leer el archivo de Nextcloud; se vuelve a intentar en la próxima hora.', $this->planillas()->status()['files'][1]['error']);
	}

	public function testAFileIsNotAFolder(): void {
		$this->save('marzo.xlsm', [], 1790000000);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('Eso es un archivo, no una carpeta');

		$this->planillas()->choose('/' . self::FOLDER . '/marzo.xlsm');
	}
}
