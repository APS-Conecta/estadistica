<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use APS\Common\Refuses;
use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Register\Register;
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
		private Catalog $catalog,
		private Register $register,
		private PivImporter $piv,
		private Goals $goals,
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
	 * - A Base de Establecimientos loads now.
	 * - FONASA's «Inscritos APS» CSV loads now. Any other header is refused
	 *   whole, with nothing stored (a11, docs/adr/0007).
	 * - A year's Metas Sanitarias file (JSON) loads now, replacing that year's
	 *   metas; its commitments stay (a9, docs/adr/0008).
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function drop(string $path = ''): JSONResponse {
		return $this->attempt(fn (): array => $this->drops->accept($path));
	}

	/**
	 * Every (año, serie) this install knows about: what is loaded, its fecha de
	 * corte and row count, whether it is incompleto, and what the last attempt
	 * did; beside each, its dictionary and how many of its códigos are left
	 * «sin descripción» (a6). Also the Base de Establecimientos, when DEIS was
	 * last checked, and what that check could not do. A failure is never
	 * silent (a4): a check that cannot reach DEIS must not read as a quiet day
	 * (docs/adr/0005). And the PIV: FONASA's cortes, and this install's
	 * establishment's figures, FONASA's beside the ones typed here. And the
	 * metas: each year's, and this establishment's commitments.
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function ingests(): JSONResponse {
		return $this->attempt(fn (): array => [
			'ingests' => array_map(static fn (IngestState $state): array => $state->toClient(), $this->states->findAll()),
			'dictionaries' => $this->catalog->status(),
			'register' => $this->register->status(),
			'deis' => $this->deis->status(),
			'piv' => $this->piv->status(),
			'metas' => $this->goals->status(),
		]);
	}

	/**
	 * This install's establishment's inscritos at 31 December of $year, as an
	 * admin typed them. They replace what was typed before for that corte, and
	 * a cell left out goes back to FONASA's figure. They count for this
	 * establishment's own series only; the country and the peer groups stay
	 * FONASA's (owner, Slice 8, docs/adr/0007). Refused while no establishment
	 * is configured.
	 *
	 * @param list<mixed> $cells each {edad, sexo, inscritos}
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function piv(int $year = 0, array $cells = []): JSONResponse {
		return $this->attempt(function () use ($year, $cells): array {
			$this->piv->enter($year, $cells);
			return $this->piv->status();
		});
	}

	/**
	 * This install's establishment's commitments for the metas of $year, as an
	 * admin typed them: each meta's meta comprometida (percent), the
	 * denominador comprometido of a meta counted over people, and the
	 * cumplimiento of a manual meta (VIII). They replace what was typed for
	 * that year; a meta left out has none. Refused while no establishment is
	 * configured (a10, a11, a12).
	 *
	 * @param list<mixed> $commitments each {codigo, meta?, denominador?, cumplimiento?}
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function goals(int $year = 0, array $commitments = []): JSONResponse {
		return $this->attempt(function () use ($year, $commitments): array {
			$this->goals->commit($year, $commitments);
			return $this->goals->status();
		});
	}
}
