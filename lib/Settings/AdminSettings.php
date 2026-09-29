<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Settings;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * The admin section, delegable: an admin hands it to any group (occ
 * admin-delegation:add 'OCA\Estadistica\Settings\AdminSettings' <group>, or
 * Administration privileges), and NC core enforces that on every write whose
 * method names this class in #[AuthorizedAdminSetting] (docs/adr/0003).
 */
class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private IInitialState $initialState,
		private EstablishmentConfig $establishment,
		private YearWindow $window,
	) {
	}

	public function getForm(): TemplateResponse {
		$establishment = $this->establishment->get();

		$this->initialState->provideInitialState('window', $this->window->toArray());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');

		// The establishment is shown by the template itself, not by the bundle:
		// the notice that it is missing must stand even when the script does not
		// load, and it is what smoke reads (acceptance a1).
		return new TemplateResponse(Application::APP_ID, 'admin', [
			'establishment' => $establishment->jsonSerialize(),
		]);
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}

	/** The label Administration privileges shows when an admin delegates this section. */
	public function getName(): ?string {
		return 'Estadística';
	}

	/**
	 * Nothing through core's generic app-config endpoint: every write is one of
	 * this app's own routes, each declaring its delegation on the method.
	 *
	 * @return array<string, list<string>>
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}
}
