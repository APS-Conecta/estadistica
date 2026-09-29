<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Db\Dashboard;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\Question;
use OCA\Estadistica\Query\QueryCompiler;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\Sources;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The schema↔served-payload drift test (farmacia's L2-10), for the fuentes,
 * the consultas, the preguntas and the tableros (acceptance a14): openapi.json
 * names exactly the keys the routes serve. The spec is generated from
 * ApiController's @return docblocks and the served shape is read from the
 * shapers that build it (Sources, QueryCompiler::points, QueryExecutor::label
 * and ::response, Question::toClient, Dashboard::toClient), so this test holds
 * the two together without either copying the other.
 */
final class ApiContractTest extends TestCase {
	private const FUENTE = ['id', 'nombre', 'origen', 'fecha_corte', 'filas', 'estado', 'anios'];

	private const ANIO = ['anio', 'fecha_corte', 'filas', 'estado', 'sin_descripcion', 'etiquetas_de'];

	private const RESPUESTA = ['series', 'etiquetas', 'fuente'];

	private const SERIES = ['local', 'nacional', 'pares'];

	private const PUNTO = ['grupo', 'valor', 'informantes'];

	private const ETIQUETAS = ['local', 'pares', 'codigos'];

	private const ETIQUETA = ['codigo', 'hoja', 'seccion', 'prestacion', 'columnas', 'nota'];

	private const PREGUNTA = ['id', 'titulo', 'consulta', 'tipo', 'autor', 'autor_nombre', 'actualizada', 'editable'];

	private const TABLERO = ['id', 'titulo', 'tarjetas', 'autor', 'autor_nombre', 'actualizado', 'editable'];

	private const TARJETA = ['pregunta', 'ancho'];

	private const PATH = '/ocs/v2.php/apps/estadistica/api/v1/fuentes';

	private const CONSULTAS = '/ocs/v2.php/apps/estadistica/api/v1/consultas';

	private const PREGUNTAS = '/ocs/v2.php/apps/estadistica/api/v1/preguntas';

	private const TABLEROS = '/ocs/v2.php/apps/estadistica/api/v1/tableros';

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

	/** @return array{0: list<string>, 1: list<string>} the body parameters a route's spec names, in order, and the required ones */
	private static function body(string $path, string $verb): array {
		$body = self::spec()['paths'][$path][$verb]['requestBody']['content']['application/json']['schema'] ?? null;
		self::assertIsArray($body, "the $path " . strtoupper($verb) . ' request body is gone from the spec');

		return [array_keys($body['properties']), $body['required'] ?? []];
	}

	/**
	 * @param list<string> $path the parameters the route takes from its path
	 * @return list<string> a controller method's parameters, less those in the path
	 */
	private static function parameters(string $method, array $path = []): array {
		$names = array_map(
			static fn (ReflectionParameter $parameter): string => $parameter->getName(),
			(new ReflectionMethod(ApiController::class, $method))->getParameters(),
		);

		return array_values(array_diff($names, $path));
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

	public function testPreguntasAndTablerosServeTheSameKeys(): void {
		$question = new Question();
		$question->setId(7);
		$question->setTitle('Controles');
		$question->setSpec('{"fuente":"rem-a"}');
		$question->setChart('barra');
		$question->setOwner('ana');
		$question->setUpdatedAt(1);
		$dashboard = new Dashboard();
		$dashboard->setId(3);
		$dashboard->setTitle('Metas');
		$dashboard->setLayout('[{"pregunta":7,"ancho":2}]');
		$dashboard->setOwner('ana');
		$dashboard->setUpdatedAt(1);

		$pregunta = $question->toClient('Ana', true);
		$tablero = $dashboard->toClient('Ana', false);

		self::assertSame(self::PREGUNTA, array_keys($pregunta), 'the served key set moved — update the @return docblock and regenerate the spec');
		self::assertSame(['fuente' => 'rem-a'], $pregunta['consulta'], 'the consulta is served as the object it was sent as');
		self::assertSame(self::TABLERO, array_keys($tablero));
		self::assertSame(self::TARJETA, array_keys($tablero['tarjetas'][0]));
	}

	public function testTheSpecNamesExactlyThePreguntaAndTableroKeys(): void {
		foreach ([[self::PREGUNTAS, self::PREGUNTA], [self::TABLEROS, self::TABLERO]] as [$path, $keys]) {
			self::assertSame($keys, array_keys(self::data($path)['items']['properties']), "GET $path");
			self::assertSame($keys, array_keys(self::data($path . '/{id}')['properties']), "GET $path/{id}");
			self::assertSame($keys, array_keys(self::data($path, 'post', '201')['properties']), "POST $path");
			self::assertSame($keys, array_keys(self::data($path . '/{id}', 'put')['properties']), "PUT $path/{id}");
		}
		self::assertSame(self::TARJETA, array_keys(self::data(self::TABLEROS)['items']['properties']['tarjetas']['items']['properties']));
	}

	/** The bodies a consumer sends are exactly the controllers' parameters, the path's id aside. */
	public function testThePreguntaAndTableroBodiesAreTheControllersParameters(): void {
		self::assertSame([['titulo', 'consulta', 'tipo'], ['titulo', 'consulta']], self::body(self::PREGUNTAS, 'post'));
		self::assertSame(self::parameters('crearPregunta'), self::body(self::PREGUNTAS, 'post')[0]);
		self::assertSame([['titulo', 'consulta', 'tipo'], []], self::body(self::PREGUNTAS . '/{id}', 'put'));
		self::assertSame(self::parameters('editarPregunta', ['id']), self::body(self::PREGUNTAS . '/{id}', 'put')[0]);

		self::assertSame([['titulo', 'tarjetas'], ['titulo']], self::body(self::TABLEROS, 'post'));
		self::assertSame(self::parameters('crearTablero'), self::body(self::TABLEROS, 'post')[0]);
		self::assertSame([['titulo', 'tarjetas'], []], self::body(self::TABLEROS . '/{id}', 'put'));
		self::assertSame(self::parameters('editarTablero', ['id']), self::body(self::TABLEROS . '/{id}', 'put')[0]);
	}
}
