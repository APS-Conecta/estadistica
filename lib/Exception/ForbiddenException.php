<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Exception;

use RuntimeException;

/**
 * Understood, and not this person's to change: the 403 beside
 * RefusedException's 422. A pregunta or a tablero is changed by its creator
 * or by whoever administers Estadística (SavedContent); everyone else reads
 * it. The message is the Spanish sentence a person reads.
 */
final class ForbiddenException extends RuntimeException {
}
