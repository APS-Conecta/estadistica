<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use Attribute;

/**
 * An OCS operation the suite AI may call, as a 'read' or a 'write'
 * (gestion-aio#75). scripts/openapi.sh writes it into openapi.json as the
 * operation's x-aps-ai, from which the suite's connector builds its tools
 * (gestion-aio#93): an unmarked operation is no tool. Never on a delete or an
 * admin route; ApiContractTest holds the list.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AiOperation {
	public function __construct(
		public string $access,
	) {
	}
}
