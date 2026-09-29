<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use APS\Common\Refuses;
use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The admin section's actions: its writes, and the ingest state it reads.
 * Every public method carries
 * #[AuthorizedAdminSetting(settings: AdminSettings::class)] and nothing else
 * that decides who may call it: NC core lets a real admin through, lets a
 * member of any group an admin delegated the section to through, and answers
 * 403 to everyone else (SecurityMiddleware). A hand-written admin check beside
 * it would refuse every delegate — IntraVox's SettingsApiController does
 * exactly that — so there is none, and MetadataTest fails a method without the
 * attribute (docs/adr/0003). The check's name is not even written here: the
 * a10 gate greps this directory for it.
 *
 * CSRF stays on: the section's own requests carry the token, and an API client
 * sends OCS-APIRequest: true, which NC accepts in its place.
 */
class AdminController extends Controller {
	use Refuses;

	public function __construct(
		IRequest $request,
		private YearWindow $window,
		private DroppedFiles $drops,
		private IngestStateMapper $states,
		private DeisRepository $deis,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * The first year of national history this install keeps. Widening makes the
	 * REM job look the new years up at DEIS within the hour, rather than at the
	 * next daily check, and load them in the background, one (año, serie) at a
	 * time. Narrowing makes it drop the years left outside. This only records
	 * the choice — the job acts on it.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function window(int $firstYear = 0): JSONResponse {
		return $this->attempt(function () use ($firstYear): array {
			$this->window->choose($firstYear);
			$this->deis->recheck();
			return $this->window->toArray();
		});
	}

	/**
	 * A file the admin put in their own Files and picked there. The platform's
	 * picker hands over a path, never bytes.
	 * - A REM zip is checked now: a zip, with Serie A or P, of a year inside
	 *   the window. A wrong file is refused in this request, in Spanish. A
	 *   right one loads in the background, one serie per hourly run (RemJob).
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function drop(string $path = ''): JSONResponse {
		return $this->attempt(fn (): array => $this->drops->accept($path));
	}

	/**
	 * Every (año, serie) this install knows about: what is loaded, its fecha de
	 * corte and row count, whether it is incompleto, and what the last attempt
	 * did. Also when DEIS was last checked, and what that check could not do.
	 * A failure is never silent (a4): a check that cannot reach DEIS must not
	 * read as a quiet day (docs/adr/0005).
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function ingests(): JSONResponse {
		return $this->attempt(fn (): array => [
			'ingests' => array_map(static fn (IngestState $state): array => $state->toClient(), $this->states->findAll()),
			'deis' => $this->deis->status(),
		]);
	}
}
