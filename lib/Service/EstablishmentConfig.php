<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCP\IAppConfig;

/**
 * Reads which establishment this install serves. All the judgement lives in
 * {@see Establishment}; this is only the part that needs Nextcloud.
 *
 * There is no set(). The suite's provisioner writes these keys from the site
 * file the installer produced, and that is the only writer: a second one here
 * would be a way for the installer's choice and the app to disagree, and the
 * divergence gate reads the keys back against the site file (the suite's
 * scripts/divergence.sh).
 */
class EstablishmentConfig {
	public const KEY_DEIS = 'deis_code';
	public const KEY_TYPE = 'establishment_type';
	public const KEY_CUT = 'comuna_cut';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	/**
	 * Unset or unusable reads as none(), never as an exception: every page load
	 * comes through here, and the admin section says what is missing instead of
	 * the app failing (territorio's ComunaConfig::get, same reasoning).
	 */
	public function get(): Establishment {
		$deis = $this->appConfig->getValueString(Application::APP_ID, self::KEY_DEIS);
		if ($deis === '') {
			return Establishment::none();
		}

		try {
			return Establishment::of(
				$deis,
				$this->appConfig->getValueString(Application::APP_ID, self::KEY_TYPE),
				$this->appConfig->getValueString(Application::APP_ID, self::KEY_CUT),
			);
		} catch (RefusedException) {
			return Establishment::none();
		}
	}
}
