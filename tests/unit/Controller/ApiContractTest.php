<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Query\QueryCompiler;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\Sources;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The schema↔served-payload drift test (farmacia's L2-10), for the fuentes
 * and the consultas (acceptance a14): openapi.json names exactly the keys the
 * routes serve. The spec is generated from ApiController's @return docblocks
 * and the served shape is read from the shapers that build it (Sources,
 * QueryCompiler::points, QueryExecutor::label and ::response), so this test
 * holds the two together without either copying the other.
 */
final class ApiContractTest extends TestCase {
	private const FUENTE = ['id', 'nombre', 'origen', 'fecha_corte', 'filas', 'estado', 'anios'];

	private const ANIO = ['anio', 'fecha_corte', 'filas', 'estado', 'sin_descripcion', 'etiquetas_de'];

	private const RESPUESTA = ['series', 'etiquetas', 'fuente'];

	private const SERIES = ['local', 'nacional', 'pares'];

	private const PUNTO = ['grupo', 'valor', 'informantes'];

	private const ETIQUETAS = ['local', 'pares', 'codigos'];

	private const ETIQUETA = ['codigo', 'hoja', 'seccion', 'prestacion', 'columnas', 'nota'];

	private const PATH = '/ocs/v2.php/apps/estadistica/api/v1/fuentes';

	private const CONSULTAS = '/ocs/v2.php/apps/estadistica/api/v1/consultas';

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

	public function testAConsultaServesTheSameKeys(): void {
		$spec = QuerySpec::from([
			'fuente' => 'rem-a',
			'periodo' => ['desde' => '2099-01', 'hasta' => '2099-02'],
			'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col01']],
			'agrupar' => ['mes'],
		], 2099);
		$point = QueryCompiler::points($spec, [['g0' => 209901, 'valor' => '1', 'informantes' => 1]])[0];
		$label = QueryExecutor::label(
			['codigo' => '02010420', 'hoja' => 'A03', 'seccion' => 'SECCIÓN A', 'prestacion' => 'Evaluación', 'columnas' => [1 => 'Total'], 'de' => 2099],
			2099,
			[1],
		);
		$fuente = Sources::rem('rem-a', [], []);

		$answer = QueryExecutor::response([$point], [$point], null, '900001', null, [$label], $fuente);

		self::assertSame(self::RESPUESTA, array_keys($answer), 'the served key set moved — update the @return docblock and regenerate the spec');
		self::assertSame(self::SERIES, array_keys($answer['series']));
		self::assertSame(self::PUNTO, array_keys($point));
		self::assertSame(self::ETIQUETAS, array_keys($answer['etiquetas']));
		self::assertSame(self::ETIQUETA, array_keys($label));
		self::assertSame(self::FUENTE, array_keys($answer['fuente']), 'a consulta carries the fuente as GET fuentes serves it');
	}

	public function testTheSpecNamesExactlyTheKeysAConsultaServes(): void {
		$data = self::data(self::CONSULTAS, 'post');
		self::assertSame(self::RESPUESTA, array_keys($data['properties']));

		$series = $data['properties']['series']['properties'];
		self::assertSame(self::SERIES, array_keys($series));
		foreach (self::SERIES as $name) {
			self::assertSame(self::PUNTO, array_keys($series[$name]['items']['properties']), "series.$name");
			self::assertTrue($series[$name]['nullable'] ?? false, "series.$name is null when it was not asked for");
		}

		$etiquetas = $data['properties']['etiquetas']['properties'];
		self::assertSame(self::ETIQUETAS, array_keys($etiquetas));
		self::assertSame(self::ETIQUETA, array_keys($etiquetas['codigos']['items']['properties']));
		self::assertSame(self::FUENTE, array_keys($data['properties']['fuente']['properties']));
	}

	/** The body a consumer sends is exactly the controller's parameters, the spec required. */
	public function testTheConsultaBodyIsTheControllersParameters(): void {
		$body = self::spec()['paths'][self::CONSULTAS]['post']['requestBody']['content']['application/json']['schema'] ?? null;
		self::assertIsArray($body, 'the consultas request body is gone from the spec');

		$parameters = array_map(
			static fn (ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(ApiController::class, 'consultas'))->getParameters(),
		);
		self::assertSame(['consulta', 'establecimiento'], $parameters);
		self::assertSame($parameters, array_keys($body['properties']));
		self::assertSame(['consulta'], $body['required']);
	}
}
