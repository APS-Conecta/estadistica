<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Controller\ApiController;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * POST consultas at the controller (acceptance a14, a16): which establishment
 * the local series is, and a refusal answered as a Spanish 422 whether the
 * spec, the DEIS code or the executor refuses.
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

	private ApiController $controller;

	protected function setUp(): void {
		$this->config = $this->createMock(EstablishmentConfig::class);
		$this->executor = $this->createMock(QueryExecutor::class);
		$window = $this->createStub(YearWindow::class);
		$window->method('currentYear')->willReturn(2026);

		$this->controller = new ApiController(
			$this->createStub(IRequest::class),
			$this->createStub(Sources::class),
			$this->executor,
			$this->config,
			$window,
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
}
