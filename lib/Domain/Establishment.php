<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Domain;

use JsonSerializable;
use OCA\Estadistica\Exception\RefusedException;

/**
 * The establishment this install serves: its DEIS code, its tipo and its
 * comuna's CUT. Exactly one per instance (gestion ADR-0013).
 *
 * The installer chooses it (Región → Comuna → Centro), gestion's phase 16
 * writes it into app config, and the app only ever reads it. Everything
 * national works without it: an unconfigured install still shows the country
 * and the peer groups, it just has no «local» series to set beside them.
 *
 * Depends on no OCP class, so it tests in bare PHP (territorio's Comuna shape).
 */
final class Establishment implements JsonSerializable {
	private function __construct(
		public readonly string $deisCode,
		public readonly string $type,
		public readonly string $comunaCut,
	) {
	}

	/** Not configured: a fresh install, or a site file that never said. */
	public static function none(): self {
		return new self('', '', '');
	}

	/**
	 * A DEIS establishment code has six digits (every EstablecimientoCodigo in
	 * the register since 2014); a comuna CUT has five. Anything else is a
	 * mistake in the site file, and reading it as configured would set a wrong
	 * «local» series beside the national one.
	 *
	 * @throws RefusedException
	 */
	public static function of(string $deisCode, string $type = '', string $comunaCut = ''): self {
		$deisCode = trim($deisCode);
		if (preg_match('/^\d{6}$/', $deisCode) !== 1) {
			throw new RefusedException(sprintf(
				'«%s» no es un código DEIS de establecimiento: se esperan seis dígitos.',
				$deisCode,
			));
		}

		$comunaCut = trim($comunaCut);
		if ($comunaCut !== '' && preg_match('/^\d{5}$/', $comunaCut) !== 1) {
			throw new RefusedException(sprintf(
				'«%s» no es un código CUT de comuna: se esperan cinco dígitos.',
				$comunaCut,
			));
		}

		return new self($deisCode, trim($type), $comunaCut);
	}

	public function isConfigured(): bool {
		return $this->deisCode !== '';
	}

	/** @return array{deisCode: string, type: string, comunaCut: string, configured: bool} */
	public function jsonSerialize(): array {
		return [
			'deisCode' => $this->deisCode,
			'type' => $this->type,
			'comunaCut' => $this->comunaCut,
			'configured' => $this->isConfigured(),
		];
	}
}
