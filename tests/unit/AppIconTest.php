<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The icon pair is a contract, not decoration (territorio pins the same rules):
 * each file is a single-tint glyph naming its surface, parseable as XML — an
 * SVG that fails to parse is served with a 200 and renders nothing.
 */
class AppIconTest extends TestCase {
	private const APP_DIR = __DIR__ . '/../..';

	/** @return array<string, array{string, string}> */
	public static function iconProvider(): array {
		return [
			'app.svg on dark surfaces' => ['app.svg', '#fff'],
			'app-dark.svg on light surfaces' => ['app-dark.svg', '#000'],
		];
	}

	/** @dataProvider iconProvider */
	public function testIconIsASingleTintGlyph(string $file, string $tint): void {
		$path = self::APP_DIR . '/img/' . $file;
		self::assertFileExists($path);
		$xml = @simplexml_load_file($path);
		self::assertInstanceOf(SimpleXMLElement::class, $xml, "$file must parse as XML");
		self::assertSame('svg', $xml->getName(), "$file's root element must be the svg itself, not a wrapper");
		$source = (string)file_get_contents($path);
		self::assertStringContainsString($tint, $source, "$file must be the $tint single-tint glyph");
		self::assertStringContainsString('Surface:', $source, "$file must name its surface in a comment");
		// Exactly one fill colour: every fill attribute present must be the tint.
		preg_match_all('/fill="([^"]+)"/', $source, $fills);
		self::assertNotEmpty($fills[1], "$file must fill its glyph with the tint");
		foreach ($fills[1] as $fill) {
			self::assertSame($tint, $fill, "$file carries a second tint ($fill): the icon must be single-colour");
		}
	}
}