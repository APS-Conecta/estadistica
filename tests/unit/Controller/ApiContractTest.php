<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Service\Sources;
use PHPUnit\Framework\TestCase;

/**
 * The schema↔served-payload drift test (farmacia's L2-10), for the fuentes
 * (acceptance a14): openapi.json names exactly the keys the routes serve. The
 * spec is generated from ApiController's @return docblocks and the served
 * shape is read from the shapers that build it (Sources), so this test holds
 * the two together without either copying the other.
 */
final class ApiContractTest extends TestCase {
	private const FUENTE = ['id', 'nombre', 'origen', 'fecha_corte', 'filas', 'estado', 'anios'];

	private const ANIO = ['anio', 'fecha_corte', 'filas', 'estado', 'sin_descripcion', 'etiquetas_de'];

	private const PATH = '/ocs/v2.php/apps/estadistica/api/v1/fuentes';

	/** @return array<string, mixed> */
	private static function spec(): array {
		$spec = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/openapi.json'), true);
		self::assertIsArray($spec, 'openapi.json is missing or unparseable — it is generated: run scripts/openapi.sh');

		return $spec;
	}

	/** @return array<string, mixed> the ocs.data schema of a route's success response */
	private static function data(string $path, string $verb = 'get', string $status = '200'): array {
		$data = self::spec()['paths'][$path][$verb]['responses'][$status]['content']['application/json']['schema']['properties']['ocs']['properties']['data'] ?? null;
		self::assertIsArray($data, "the $path " . strtoupper($verb) . " $status response is gone from the spec");

		return $data;
	}

	public function testEveryFuenteServesTheSameKeys(): void {
		$state = new IngestState();
		$state->setAnio(2099);
		$state->setSerie('A');
		$state->setRowCount(12);
		$state->setFechaCorte('2099-02');
		$dictionary = ['anio' => 2099, 'serie' => 'A', 'file' => null, 'codes' => 3, 'unmapped' => 1, 'labelsFrom' => 2099, 'error' => null, 'checkedAt' => 1];

		$rem = Sources::rem('rem-a', [$state], [$dictionary]);
		$register = Sources::register(['origin' => null, 'year' => null, 'loadedAt' => null, 'rows' => 0]);

		self::assertSame(self::FUENTE, array_keys($rem), 'the served key set moved — update the @return docblock and regenerate the spec');
		self::assertSame(self::ANIO, array_keys($rem['anios'][0]));
		self::assertSame(self::FUENTE, array_keys($register), 'every fuente has one shape');
	}

	public function testTheSpecNamesExactlyTheServedKeys(): void {
		$list = self::data(self::PATH);
		self::assertSame(self::FUENTE, array_keys($list['items']['properties']));
		self::assertSame(self::ANIO, array_keys($list['items']['properties']['anios']['items']['properties']));

		$one = self::data(self::PATH . '/{id}');
		self::assertSame([...self::FUENTE, 'catalogo'], array_keys($one['properties']));
	}
}
