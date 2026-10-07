<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit;

use DOMDocument;
use OCA\Estadistica\Controller\AdminController;
use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Controller\PageController;
use OCA\Estadistica\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The repo says true things about itself, and this file fails when they stop
 * being true (farmacia's MetadataTest, extended with the promises this app
 * adds: PostgreSQL only, delegation declared on every admin write, and every
 * OCS route open, rate-limited and attribute-routed).
 */
class MetadataTest extends TestCase {
	private const APP_DIR = __DIR__ . '/../..';

	private function infoXml(): DOMDocument {
		$doc = new DOMDocument();
		self::assertTrue($doc->load(self::APP_DIR . '/appinfo/info.xml'), 'appinfo/info.xml must parse');
		return $doc;
	}

	private function text(DOMDocument $doc, string $tag): string {
		$nodes = $doc->getElementsByTagName($tag);
		self::assertGreaterThan(0, $nodes->length, "info.xml must carry <$tag>");
		return trim((string)$nodes->item(0)->textContent);
	}

	/** @return list<ReflectionMethod> the public actions a class declares itself */
	private function actions(string $class): array {
		$actions = [];
		foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() === $class && !str_starts_with($method->getName(), '__')) {
				$actions[] = $method;
			}
		}
		return $actions;
	}

	public function testEveryFileThatDeclaresAVersionDeclaresTheSameOne(): void {
		// "Agree", not "exist": openapi.json joins the loop the day it exists.
		$expected = $this->text($this->infoXml(), 'version');

		foreach (['package.json' => 'version', 'openapi.json' => null] as $file => $key) {
			$path = self::APP_DIR . '/' . $file;
			if (!file_exists($path)) {
				continue;
			}
			$json = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$found = $key === null ? ($json['info']['version'] ?? null) : ($json[$key] ?? null);
			if ($found === null) {
				continue;
			}
			self::assertSame($expected, $found, "$file declares a version info.xml does not");
		}
	}

	public function testThePublishedContractDescribesTheAppItShips(): void {
		$summary = $this->text($this->infoXml(), 'summary');
		self::assertNotSame('', $summary, 'info.xml declares no summary, so everything below would be vacuous');

		$path = self::APP_DIR . '/openapi.json';
		if (!file_exists($path)) {
			self::markTestSkipped('openapi.json ships with the OCS surface (Slice 4)');
		}

		$spec = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($summary, $spec['info']['description'] ?? null,
			'openapi.json describes the app differently from info.xml — it is generated, so run scripts/openapi.sh');
	}

	public function testPackageDescriptionCarriesTheSummary(): void {
		$summary = $this->text($this->infoXml(), 'summary');
		$package = json_decode((string)file_get_contents(self::APP_DIR . '/package.json'), true, 512, JSON_THROW_ON_ERROR);
		self::assertStringContainsString($summary, $package['description'] ?? '', 'package.json description must carry the info.xml summary verbatim');
	}

	public function testLicenceIsTheSpdxAgplId(): void {
		self::assertSame('AGPL-3.0-or-later', $this->text($this->infoXml(), 'licence'));
	}

	public function testTheDeclaredLicenceIsActuallyShipped(): void {
		$text = (string)file_get_contents(self::APP_DIR . '/LICENSE');
		self::assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', substr($text, 0, 200));
		self::assertGreaterThan(30_000, strlen($text), 'the AGPL text is long; a short file means the licence was not copied');
	}

	public function testEveryPhpFileUnderLibCarriesTheSpdxHeader(): void {
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::APP_DIR . '/lib', \FilesystemIterator::SKIP_DOTS));
		$checked = 0;
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$checked++;
			$head = (string)substr((string)file_get_contents($file->getPathname()), 0, 4096);
			self::assertStringContainsString('SPDX-License-Identifier: AGPL-3.0-or-later', $head, "{$file->getPathname()} must carry the SPDX header");
		}
		self::assertGreaterThan(0, $checked, 'lib/ must contain PHP files by now');
	}

	/** docs/adr/0002: the engine is PostgreSQL by construction, so the app says so. */
	public function testTheAppDeclaresPostgresqlOnly(): void {
		$databases = [];
		foreach ($this->infoXml()->getElementsByTagName('database') as $node) {
			$databases[] = trim((string)$node->textContent);
		}
		self::assertSame(['pgsql'], $databases, 'info.xml must declare <database min-version="18">pgsql</database> and nothing else');
	}

	/**
	 * docs/adr/0003: the section is delegable only if core can find it as an
	 * IDelegatedSettings — occ admin-delegation:add refuses anything else.
	 */
	public function testTheRegisteredAdminSectionIsDelegable(): void {
		self::assertSame(AdminSettings::class, $this->text($this->infoXml(), 'admin'));
		self::assertContains(IDelegatedSettings::class, class_implements(AdminSettings::class));
	}

	/**
	 * docs/adr/0003: every admin write names the section in
	 * #[AuthorizedAdminSetting] — the attribute IS the permission, so a method
	 * without it is admin-only and silently refuses every delegate. And none
	 * carries #[NoAdminRequired], which would open it to everyone.
	 */
	public function testEveryAdminWriteDeclaresItsDelegation(): void {
		$actions = $this->actions(AdminController::class);
		self::assertNotEmpty($actions, 'AdminController must have actions by now');

		foreach ($actions as $method) {
			$name = AdminController::class . '::' . $method->getName();
			$attributes = $method->getAttributes(AuthorizedAdminSetting::class);
			self::assertCount(1, $attributes, "$name must carry #[AuthorizedAdminSetting]");
			self::assertSame(['settings' => AdminSettings::class], $attributes[0]->getArguments(),
				"$name must delegate through AdminSettings, the section admins hand to a group");
			self::assertCount(0, $method->getAttributes(NoAdminRequired::class),
				"$name must not be #[NoAdminRequired]: that opens an admin write to every user");
		}
	}

	/** The IntraVox anti-pattern: a manual admin check refuses every delegate. */
	public function testNoControllerChecksForAnAdminByHand(): void {
		$files = glob(self::APP_DIR . '/lib/Controller/*.php') ?: [];
		self::assertNotEmpty($files, 'lib/Controller must contain controllers');
		foreach ($files as $file) {
			self::assertStringNotContainsString('isAdmin(', (string)file_get_contents($file),
				basename($file) . ' checks isAdmin() by hand — delegation is the attribute\'s job');
		}
	}

	/** Every staff member reads everything (D6): the page is open to every user. */
	public function testEveryPageActionIsOpenToEveryUser(): void {
		foreach ($this->actions(PageController::class) as $method) {
			self::assertCount(1, $method->getAttributes(NoAdminRequired::class),
				PageController::class . '::' . $method->getName() . ' must be #[NoAdminRequired]');
		}
	}

	/**
	 * a14: every OCS route is routed by its attribute, open to every user (D6)
	 * and rate-limited. An attribute deleted from one method is a route that
	 * is gone, admin-only or unbounded, silently (farmacia's gate).
	 */
	public function testEveryOcsRouteIsRoutedOpenAndRateLimited(): void {
		$actions = $this->actions(ApiController::class);
		self::assertNotEmpty($actions, 'ApiController must have routes by now');

		foreach ($actions as $method) {
			$name = ApiController::class . '::' . $method->getName();
			self::assertCount(1, $method->getAttributes(ApiRoute::class), "$name must carry #[ApiRoute]");
			self::assertCount(1, $method->getAttributes(NoAdminRequired::class), "$name must be #[NoAdminRequired]: every staff member reads (D6)");
			self::assertCount(1, $method->getAttributes(UserRateLimit::class), "$name must carry #[UserRateLimit]");
		}
	}
}
