<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Controller;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Controller\AdminController;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Goals\Goals;
use OCA\Estadistica\Piv\PivImporter;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Service\DroppedFiles;
use OCA\Estadistica\Service\YearWindow;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The admin actions' own sequencing: what runs after what, and what a refusal
 * stops. Who may call them is core's, from the attribute (MetadataTest, and
 * smoke's delegate 200 / outsider 403); what each service does is its own
 * suite's.
 */
final class AdminControllerTest extends TestCase {
	private YearWindow&MockObject $window;

	private DeisRepository&MockObject $deis;

	private DroppedFiles&MockObject $drops;

	private PivImporter&MockObject $piv;

	private Goals&MockObject $goals;

	protected function setUp(): void {
		$this->window = $this->createMock(YearWindow::class);
		$this->deis = $this->createMock(DeisRepository::class);
		$this->drops = $this->createMock(DroppedFiles::class);
		$this->piv = $this->createMock(PivImporter::class);
		$this->goals = $this->createMock(Goals::class);
	}

	private function controller(): AdminController {
		return new AdminController($this->createMock(IRequest::class), $this->window, $this->drops,
			$this->createMock(IngestStateMapper::class), $this->deis, $this->createMock(Catalog::class),
			$this->createMock(Register::class), $this->piv, $this->goals);
	}

	/** The choice is recorded first; only then is DEIS asked again, and the window answered as stored. */
	public function testAWindowChoiceIsStoredThenDeisIsCheckedAgain(): void {
		$calls = [];
		$this->window->method('choose')->willReturnCallback(function (int $year) use (&$calls): void {
			$calls[] = "choose $year";
		});
		$this->deis->method('recheck')->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'recheck';
		});
		$this->window->method('toArray')->willReturn(['firstYear' => 2020]);

		$response = $this->controller()->window(2020);

		self::assertSame(['choose 2020', 'recheck'], $calls);
		self::assertSame(['firstYear' => 2020], $response->getData());
	}

	/** A year the window refuses changes nothing: DEIS is not asked again. */
	public function testARefusedWindowIsA422AndAsksDeisNothing(): void {
		$this->window->method('choose')->willThrowException(new RefusedException('El primer año debe estar entre 2009 y 2026.'));
		$this->deis->expects(self::never())->method('recheck');

		$response = $this->controller()->window(1990);

		self::assertSame(422, $response->getStatus());
		self::assertSame(['message' => 'El primer año debe estar entre 2009 y 2026.'], $response->getData());
	}

	public function testADropIsHandedOverByPathAndARefusalIsA422InSpanish(): void {
		$this->drops->expects(self::once())->method('accept')->with('/REM/SERIE_REM_2026.zip')
			->willThrowException(new RefusedException('El ZIP mezcla años.'));

		$response = $this->controller()->drop('/REM/SERIE_REM_2026.zip');

		self::assertSame([422, ['message' => 'El ZIP mezcla años.']], [$response->getStatus(), $response->getData()]);
	}

	/** @return array<string, array{string}> */
	public static function writes(): array {
		return ['the PIV typed here' => ['piv'], 'the metas comprometidas' => ['goals']];
	}

	/**
	 * Each typed write answers with the state it produced, read after the write.
	 *
	 * @dataProvider writes
	 */
	public function testATypedWriteAnswersWithTheStateReadAfterIt(string $action): void {
		$calls = [];
		[$service, $write] = $action === 'piv' ? [$this->piv, 'enter'] : [$this->goals, 'commit'];
		$service->method($write)->willReturnCallback(function () use (&$calls): void {
			$calls[] = 'write';
		});
		$service->method('status')->willReturnCallback(function () use (&$calls): array {
			$calls[] = 'status';
			return ['written' => true];
		});

		$response = $this->controller()->$action(2026, [['edad' => '0-4', 'sexo' => 'F', 'inscritos' => 10]]);

		self::assertSame(['write', 'status'], $calls);
		self::assertSame(['written' => true], $response->getData());
	}
}
