<?php

declare(strict_types=1);

// The internal routes (English URLs, farmacia's language split). The OCS
// surface (Slice 4 on) registers by attribute on ApiController, under Spanish
// resource names.
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// The admin section's actions. Every one is delegated by the
		// #[AuthorizedAdminSetting] on its controller method and by nothing
		// else — no group check in code (docs/adr/0003).
		['name' => 'admin#window', 'url' => '/admin/window', 'verb' => 'PUT'],
		['name' => 'admin#drop', 'url' => '/admin/drop', 'verb' => 'POST'],
		['name' => 'admin#ingests', 'url' => '/admin/ingests', 'verb' => 'GET'],
	],
];
