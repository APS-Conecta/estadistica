<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\ForbiddenException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\SavedContent;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * POST consultas at the controller (acceptance a14, a16): which establishment
 * the local series is, and a refusal answered as a Spanish 422 whether the
 * spec, the DEIS code or the executor refuses. And the preguntas and tableros
 * routes (a8): a save answers 201, and SavedContent's three refusals answer
 * 404, 403 and 422, each with its sentence.
 */
final class ApiControllerTest extends TestCase {
	private const SPEC = [
		'fuente' => 'rem-a',
		'periodo' => ['desde' => '2026-01', 'hasta' => '2026-03'],
		'filtros' => ['codigos' => ['02010420'], 'columnas' => ['col01']],
		'comparar' => ['nacional' => true],
	];

	private EstablishmentConfig&MockObject $config;

	private QueryExecutor&MockObject $executor;

	private SavedContent&MockObject $saved;

	private ApiController $controller;

	protected function setUp(): void {
		$this->config = $this->createMock(EstablishmentConfig::class);
		$this->executor = $this->createMock(QueryExecutor::class);
		$this->saved = $this->createMock(SavedContent::class);
		$window = $this->createStub(YearWindow::class);
		$window->method('currentYear')->willReturn(2026);

		$this->controller = new ApiController(
			$this->createStub(IRequest::class),
			$this->createStub(Sources::class),
			$this->executor,
			$this->config,
			$window,
			$this->saved,
		);
	}

	/** a14: a consulta without a DEIS code binds the one app config holds. */
	public function testAConsultaWithoutADeisCodeBindsTheConfiguredOne(): void {
		$configured = Establishment::of('900001');
		$this->config->expects(self::once())->method('get')->willReturn($configured);
		$this->executor->expects(self::once())->method('answer')
			->with(self::isInstanceOf(QuerySpec::class), self::identicalTo($configured))
			->willReturn(['series' => []]);

		$response = $this->controller->consultas(self::SPEC);

		self::assertSame([Http::STATUS_OK, ['series' => []]], [$response->getStatus(), $response->getData()]);
	}

	/** gestion ADR-0013: the DEIS code is a parameter, and a named one is the local series instead. */
	public function testANamedDeisCodeIsTheLocalSeries(): void {
		$this->config->expects(self::never())->method('get');
		$this->executor->expects(self::once())->method('answer')
			->with(self::isInstanceOf(QuerySpec::class), self::callback(static fn (Establishment $local): bool => $local->deisCode === '900002'))
			->willReturn([]);

		self::assertSame(Http::STATUS_OK, $this->controller->consultas(self::SPEC, '900002')->getStatus());
	}

	public function testADeisCodeThatIsNotOneIsA422InSpanish(): void {
		$this->executor->expects(self::never())->method('answer');

		$response = $this->controller->consultas(self::SPEC, '9001');

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertStringContainsString('no es un código DEIS de establecimiento', $response->getData()['message']);
	}

	/** a16: a tampered spec is refused before it reaches the database. */
	public function testATamperedSpecIsA422InSpanishAndNeverReachesTheExecutor(): void {
		$this->config->method('get')->willReturn(Establishment::none());
		$this->executor->expects(self::never())->method('answer');

		$response = $this->controller->consultas(['metrica' => 'max'] + self::SPEC);

		self::assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		self::assertSame(['message' => 'La métrica «max» no existe: es suma o promedio_establecimiento.'], $response->getData());
	}

	public function testWhatTheExecutorRefusesIsA422Too(): void {
		$this->config->method('get')->willReturn(Establishment::none());
		$this->executor->method('answer')->willThrowException(new RefusedException('Ningún diccionario del periodo tiene la hoja «A99».'));

		$response = $this->controller->consultas(self::SPEC);

		self::assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['message' => 'Ningún diccionario del periodo tiene la hoja «A99».']], [$response->getStatus(), $response->getData()]);
	}

	public function testAPreguntaIsSavedWith201(): void {
		$this->saved->expects(self::once())->method('createQuestion')
			->with('Controles de salud', self::SPEC, 'linea')
			->willReturn(['id' => 7]);

		$response = $this->controller->crearPregunta('Controles de salud', self::SPEC, 'linea');

		self::assertSame([Http::STATUS_CREATED, ['id' => 7]], [$response->getStatus(), $response->getData()]);
	}

	public function testAPreguntaSavedWithoutAChartTypeIsDrawnAsBars(): void {
		$this->saved->expects(self::once())->method('createQuestion')->with('Controles', self::SPEC, 'barra')->willReturn([]);

		$this->controller->crearPregunta('Controles', self::SPEC);
	}

	public function testAPreguntaRefusedIsA422InSpanish(): void {
		$this->saved->method('createQuestion')->willThrowException(new RefusedException('Póngale un título: es lo que el resto del personal verá en la lista.'));

		$response = $this->controller->crearPregunta(' ', self::SPEC);

		self::assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['message' => 'Póngale un título: es lo que el resto del personal verá en la lista.']], [$response->getStatus(), $response->getData()]);
	}

	/** a8: someone else's pregunta is read, never changed. */
	public function testChangingSomeoneElsesPreguntaIsA403InSpanish(): void {
		$this->saved->method('updateQuestion')->willThrowException(new ForbiddenException('Solo quien creó esta pregunta, o quien administra Estadística, puede cambiarla o eliminarla.'));

		$response = $this->controller->editarPregunta(7, 'Otro título');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertStringContainsString('Solo quien creó esta pregunta', $response->getData()['message']);
	}

	/**
	 * A value left out of the body reaches SavedContent as null: left as it
	 * was. isNull(), because with() compares loosely and '' == null.
	 */
	public function testAnEditPassesOnlyWhatWasSent(): void {
		$this->saved->expects(self::once())->method('updateQuestion')->with(7, self::isNull(), self::isNull(), 'tabla')->willReturn(['id' => 7]);

		self::assertSame(Http::STATUS_OK, $this->controller->editarPregunta(7, tipo: 'tabla')->getStatus());
	}

	public function testDeletingAPreguntaThatIsNotThereIs404(): void {
		$this->saved->method('deleteQuestion')->willThrowException(new DoesNotExistException(''));

		$response = $this->controller->eliminarPregunta(99);

		self::assertSame([Http::STATUS_NOT_FOUND, []], [$response->getStatus(), $response->getData()]);
	}

	public function testDeletingYourOwnPreguntaAnswers200(): void {
		$this->saved->expects(self::once())->method('deleteQuestion')->with(7);

		self::assertSame(Http::STATUS_OK, $this->controller->eliminarPregunta(7)->getStatus());
	}

	public function testATableroIsSavedWith201AndItsRefusalsAreAnswered(): void {
		$cards = [['pregunta' => 7, 'ancho' => 2]];
		$this->saved->expects(self::once())->method('createDashboard')->with('Metas', $cards)->willReturn(['id' => 3]);
		self::assertSame(Http::STATUS_CREATED, $this->controller->crearTablero('Metas', $cards)->getStatus());

		$this->saved->method('updateDashboard')->willThrowException(new RefusedException('La pregunta 8 no existe: puede que la hayan eliminado.'));
		$response = $this->controller->editarTablero(3, tarjetas: [['pregunta' => 8]]);
		self::assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['message' => 'La pregunta 8 no existe: puede que la hayan eliminado.']], [$response->getStatus(), $response->getData()]);

		$this->saved->method('deleteDashboard')->willThrowException(new ForbiddenException('Solo quien creó este tablero, o quien administra Estadística, puede cambiarlo o eliminarlo.'));
		self::assertSame(Http::STATUS_FORBIDDEN, $this->controller->eliminarTablero(3)->getStatus());
	}

	public function testAMissingTableroIs404(): void {
		$this->saved->method('getDashboard')->willThrowException(new DoesNotExistException(''));

		self::assertSame(Http::STATUS_NOT_FOUND, $this->controller->tablero(99)->getStatus());
	}
}
