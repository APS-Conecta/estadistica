<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\MemberLines;
use OCA\Estadistica\Rem\RangeReader;
use OCA\Estadistica\Rem\RemReader;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;

/**
 * The admin's door for a file: a DEIS REM zip, or DEIS's Base de
 * Establecimientos. An install without internet has no other way to get
 * them. The admin picks the file in Files with the platform's picker, and it
 * is handed over by path.
 *
 * A REM zip is kept in the app's own storage until the job has loaded it. The
 * job has no session, so it cannot read anyone's Files; IAppData needs none
 * (research). The Base de Establecimientos is ≈ 1 MB and 5,600 rows, so it is
 * loaded in the request, and replaces the register at once (Register).
 *
 * Nothing is read into memory:
 *
 * 1. the file is streamed to a local temporary copy (a zip is read from its
 *    end, and a stream from Files need not be seekable);
 * 2. it is checked there, in the request, so a wrong file is refused in
 *    Spanish while the admin is still looking;
 * 3. it is streamed on into IAppData as drops/<año>.zip.
 *
 * A workbook is a zip too: one with no Serie A or P is read as the Base de
 * Establecimientos, and refused in Spanish if it is not one either. The year
 * comes from the rows, not the file name, which may say anything.
 *
 * A drop queues its (año, serie) pairs only AFTER it is stored, so the job
 * never meets a queued pair whose file has not arrived.
 */
class DroppedFiles {
	private const FOLDER = 'drops';

	/** A DEIS REM zip weighs 100–246 MB (research). This stops a mistaken pick before it is copied. */
	private const MAX_BYTES = 1024 * 1024 * 1024;

	/**
	 * A drop nothing is queued for is left over from a run interrupted between
	 * its last member and the removal, once it is this old. The margin covers
	 * the moment between storing a drop and queueing it.
	 */
	private const STALE_AFTER = 24 * 3600;

	private const NO_COPY = 'No se pudo copiar el archivo para revisarlo. Si el servidor está sin espacio, avise al administrador.';

	private IAppData $appData;

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		IAppDataFactory $appDataFactory,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private YearWindow $window,
		private IngestStateMapper $states,
		private Register $register,
	) {
		$this->appData = $appDataFactory->get(Application::APP_ID);
	}

	/**
	 * Takes the file at $path in the caller's own Files: queues a REM zip's
	 * series, or loads a Base de Establecimientos.
	 *
	 * @return array{anio: int, series: list<string>}|array{establecimientos: int}
	 * @throws RefusedException
	 */
	public function accept(string $path): array {
		$local = $this->localCopy($this->open($path));

		try {
			$range = new LocalRange($local);
			$zip = ZipDirectory::read($range);
			$series = $zip->series();
			if ($series === []) {
				return ['establecimientos' => $this->acceptRegister($local)];
			}

			$year = $this->yearOf($range, $zip, $series);
			if ($year < $this->window->firstYear() || $year > $this->window->currentYear()) {
				throw new RefusedException(sprintf(
					'El ZIP trae cifras de %d, fuera de los años que guarda esta instalación (%d a %d). Para cargarlo, amplíe primero «Años de historia nacional».',
					$year,
					$this->window->firstYear(),
					$this->window->currentYear(),
				));
			}

			$this->store($year, $local);
		} finally {
			if (is_file($local)) {
				unlink($local);
			}
		}

		foreach ($series as $serie) {
			$this->states->markPending($year, $serie);
		}

		return ['anio' => $year, 'series' => $series];
	}

	/**
	 * The register, replaced now: its rows are few, and nothing waits on the job.
	 *
	 * @throws RefusedException
	 */
	private function acceptRegister(string $local): int {
		try {
			$xlsx = XlsxReader::open($local);
		} catch (RefusedException) {
			throw new RefusedException('El ZIP no trae la Serie A ni la Serie P del REM, y tampoco es la Base de Establecimientos del DEIS.');
		}

		return $this->register->replace(RegisterParser::parse($xlsx)['rows'], IngestState::ORIGIN_FILE, null);
	}

	/**
	 * A local copy of the drop for $year, for LocalRange, or null when there is
	 * none. The caller deletes it; the platform's temp cleanup after every job
	 * catches what it misses.
	 *
	 * @throws RefusedException
	 */
	public function localCopyOf(int $year): ?string {
		try {
			$file = $this->folder()->getFile($year . '.zip');
		} catch (NotFoundException) {
			return null;
		}

		try {
			$stream = $file->read();
		} catch (NotPermittedException) {
			$stream = false;
		}

		return $this->localCopy($stream);
	}

	public function remove(int $year): void {
		try {
			$this->folder()->getFile($year . '.zip')->delete();
		} catch (NotFoundException) {
			// Already gone: a second tick, or a re-drop replaced it.
		}
	}

	/**
	 * Drops nothing is queued for. Called only when the queue is empty, so every
	 * drop left is leftover (STALE_AFTER).
	 */
	public function removeStale(): void {
		$before = $this->time->getTime() - self::STALE_AFTER;
		foreach ($this->folder()->getDirectoryListing() as $file) {
			if ($file->getMTime() < $before) {
				$file->delete();
			}
		}
	}

	/**
	 * The zip's stream, resolved against the caller's OWN user folder, which is
	 * the entire access rule (territorio's ImportFiles::fileInNextcloud). A path
	 * that is not theirs does not resolve, and Folder::get() normalizes `..`
	 * away rather than climbing out.
	 *
	 * @return resource
	 * @throws RefusedException
	 */
	private function open(string $path) {
		try {
			$node = $this->rootFolder
				->getUserFolder((string)$this->userSession->getUser()?->getUID())
				->get($path);
		} catch (NotFoundException|NotPermittedException) {
			throw new RefusedException('No se encontró ese archivo en sus archivos de Nextcloud.');
		}

		if (!$node instanceof File) {
			throw new RefusedException('Eso es una carpeta, no un archivo.');
		}
		if ($node->getSize() > self::MAX_BYTES) {
			throw new RefusedException('El archivo supera 1 GB; un ZIP del DEIS pesa menos de 300 MB.');
		}

		try {
			$stream = $node->fopen('r');
		} catch (NotPermittedException|LockedException) {
			$stream = false;
		}
		if ($stream === false) {
			throw new RefusedException('No se pudo leer ese archivo de Nextcloud.');
		}

		return $stream;
	}

	/**
	 * @param resource|false $source
	 * @throws RefusedException
	 */
	private function localCopy($source): string {
		if ($source === false) {
			throw new RefusedException('No se pudo leer el archivo recibido.');
		}

		$path = $this->tempManager->getTemporaryFile('.zip');
		$target = $path === false ? false : fopen($path, 'wb');
		if ($target === false) {
			fclose($source);
			throw new RefusedException(self::NO_COPY);
		}

		$copied = stream_copy_to_stream($source, $target);
		fclose($source);
		fclose($target);
		if ($copied === false) {
			unlink($path);
			throw new RefusedException(self::NO_COPY);
		}

		return $path;
	}

	/**
	 * Every series in one drop must be of one year: DEIS publishes one zip per
	 * year, and the drop is filed under it.
	 *
	 * @param list<string> $series
	 * @throws RefusedException
	 */
	private function yearOf(RangeReader $range, ZipDirectory $zip, array $series): int {
		$years = [];
		foreach ($series as $serie) {
			$years[$serie] = RemReader::year(MemberLines::of($range, $zip->member($serie)));
		}

		if (count(array_unique($years)) > 1) {
			throw new RefusedException(sprintf(
				'El ZIP mezcla años (%s). Cargue un ZIP por año, como los publica el DEIS.',
				implode(', ', array_map(static fn (string $serie, int $year): string => "Serie $serie de $year", array_keys($years), $years)),
			));
		}

		return reset($years);
	}

	private function store(int $year, string $local): void {
		$source = fopen($local, 'rb');
		if ($source === false) {
			throw new RefusedException(self::NO_COPY);
		}

		try {
			$folder = $this->folder();
			$name = $year . '.zip';
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->putContent($source);
			} else {
				$folder->newFile($name, $source);
			}
		} catch (NotPermittedException|LockedException) {
			throw new RefusedException('No se pudo guardar el archivo en el almacenamiento de la aplicación.');
		} finally {
			// The Files layer may already have closed it after copying.
			if (is_resource($source)) {
				fclose($source);
			}
		}
	}

	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}
}
