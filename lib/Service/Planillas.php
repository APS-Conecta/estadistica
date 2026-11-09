<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\BackgroundJob\ResumenJob;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\PlanillaReader;
use OCA\Estadistica\Rem\RemReader;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\ITempManager;
use OCP\IUserSession;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The folder where the establishment saves its planillas (docs/adr/0010), and
 * what reading each file there found.
 *
 * - **Choosing it** is a delegated admin write (docs/adr/0003): a folder in
 *   the chooser's OWN Files, the same access rule as DroppedFiles. Its owner
 *   and file id are kept, so renaming or moving it keeps it chosen.
 * - **Reading it** is the job's (RemJob), as the person who chose it: the job
 *   reads only what they can. Every file directly in the folder is listed. A
 *   new or changed one is read (PlanillaReader), and so is every one when what
 *   judged them changes: the configured establishment, or a diccionario. A
 *   file no longer there is forgotten, rows and all. The folder is the single
 *   truth.
 * - **States.** «leido», with its serie and month; «rechazado», with the
 *   Spanish reason; «reemplazado», a leído planilla of a serie and month that
 *   has a newer one (by modification time, then file id). Only «rechazado» is
 *   stored as such: the others are read from the files beside them.
 *
 * A leído planilla's figures are kept in estadistica_planilla_rem, apart from
 * DEIS's, with their file, and the newest one of each serie and month is its
 * establishment's local series (QueryCompiler::local). So reading or
 * forgetting a leído planilla queues one rebuild of the stored Resumen in the
 * same transaction (ResumenJob, docs/adr/0009); a refused one changes no
 * figure and queues none.
 *
 * @psalm-type Status = array{folder: ?string, owner: ?string, error: ?string, files: list<array{name: string, mtime: int, state: string, serie: ?string, anio: ?int, mes: ?int, error: ?string}>}
 */
class Planillas {
	use TTransactional;

	public const KEY_OWNER = 'planilla_owner';
	public const KEY_FOLDER = 'planilla_folder';

	/** A REM form weighs under 2 MB (2026 SA: 1.6 MB). Past this, a file is not copied to be read. */
	private const MAX_BYTES = 64 * 1024 * 1024;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private EstablishmentConfig $establishment,
		private Catalog $catalog,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private LoggerInterface $logger,
		private IJobList $jobs,
	) {
	}

	/**
	 * The folder at $path in the caller's own Files becomes the planilla
	 * folder. The job reads it at its next run.
	 *
	 * @return Status
	 * @throws RefusedException
	 */
	public function choose(string $path): array {
		$uid = (string)$this->userSession->getUser()?->getUID();
		try {
			$node = $this->rootFolder->getUserFolder($uid)->get($path);
		} catch (NotFoundException|NotPermittedException) {
			throw new RefusedException('No se encontró esa carpeta en sus archivos de Nextcloud.');
		}
		if (!$node instanceof Folder) {
			throw new RefusedException('Eso es un archivo, no una carpeta: elija la carpeta donde se guardan las planillas.');
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY_OWNER, $uid);
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_FOLDER, $node->getId());

		return $this->status();
	}

	/** Reads the new and changed files of the folder, and forgets the ones gone. Outcomes are recorded, not thrown. */
	public function scan(): void {
		$files = [];
		foreach ($this->folder()?->getDirectoryListing() ?? [] as $node) {
			if ($node instanceof File) {
				$files[$node->getId()] = $node;
			}
		}

		$result = $this->db->executeQuery('SELECT file_id, mtime, context FROM *PREFIX*estadistica_planilla');
		$known = [];
		while (($row = $result->fetch()) !== false) {
			$known[(int)$row['file_id']] = [(int)$row['mtime'], (string)$row['context']];
		}
		$result->closeCursor();

		foreach (array_diff_key($known, $files) as $id => $_) {
			$this->atomic(function () use ($id): void {
				if ($this->forget($id)) {
					$this->jobs->add(ResumenJob::class);
				}
			}, $this->db);
		}

		$context = $this->context();
		foreach ($files as $id => $file) {
			if (($known[$id] ?? null) !== [$file->getMTime(), $context]) {
				$this->read($file, $context);
			}
		}
	}

	/** @return Status */
	public function status(): array {
		$owner = $this->appConfig->getValueString(Application::APP_ID, self::KEY_OWNER);
		$folder = $this->folder();
		$path = $folder === null ? null : $this->rootFolder->getUserFolder($owner)->getRelativePath($folder->getPath());

		$result = $this->db->executeQuery(
			'SELECT name, mtime, serie, anio, mes, error FROM *PREFIX*estadistica_planilla '
			. 'ORDER BY anio DESC NULLS LAST, mes DESC, serie, mtime DESC, file_id DESC',
		);
		$files = [];
		$newest = [];
		while (($row = $result->fetch()) !== false) {
			$state = 'rechazado';
			if ($row['error'] === null) {
				$key = $row['serie'] . $row['anio'] . '-' . $row['mes'];
				$state = isset($newest[$key]) ? 'reemplazado' : 'leido';
				$newest[$key] = true;
			}
			$files[] = [
				'name' => (string)$row['name'],
				'mtime' => (int)$row['mtime'],
				'state' => $state,
				'serie' => $row['serie'],
				'anio' => $row['anio'] === null ? null : (int)$row['anio'],
				'mes' => $row['mes'] === null ? null : (int)$row['mes'],
				'error' => $row['error'],
			];
		}
		$result->closeCursor();

		return [
			'folder' => $path,
			'owner' => $owner === '' ? null : $owner,
			'error' => $owner !== '' && $folder === null
				? 'No se encuentra la carpeta de planillas elegida: se borró, o quien la eligió ya no tiene acceso a ella. Elija otra.'
				: null,
			'files' => $files,
		];
	}

	/** The chosen folder as its owner sees it, or null: none chosen, or it cannot be reached. */
	private function folder(): ?Folder {
		$owner = $this->appConfig->getValueString(Application::APP_ID, self::KEY_OWNER);
		$id = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_FOLDER);
		if ($owner === '' || $id === 0) {
			return null;
		}

		try {
			$node = $this->rootFolder->getUserFolder($owner)->getFirstNodeById($id);
		} catch (Throwable) {
			// The owner was deleted, or their storage cannot be set up.
			return null;
		}

		return $node instanceof Folder ? $node : null;
	}

	/**
	 * What every verdict depends on beyond the file itself: the configured
	 * establishment, and the diccionarios (one is stored again with each load).
	 */
	private function context(): string {
		$result = $this->db->executeQuery('SELECT anio, serie, name, checked_at FROM *PREFIX*estadistica_dictionary ORDER BY anio, serie');
		$dictionaries = $result->fetchAll();
		$result->closeCursor();

		return hash('sha256', json_encode([$this->establishment->get()->deisCode, $dictionaries], JSON_THROW_ON_ERROR));
	}

	private function read(File $file, string $context): void {
		if ($file->getSize() > self::MAX_BYTES) {
			$this->record($file, $context, null, 'El archivo pesa más de 64 MB: un formulario REM pesa menos de 2 MB.');
			return;
		}

		$local = $this->localCopy($file);
		if ($local === null) {
			// Ours, not the file's (a save in progress locks it, or its storage is down): judged again at the next run.
			$this->record($file, '', null, 'No se pudo leer el archivo de Nextcloud; se vuelve a intentar en la próxima hora.');
			return;
		}

		try {
			$planilla = PlanillaReader::read(
				XlsxReader::open($local),
				$this->establishment->get()->deisCode,
				$this->catalog->layout(...),
			);
			$this->record($file, $context, $planilla, null);
		} catch (RefusedException $e) {
			$this->record($file, $context, null, $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->warning('Reading the planilla {name} failed', ['name' => $file->getName(), 'exception' => $e]);
			$this->record($file, $context, null, 'La planilla no se pudo leer por un error interno; el detalle quedó en el registro del servidor.');
		} finally {
			unlink($local);
		}
	}

	/**
	 * A local copy of $file for XlsxReader, which reads a path; null when
	 * Nextcloud would not hand it over. A denied or locked file is expected;
	 * any other failure to open it (its storage is unavailable, its encryption
	 * fails) is logged.
	 */
	private function localCopy(File $file): ?string {
		$local = $this->tempManager->getTemporaryFile('.xlsx');
		try {
			$source = $file->fopen('r');
		} catch (NotPermittedException|LockedException) {
			$source = false;
		} catch (Throwable $e) {
			$this->logger->warning('Opening the planilla {name} failed', ['name' => $file->getName(), 'exception' => $e]);
			$source = false;
		}
		$target = $local === false ? false : fopen($local, 'wb');

		$copied = $source !== false && $target !== false && stream_copy_to_stream($source, $target) !== false;
		foreach ([$source, $target] as $stream) {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		if (!$copied && is_string($local)) {
			unlink($local);
		}

		return $copied ? (string)$local : null;
	}

	/**
	 * A file's verdict and its figures, together.
	 *
	 * @param ?array{serie: string, anio: int, mes: int, estab: int, rows: list<list<int|string|null>>} $planilla
	 */
	private function record(File $file, string $context, ?array $planilla, ?string $error): void {
		$id = $file->getId();
		$this->atomic(function () use ($file, $id, $context, $planilla, $error): void {
			$was = $this->forget($id);
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_planilla (file_id, name, mtime, context, serie, anio, mes, estab, error, checked_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
				[$id, mb_substr($file->getName(), 0, 255), $file->getMTime(), $context, $planilla['serie'] ?? null, $planilla['anio'] ?? null, $planilla['mes'] ?? null, $planilla['estab'] ?? null, $error, $this->time->getTime()],
			);
			if ($planilla !== null && $planilla['rows'] !== []) {
				$this->insert($id, $planilla);
			}
			if ($was || $planilla !== null) {
				$this->jobs->add(ResumenJob::class);
			}
		}, $this->db);
	}

	/**
	 * The figures go in as one JSON document through jsonb_to_recordset, as
	 * Catalog's labels do: a planilla has a few thousand rows at most. Every
	 * value is bound.
	 *
	 * @param array{serie: string, anio: int, mes: int, estab: int, rows: list<list<int|string|null>>} $planilla
	 */
	private function insert(int $id, array $planilla): void {
		$columns = array_map(static fn (int $i): string => sprintf('col%02d', $i), range(1, RemReader::COLUMNS));
		$rows = array_map(
			static fn (array $row): array => ['codigo' => $row[2], ...array_combine($columns, array_slice($row, 3))],
			$planilla['rows'],
		);

		$this->db->executeStatement(
			sprintf(
				'INSERT INTO *PREFIX*estadistica_planilla_rem (file_id, serie, anio, mes, estab, codigo, %1$s) '
				. 'SELECT CAST(? AS bigint), ?, CAST(? AS smallint), CAST(? AS smallint), CAST(? AS integer), t.codigo, %2$s '
				. 'FROM jsonb_to_recordset(CAST(? AS jsonb)) AS t(codigo text, %3$s)',
				implode(', ', $columns),
				implode(', ', array_map(static fn (string $column): string => "t.$column", $columns)),
				implode(', ', array_map(static fn (string $column): string => "$column numeric", $columns)),
			),
			[$id, $planilla['serie'], $planilla['anio'], $planilla['mes'], $planilla['estab'], json_encode($rows, JSON_THROW_ON_ERROR)],
		);
	}

	/** Forgets a file's verdict and figures; true when it was leído, so a local series changes. */
	private function forget(int $id): bool {
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_planilla_rem WHERE file_id = ?', [$id]);
		$leido = $this->db->executeStatement('DELETE FROM *PREFIX*estadistica_planilla WHERE file_id = ? AND error IS NULL', [$id]) > 0;
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_planilla WHERE file_id = ?', [$id]);

		return $leido;
	}
}
