<?php

declare(strict_types=1);

/**
 * Boots a real Nextcloud so what only a real instance can answer is answered
 * there: the migrations' columns, the read-only executor, app config written
 * the way occ writes it.
 *
 * Run inside the dev instance: `make deps && make instance-up`, then `make integration`.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once '/var/www/html/lib/base.php';

\OC_App::loadApp('estadistica');

if (!\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForAnyone('estadistica')) {
	throw new RuntimeException('estadistica must be enabled on this instance to run integration tests');
}
