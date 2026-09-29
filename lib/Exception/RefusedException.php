<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Exception;

use APS\Common\Refusal;
use RuntimeException;

/**
 * Understood and refused. The message is the Spanish sentence a person reads,
 * answered as 422 by aps-common's Refuses on the internal routes and by the
 * OCS controller's own refuse() (Slice 4). One class for the whole app: the
 * refusals differ in their sentence, never in how they are answered.
 */
final class RefusedException extends RuntimeException implements Refusal {
}
