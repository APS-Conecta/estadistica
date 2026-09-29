<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Catalog\DictionaryParser;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Register\RegisterParser;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * DEIS, the publisher of the national REM. There is one repository per
 * publisher (epidemiologia ADR-0016). It gives the job a Range reader over a
 * year's zip, and once a day it looks for what DEIS changed (docs/adr/0005).
 *
 * The daily check reads as little as it can, and trusts only what it
 * compared:
 *
 * - **One conditional HEAD per year in the window.** A 304 against the ETag
 *   kept for that year means nothing changed since the check that found the
 *   year settled, and it costs 0 bytes (research: 304 on If-None-Match).
 * - **The central directory of a year that did change** (two ≈ 66 KB Range
 *   requests). Serie A or P is queued only when its CRC-32 or size differs
 *   from the member last judged on its content (IngestState::memberCrc), or
 *   its DICCIONARIO's from the one last judged (Catalog::judged): a dictionary
 *   DEIS republishes alone is read again with its serie. The ETag says when to
 *   look and the CRC says what changed: IIS derives the ETag from the file's
 *   mtime, so it cannot say what (research).
 * - **The ETag is kept only once the year is settled**, with nothing of it
 *   queued. A load that fails is looked at again the next day, instead of
 *   hiding behind a 304.
 *
 * Each year is checked on its own (epidemiologia's per-source isolation,
 * L3-03): a year that fails is recorded, and the next is still checked. The
 * pass runs at most once a day, failed or not. What failed is shown in the
 * admin section (status()), because a check that cannot reach DEIS must not
 * look like a quiet day (#74).
 *
 * The same pass looks for the newest Base de Establecimientos on the same
 * host, one xlsx per year (the Register decision): this year's once DEIS
 * publishes it, last year's until then. A conditional HEAD on the file last
 * loaded costs nothing while it has not changed, and a new one is read whole
 * (≈ 1 MB) and replaces the register.
 *
 * An install configured without internet (config.php
 * `'has_internet_connection' => false`, which Nextcloud's own app store and
 * update checks obey) never calls DEIS. Its figures come from dropped zips
 * only.
 *
 * @psalm-import-type Row from RegisterParser
 */
class DeisRepository {
	/** Every year's zip, 2009 onward, at one address (research: all 18 years measured). */
	private const URL = 'https://repositoriodeis.minsal.cl/DatosAbiertos/REM/SERIE_REM_%d.zip';

	/**
	 * Every year's Base de Establecimientos, 2014 onward, beside REM on the same
	 * host (measured 2026-09-28: 2025 answers 200 at 1.0 MB, 2026 not yet out).
	 */
	private const REGISTER_URL = 'https://repositoriodeis.minsal.cl/ContenidoSitioWeb2020/Establecimientos/Base%%20de%%20Establecimientos%%20%d.xlsx';

	/** A Base de Establecimientos weighs ≈ 1 MB. This stops a wrong file before it is copied. */
	private const REGISTER_MAX_BYTES = 64 * 1024 * 1024;

	/** Names this app in every request (epidemiologia ADR-0015). */
	public const USER_AGENT = 'APS-Conecta-Estadistica/1.0 (+https://github.com/APS-Conecta/estadistica)';

	/** Unix seconds of the last check that ran, whether it failed or not. */
	public const KEY_CHECKED_AT = 'deis_checked_at';

	/** año → the ETag of that year's zip when a check last found the year settled. */
	public const KEY_ETAGS = 'deis_etags';

	/** What the last check could not do, one line per year. Empty when it did everything. */
	public const KEY_ERRORS = 'deis_check_errors';

	/** The Base de Establecimientos last loaded from DEIS: its year and its ETag. */
	public const KEY_REGISTER = 'deis_register';

	public const CHECK_EVERY = 24 * 3600;

	private const OFFLINE = 'Esta instalación está configurada sin acceso a internet, así que no consulta el DEIS. Cargue a mano el ZIP de ese año.';

	public function __construct(
		private IClientService $clients,
		private IAppConfig $appConfig,
		private IConfig $config,
		private ITimeFactory $time,
		private YearWindow $window,
		private IngestStateMapper $states,
		private Catalog $catalog,
		private Register $register,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	public function online(): bool {
		return $this->config->getSystemValueBool('has_internet_connection', true);
	}

	/**
	 * A Range reader over $year's zip. Nothing is requested until it is read.
	 *
	 * @throws RefusedException the install is configured without internet
	 */
	public function zip(int $year): HttpRange {
		if (!$this->online()) {
			throw new RefusedException(self::OFFLINE);
		}

		return new HttpRange($this->clients->newClient(), sprintf(self::URL, $year), self::USER_AGENT, $this->logger);
	}

	public function checkDue(): bool {
		return $this->online()
			&& $this->time->getTime() - $this->appConfig->getValueInt(Application::APP_ID, self::KEY_CHECKED_AT) >= self::CHECK_EVERY;
	}

	/** The window changed: the next idle tick checks, instead of waiting out the day. */
	public function recheck(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::KEY_CHECKED_AT);
	}

	/**
	 * The daily check. The newest year goes first, because it changes most
	 * and is consulted most.
	 */
	public function check(): void {
		$current = $this->window->currentYear();
		$etags = $this->etags();
		$errors = [];

		foreach (array_reverse($this->window->years()) as $year) {
			try {
				$zip = $this->zip($year);
				if (!$zip->changedSince($etags[$year] ?? '')) {
					continue;
				}

				if ($this->queueChanged($year, ZipDirectory::read($zip))) {
					unset($etags[$year]);
				} else {
					$etags[$year] = $zip->etag();
				}
			} catch (RefusedException $e) {
				// Early in a year DEIS has not published it yet. Any other year
				// missing means the address moved (epidemiologia ADR-0014), and
				// that is said, never skipped quietly.
				if ($e->getCode() === HttpRange::NOT_FOUND && $year === $current) {
					continue;
				}
				$errors[] = sprintf('%d: %s', $year, $e->getMessage());
				$this->logger->warning('The DEIS check of REM {anio} failed', ['anio' => $year, 'exception' => $e]);
			}
		}

		try {
			$this->checkRegister();
		} catch (RefusedException $e) {
			$errors[] = sprintf('Base de Establecimientos: %s', $e->getMessage());
			$this->logger->warning('The DEIS check of the Base de Establecimientos failed', ['exception' => $e]);
		}

		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_ETAGS, $etags);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_ERRORS, implode("\n", $errors));
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_CHECKED_AT, $this->time->getTime());
	}

	/**
	 * A year the window let go. Its kept ETag goes before its rows (RemJob), so
	 * if the year comes back it is read afresh rather than answered by a 304.
	 */
	public function forget(int $year): void {
		$etags = $this->etags();
		unset($etags[$year]);
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_ETAGS, $etags);
	}

	/**
	 * What the admin section shows under «Cifras REM cargadas».
	 *
	 * @return array{online: bool, checkedAt: ?int, errors: ?string}
	 */
	public function status(): array {
		$checkedAt = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_CHECKED_AT);
		$errors = $this->appConfig->getValueString(Application::APP_ID, self::KEY_ERRORS);

		return [
			'online' => $this->online(),
			'checkedAt' => $checkedAt === 0 ? null : $checkedAt,
			'errors' => $errors === '' ? null : $errors,
		];
	}

	/**
	 * Queues each serie of $year whose member is not the one last judged.
	 *
	 * @return bool whether anything was queued
	 * @throws RefusedException the zip has no Serie A or P under a name DEIS has used
	 */
	private function queueChanged(int $year, ZipDirectory $directory): bool {
		$series = $directory->series();
		if ($series === []) {
			throw new RefusedException('El ZIP no trae la Serie A ni la Serie P con un nombre conocido. Puede que el DEIS haya cambiado el nombre de sus archivos.');
		}

		$queued = false;
		foreach ($series as $serie) {
			$state = $this->states->findOrNew($year, $serie);
			try {
				$member = $directory->member($serie);
				$settled = $state->getMemberCrc() === $member['crc'] && $state->getMemberSize() === $member['size'];
			} catch (RefusedException) {
				// Unreadable as published: two members of one serie, encryption,
				// a method other than deflate. The load attempt refuses it with
				// the same sentence, on the row the admin reads.
				$settled = false;
			}
			try {
				$dictionary = DictionaryParser::member($directory, $serie);
			} catch (RefusedException) {
				// Two of them: the load records that with no identity
				// (Catalog::load), and this compares against the same none.
				$dictionary = null;
			}
			$settled = $settled && $this->catalog->judged($year, $serie, $dictionary);

			if (!$settled) {
				$this->states->markPending($year, $serie);
				$queued = true;
			}
		}

		return $queued;
	}

	/**
	 * The newest Base de Establecimientos: this year's, or last year's while
	 * this year's is not out. A 404 is quiet only for this year, and only
	 * while this year's file was never loaded: a file loaded once and gone
	 * means the address moved (epidemiologia ADR-0014), and that is said.
	 *
	 * @throws RefusedException
	 */
	private function checkRegister(): void {
		$kept = $this->appConfig->getValueArray(Application::APP_ID, self::KEY_REGISTER);
		$current = $this->window->currentYear();

		foreach ([$current, $current - 1] as $year) {
			$file = new HttpRange($this->clients->newClient(), sprintf(self::REGISTER_URL, $year), self::USER_AGENT, $this->logger);
			try {
				$changed = $file->changedSince(($kept['year'] ?? null) === $year ? (string)($kept['etag'] ?? '') : '');
			} catch (RefusedException $e) {
				if ($e->getCode() === HttpRange::NOT_FOUND && $year === $current && ($kept['year'] ?? null) !== $current) {
					continue;
				}
				throw $e;
			}

			if ($changed) {
				$this->register->replace($this->registerRows($file), IngestState::ORIGIN_DEIS, $year);
				$this->appConfig->setValueArray(Application::APP_ID, self::KEY_REGISTER, ['year' => $year, 'etag' => $file->etag()]);
			}
			return;
		}
	}

	/**
	 * The file copied whole to a temporary one, then read.
	 *
	 * @return list<Row>
	 * @throws RefusedException
	 */
	private function registerRows(HttpRange $file): array {
		$size = $file->size();
		if ($size > self::REGISTER_MAX_BYTES) {
			throw new RefusedException(sprintf('El archivo pesa %d MB; una Base de Establecimientos pesa cerca de 1 MB.', intdiv($size, 1024 * 1024)));
		}

		$path = $this->tempManager->getTemporaryFile('.xlsx');
		if ($path === false) {
			throw new RefusedException('No se pudo crear un archivo temporal para leerla.');
		}

		try {
			$target = fopen($path, 'wb');
			if ($target === false) {
				throw new RefusedException('No se pudo crear un archivo temporal para leerla.');
			}
			try {
				$source = $file->stream(0, $size);
				$copied = stream_copy_to_stream($source, $target, $size);
				fclose($source);
			} finally {
				fclose($target);
			}
			if ($copied !== $size) {
				throw new RefusedException('La descarga llegó cortada.');
			}

			return RegisterParser::parse(XlsxReader::open($path))['rows'];
		} finally {
			unlink($path);
		}
	}

	/** @return array<int, string> */
	private function etags(): array {
		return array_map('strval', $this->appConfig->getValueArray(Application::APP_ID, self::KEY_ETAGS));
	}
}
