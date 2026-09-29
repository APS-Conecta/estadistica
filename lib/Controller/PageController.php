<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		IRequest $request,
		private IInitialState $initialState,
		private EstablishmentConfig $establishment,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// A top-level navigation carries no request token; without NoCSRFRequired
	// NC 34's CSRF middleware answers 412 (farmacia #1).
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$this->initialState->provideInitialState('establishment', $this->establishment->get()->jsonSerialize());

		Util::addScript(Application::APP_ID, Application::APP_ID . '-main');
		return new TemplateResponse(Application::APP_ID, 'index');
	}
}
