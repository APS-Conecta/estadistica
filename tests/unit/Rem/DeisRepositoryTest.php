<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Rem\DeisRepository;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\MemberLines;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Service\YearWindow;
use OCA\Estadistica\Tests\Unit\Xlsx\XlsxFixture;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * DEIS acquisition with no network (acceptance a3). A fake server publishes
 * generated zips and answers the way repositoriodeis.minsal.cl was measured to
 * answer (design, 2026-09-28):
 * - 206 with an exact Content-Range;
 * - 304 on a matching If-None-Match;
 * - 404 for a year it does not publish;
 * - 200 with the whole file when If-Range names an ETag that is no longer
 *   current.
 *
 * It also publishes each year's Base de Establecimientos beside REM, which
 * the same daily pass looks at. By default only last year's is out, as on the
 * day this was measured.
 */
class DeisRepositoryTest extends TestCase {
	/** 2026-09-28 12:00 UTC: the default window is 2023–2026. */
	private const NOW = 1790596800;

	private const DAY = DeisRepository::CHECK_EVERY;

	private int $now = self::NOW;

	private bool $online = true;

	/** Every request fails as if nothing answered. */
	private bool $unreachable = false;

	/**
	 * Every range is answered one byte later than asked. A fixture zip is
	 * smaller than the tail read, so an answer "from the start" would be the
	 * right one and prove nothing.
	 */
	private bool $misrange = false;

	/** @var array<int, array{path: string, etag: string}> what the fake server publishes, by year */
	private array $published = [];

	/** @var array<int, array{path: string, etag: string}> the Base de Establecimientos it publishes, by year */
	private array $registers = [];

	/** @var list<array{kind: string, method: string, year: int, headers: array<string, string>, stream: bool}> */
	private array $requests = [];

	/** @var resource|null the last body the fake server handed out */
	private $lastBody = null;

	/** @var array<string, mixed> the fake app config */
	private array $config = [];

	/** @var array<string, array{crc: int, size: int}> "año-serie" → the member last judged on its content */
	private array $judged = [];

	/** @var list<string> "año-serie" as the check queued them */
	private array $queued = [];

	/** @var list<string> "año-serie" whose dictionary the catalogue has not judged as the one now published */
	private array $unjudged = [];

	/** @var list<array{rows: int, origin: string, year: ?int}> every register the check stored */
	private array $stored = [];

	/** @var list<string> temporary files the repository asked for */
	private array $temporary = [];

	protected function setUp(): void {
		$this->publishRegister(2025);
	}

	protected function tearDown(): void {
		ZipFixture::cleanup();
		XlsxFixture::cleanup();
		foreach ($this->temporary as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	/** Publishes $year's Base de Establecimientos: two fabricated establishments. */
	private function publishRegister(int $year, string $etag = '"r1:0"'): void {
		$this->registers[$year] = [
			'path' => XlsxFixture::register([XlsxFixture::establishment(900001), XlsxFixture::establishment(900002)]),
			'etag' => $etag,
		];
	}

	/** Publishes $year: Serie A and P, plus BS, D and a dictionary that are never read. */
	private function publish(int $year, string $etag = '"1:0"', int $months = 2, bool $withP = true): void {
		$members = [
			"SERIE_REM_$year/SerieBS$year.csv" => str_repeat("bs\r\n", 100),
			"SERIE_REM_$year/SerieA$year.csv" => ZipFixture::member($year, ZipFixture::establishments(3), months: $months),
			"SERIE_REM_$year/SerieD$year.csv" => str_repeat("d\r\n", 100),
			"SERIE_REM_$year/DICCIONARIOS/SA_{$year}_V1.0.xlsm" => str_repeat('x', 100),
		];
		if ($withP) {
			$members["SERIE_REM_$year/SerieP$year.csv"] = ZipFixture::member($year, ZipFixture::establishments(2), ['P1206010'], 1);
		}
		$this->published[$year] = ['path' => ZipFixture::zip($members), 'etag' => $etag];
	}

	/** What the job's loads would leave: every queued pair judged as the member now published. */
	private function load(): void {
		foreach ($this->queued as $pair) {
			[$year, $serie] = explode('-', $pair);
			$member = ZipDirectory::read(new LocalRange($this->published[(int)$year]['path']))->member($serie);
			$this->judged[$pair] = ['crc' => $member['crc'], 'size' => $member['size']];
		}
		$this->queued = [];
	}

	private function repository(): DeisRepository {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->config[$key] ?? $default),
		);
		$appConfig->method('getValueArray')->willReturnCallback(
			fn (string $app, string $key, array $default = []): array => (array)($this->config[$key] ?? $default),
		);
		foreach (['setValueInt', 'setValueString', 'setValueArray'] as $setter) {
			$appConfig->method($setter)->willReturnCallback(function (string $app, string $key, mixed $value): bool {
				$this->config[$key] = $value;
				return true;
			});
		}
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});

		$system = $this->createMock(IConfig::class);
		$system->method('getSystemValueBool')->willReturnCallback(
			fn (string $key, bool $default = false): bool => $key === 'has_internet_connection' ? $this->online : $default,
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturnCallback(fn (): IClient => $this->client());

		$states = $this->createMock(IngestStateMapper::class);
		$states->method('findOrNew')->willReturnCallback(function (int $anio, string $serie): IngestState {
			$state = new IngestState();
			$state->setAnio($anio);
			$state->setSerie($serie);
			if (isset($this->judged["$anio-$serie"])) {
				$state->setMemberCrc($this->judged["$anio-$serie"]['crc']);
				$state->setMemberSize($this->judged["$anio-$serie"]['size']);
			}
			return $state;
		});
		$states->method('markPending')->willReturnCallback(function (int $anio, string $serie): void {
			$this->queued[] = "$anio-$serie";
		});

		$catalog = $this->createMock(Catalog::class);
		$catalog->method('judged')->willReturnCallback(
			fn (int $anio, string $serie): bool => !in_array("$anio-$serie", $this->unjudged, true),
		);

		$register = $this->createMock(Register::class);
		$register->method('replace')->willReturnCallback(function (array $rows, string $origin, ?int $year): int {
			$this->stored[] = ['rows' => count($rows), 'origin' => $origin, 'year' => $year];
			return count($rows);
		});

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(function (): string {
			return $this->temporary[] = (string)tempnam(sys_get_temp_dir(), 'estadistica-');
		});

		return new DeisRepository($clients, $appConfig, $system, $time, new YearWindow($appConfig, $time), $states, $catalog, $register, $temp, new NullLogger());
	}

	private function client(): IClient {
		$client = $this->createMock(IClient::class);
		$client->method('head')->willReturnCallback(function (string $url, array $options): IResponse {
			$zip = $this->serve('head', $url, $options);
			if (($options['headers']['If-None-Match'] ?? null) === $zip['etag']) {
				return $this->response(304);
			}
			return $this->response(200, ['Content-Length' => (string)filesize($zip['path']), 'ETag' => $zip['etag']]);
		});
		$client->method('get')->willReturnCallback(function (string $url, array $options): IResponse {
			$zip = $this->serve('get', $url, $options);
			$size = filesize($zip['path']);
			$file = fopen($zip['path'], 'rb');
			if (isset($options['headers']['If-Range']) && $options['headers']['If-Range'] !== $zip['etag']) {
				return $this->response(200, ['Content-Length' => (string)$size], $this->lastBody = $file);
			}

			preg_match('/^bytes=(\d+)-(\d+)$/', $options['headers']['Range'], $asked);
			$from = (int)$asked[1] + ($this->misrange ? 1 : 0);
			$to = min($from + (int)$asked[2] - (int)$asked[1], $size - 1);
			$body = fopen('php://temp', 'w+b');
			stream_copy_to_stream($file, $body, $to - $from + 1, $from);
			fclose($file);
			rewind($body);

			return $this->response(206, ['Content-Range' => sprintf('bytes %d-%d/%d', $from, $to, $size)], $this->lastBody = $body);
		});
		// The real client rethrows when the failure carries no response.
		$client->method('getResponseFromThrowable')->willReturnCallback(
			fn (Throwable $e): IResponse => $e->getCode() === 404 ? $this->response(404) : throw $e,
		);

		return $client;
	}

	/** @return array{path: string, etag: string} */
	private function serve(string $method, string $url, array $options): array {
		if (preg_match('~^https://repositoriodeis\.minsal\.cl/DatosAbiertos/REM/SERIE_REM_(\d{4})\.zip$~', $url, $match) === 1) {
			$kind = 'rem';
			$files = $this->published;
		} else {
			self::assertMatchesRegularExpression('~^https://repositoriodeis\.minsal\.cl/ContenidoSitioWeb2020/Establecimientos/Base%20de%20Establecimientos%20(\d{4})\.xlsx$~', $url);
			preg_match('~(\d{4})\.xlsx$~', $url, $match);
			$kind = 'register';
			$files = $this->registers;
		}
		$year = (int)$match[1];
		$this->requests[] = ['kind' => $kind, 'method' => $method, 'year' => $year, 'headers' => $options['headers'], 'stream' => $options['stream'] ?? false];

		if ($this->unreachable) {
			throw new RuntimeException('cURL error 28: Operation timed out');
		}

		return $files[$year] ?? throw new RuntimeException('404 Not Found', 404);
	}

	/**
	 * @param array<string, string> $headers
	 * @param resource|null $body
	 */
	private function response(int $status, array $headers = [], $body = null): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getHeader')->willReturnCallback(static fn (string $key): string => $headers[$key] ?? '');
		$response->method('getBody')->willReturn($body ?? '');

		return $response;
	}

	/** @return list<array{kind: string, method: string, year: int, headers: array<string, string>, stream: bool}> */
	private function requestsFor(string $kind): array {
		return array_values(array_filter($this->requests, static fn (array $request): bool => $request['kind'] === $kind));
	}

	/** @return list<string> the requests for $kind, as "method year" */
	private function methods(string $kind = 'rem'): array {
		return array_map(static fn (array $request): string => $request['method'] . ' ' . $request['year'], $this->requestsFor($kind));
	}

	public function testTheFirstCheckQueuesEverySerieOfEveryYearInTheWindowNewestFirst(): void {
		foreach ([2023, 2024, 2025] as $year) {
			$this->publish($year);
		}
		$this->publish(2026, withP: false);
		$deis = $this->repository();
		self::assertTrue($deis->checkDue(), 'a fresh install has never checked');

		$deis->check();

		self::assertSame(['2026-A', '2025-A', '2025-P', '2024-A', '2024-P', '2023-A', '2023-P'], $this->queued);
		self::assertFalse($deis->checkDue(), 'once a day');
		self::assertSame(['online' => true, 'checkedAt' => self::NOW, 'errors' => null], $deis->status());
	}

	/** a3: to decide, the check reads the HEAD and the central directory, and no member. */
	public function testTheCheckReadsOnlyTheCentralDirectoryToDecide(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);

		$this->repository()->check();

		self::assertSame(['head 2026', 'get 2026', 'get 2026'], $this->methods(), 'a HEAD, then the tail and the directory');
	}

	/** a3, over HTTP: after the directory, only Serie A and P are fetched, never BS, D or a dictionary. */
	public function testAfterTheDirectoryOnlySerieAAndPAreFetchedByRange(): void {
		$this->publish(2026);
		$zip = $this->repository()->zip(2026);
		$directory = ZipDirectory::read($zip);
		foreach (['A', 'P'] as $serie) {
			iterator_to_array(MemberLines::of($zip, $directory->member($serie)), false);
		}

		$gets = array_values(array_filter($this->requests, static fn (array $request): bool => $request['method'] === 'get'));
		$ranges = array_map(static fn (array $get): array => array_map('intval', explode('-', substr($get['headers']['Range'], 6))), $gets);
		self::assertSame(filesize($this->published[2026]['path']) - 1, $ranges[0][1], 'the first range is the tail, where the directory is found');

		$spans = [];
		foreach (['A', 'P'] as $serie) {
			$member = $directory->member($serie);
			$spans[] = [$member['offset'], $member['offset'] + 30 + strlen($member['name']) + $member['compressedSize'] - 1];
		}
		foreach (array_slice($ranges, 2) as [$from, $to]) {
			$inside = false;
			foreach ($spans as [$first, $last]) {
				$inside = $inside || ($from >= $first && $to <= $last);
			}
			self::assertTrue($inside, "bytes $from-$to fall outside the Serie A and P members");
		}
		self::assertCount(2 + 2 * 2, $gets, 'the tail, the directory, then a header and a data range per member');

		foreach ($this->requests as $request) {
			self::assertSame(DeisRepository::USER_AGENT, $request['headers']['User-Agent'], 'every request names the app (epidemiologia ADR-0015)');
			self::assertSame('identity', $request['headers']['Accept-Encoding'], 'offsets are the file\'s own');
		}
		foreach ($gets as $get) {
			self::assertTrue($get['stream'], 'every body is streamed, never held whole (a17)');
			self::assertSame('"1:0"', $get['headers']['If-Range'], 'every read is pinned to the version the HEAD saw');
		}
	}

	/** a3: an unchanged ETag skips; nothing is read past the HEAD. */
	public function testAnUnchangedZipCostsOneHeadAndQueuesNothing(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();
		$this->load();
		$this->now += self::DAY;
		$deis->check();
		self::assertSame([], $this->queued, 'the loads settled the year, so its ETag is kept now');

		$this->requests = [];
		$this->now += self::DAY;
		$deis->check();

		self::assertSame([], $this->queued);
		self::assertSame(['head 2026'], $this->methods(), 'a 304, and nothing read');
		self::assertSame('"1:0"', $this->requests[0]['headers']['If-None-Match']);
	}

	/** a3: a changed CRC re-ingests; the ETag says when to look, the CRC says what changed. */
	public function testOnlyTheMemberDeisChangedIsQueued(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();
		$this->load();
		$this->now += self::DAY;
		$deis->check();

		// DEIS republishes: Serie A gains a month, Serie P is byte-identical.
		$this->publish(2026, '"2:0"', months: 3);
		$this->now += self::DAY;
		$deis->check();

		self::assertSame(['2026-A'], $this->queued);
	}

	/** #74: a load that failed is looked at again the next day, not hidden behind a 304. */
	public function testAPairWhoseLoadFailedIsQueuedAgainTheNextDay(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();
		$this->queued = [];

		$this->requests = [];
		$this->now += self::DAY;
		$deis->check();

		self::assertSame(['2026-A', '2026-P'], $this->queued);
		self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers'], 'no ETag is kept while the year is not settled');
	}

	/** a3: the widened window spans 2009 to the current year. */
	public function testTheWidenedWindowSpans2009ToTheCurrentYear(): void {
		$this->config[YearWindow::KEY] = 2009;
		$this->publish(2026);
		// The check reads only directories, so one zip can stand for every year.
		foreach (range(2009, 2025) as $year) {
			$this->published[$year] = $this->published[2026];
		}

		$this->repository()->check();

		$heads = array_column(array_filter($this->requestsFor('rem'), static fn (array $request): bool => $request['method'] === 'head'), 'year');
		self::assertSame(range(2026, 2009), array_values($heads));
		self::assertCount(2 * 18, $this->queued);
	}

	public function testAnUnpublishedCurrentYearIsQuietButAPastYearGoneIsSaid(): void {
		$this->config[YearWindow::KEY] = 2025;
		$this->publish(2025);
		$deis = $this->repository();
		$deis->check();
		self::assertSame(['2025-A', '2025-P'], $this->queued);
		self::assertNull($deis->status()['errors'], 'early in a year, DEIS has not published it yet');

		// The address moves (epidemiologia ADR-0014): a past year stops answering.
		unset($this->published[2025]);
		$this->now += self::DAY;
		$deis->check();

		self::assertSame('2025: El DEIS no publica SERIE_REM_2025.zip.', $deis->status()['errors']);
	}

	/** #74: one year failing, here a garbage 200 where its zip should be, leaves the others checked. */
	public function testOneYearFailingLeavesTheOthersCheckedAndIsSaid(): void {
		$this->config[YearWindow::KEY] = 2024;
		$this->publish(2026);
		$this->published[2025] = ['path' => ZipFixture::file(ZipFixture::GARBAGE), 'etag' => '"1:0"'];
		$this->publish(2024);
		$deis = $this->repository();

		$deis->check();

		self::assertSame(['2026-A', '2026-P', '2024-A', '2024-P'], $this->queued);
		self::assertSame('2025: El archivo no es un ZIP (no se encontró su índice).', $deis->status()['errors']);
	}

	public function testAZipWithNoSerieUnderAKnownNameIsSaidNotSettled(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->published[2026] = ['path' => ZipFixture::zip(['REM_A_2026.csv' => 'a']), 'etag' => '"1:0"'];
		$deis = $this->repository();

		$deis->check();

		self::assertSame([], $this->queued);
		self::assertStringContainsString('cambiado el nombre de sus archivos', (string)$deis->status()['errors']);
		$this->requests = [];
		$this->now += self::DAY;
		$deis->check();
		self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers'], 'looked at again, never taken as settled');
	}

	public function testAHostThatDoesNotAnswerIsSaidAndAskedAgainOnlyTomorrow(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$this->unreachable = true;
		$deis = $this->repository();

		$deis->check();

		self::assertSame([], $this->queued);
		self::assertSame(
			"2026: No se pudo conectar con el DEIS para pedir SERIE_REM_2026.zip. Se volverá a intentar.\n"
			. 'Base de Establecimientos: No se pudo conectar con el DEIS para pedir Base de Establecimientos 2026.xlsx. Se volverá a intentar.',
			$deis->status()['errors'],
		);
		self::assertFalse($deis->checkDue(), 'failed or not, the check runs once a day');
	}

	/** If-Range: offsets read from one version are never applied to another. */
	public function testAZipReplacedMidReadIsRefusedBeforeAnyOfItIsRead(): void {
		$this->publish(2026);
		$zip = $this->repository()->zip(2026);
		$member = ZipDirectory::read($zip)->member('A');
		$this->publish(2026, '"2:0"', months: 3);

		try {
			iterator_to_array(MemberLines::of($zip, $member), false);
			self::fail('a read against a replaced zip was taken for data');
		} catch (RefusedException $e) {
			self::assertStringContainsString('El DEIS cambió SERIE_REM_2026.zip mientras se leía', $e->getMessage());
		}
		self::assertFalse(is_resource($this->lastBody), 'the whole-file answer was closed unread');
	}

	public function testARangeTheServerDidNotHonourIsRefused(): void {
		$this->publish(2026);
		$this->misrange = true;

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('una parte de SERIE_REM_2026.zip distinta de la pedida');

		ZipDirectory::read($this->repository()->zip(2026));
	}

	public function testAnInstallWithoutInternetNeverCallsDeis(): void {
		$this->online = false;
		$this->publish(2026);
		$deis = $this->repository();

		self::assertFalse($deis->checkDue());
		self::assertFalse($deis->status()['online']);
		try {
			$deis->zip(2026);
			self::fail('an install without internet was handed a DEIS reader');
		} catch (RefusedException $e) {
			self::assertStringContainsString('sin acceso a internet', $e->getMessage());
		}
		self::assertSame([], $this->requests);
	}

	public function testAChangedWindowIsCheckedWithinTheHourAndAForgottenYearIsReadAfresh(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();
		$this->load();
		$this->now += self::DAY;
		$deis->check();
		self::assertFalse($deis->checkDue());

		$deis->recheck();
		self::assertTrue($deis->checkDue(), 'the next idle tick checks, instead of waiting out the day');

		$deis->forget(2026);
		$this->requests = [];
		$deis->check();
		self::assertArrayNotHasKey('If-None-Match', $this->requests[0]['headers'], 'a year let go is not answered by a 304 when it comes back');
	}

	/** A dictionary DEIS republishes alone: its serie is read again, so the labels follow it. */
	public function testADictionaryRepublishedAloneRequeuesItsSerie(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();
		$this->load();
		$this->now += self::DAY;
		$deis->check();
		self::assertSame([], $this->queued);

		$this->publish(2026, '"2:0"');
		$this->unjudged = ['2026-A'];
		$this->now += self::DAY;
		$deis->check();

		self::assertSame(['2026-A'], $this->queued, 'Serie A\'s figures are byte-identical; its dictionary is not');
	}

	/** The newest Base de Establecimientos: last year's while this year's is not out, read whole, stored. */
	public function testTheRegisterIsTheNewestOneDeisPublishes(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();

		$deis->check();

		self::assertSame(['head 2026', 'head 2025', 'get 2025'], $this->methods('register'), 'this year\'s is not out yet: quiet');
		self::assertSame([['rows' => 2, 'origin' => IngestState::ORIGIN_DEIS, 'year' => 2025]], $this->stored);
		self::assertSame(['year' => 2025, 'etag' => '"r1:0"'], $this->config[DeisRepository::KEY_REGISTER]);
		self::assertNull($deis->status()['errors']);
		foreach ($this->requestsFor('register') as $request) {
			self::assertSame(DeisRepository::USER_AGENT, $request['headers']['User-Agent']);
		}
		self::assertSame([], array_filter($this->temporary, 'is_file'), 'the temporary copy is gone');
	}

	public function testAnUnchangedRegisterCostsTwoHeadsAndThisYearsReplacesIt(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$deis = $this->repository();
		$deis->check();

		$this->requests = [];
		$this->now += self::DAY;
		$deis->check();
		self::assertSame(['head 2026', 'head 2025'], $this->methods('register'), 'a 404 and a 304');
		self::assertSame('"r1:0"', $this->requestsFor('register')[1]['headers']['If-None-Match']);
		self::assertCount(1, $this->stored);

		$this->publishRegister(2026, '"r2:0"');
		$this->now += self::DAY;
		$deis->check();
		self::assertSame(2026, $this->stored[1]['year']);
		self::assertSame(['year' => 2026, 'etag' => '"r2:0"'], $this->config[DeisRepository::KEY_REGISTER]);
	}

	/** #74: a file that is not the register is said, and the register loaded before stays. */
	public function testARegisterThatCannotBeReadIsSaidAndStoresNothing(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$this->registers[2025]['path'] = XlsxFixture::register([XlsxFixture::establishment(900001)], ['tipo']);
		$deis = $this->repository();

		$deis->check();

		self::assertSame([], $this->stored);
		self::assertSame(
			'Base de Establecimientos: La Base de Establecimientos no trae TipoEstablecimientoGlosa: no tiene la forma que esta aplicación conoce.',
			$deis->status()['errors'],
		);
		self::assertArrayNotHasKey(DeisRepository::KEY_REGISTER, $this->config, 'looked at again tomorrow, not settled');
	}

	public function testNoRegisterAtAllIsSaid(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$this->registers = [];
		$deis = $this->repository();

		$deis->check();

		self::assertSame('Base de Establecimientos: El DEIS no publica Base de Establecimientos 2025.xlsx.', $deis->status()['errors']);
	}

	/** A file loaded once and gone means the address moved (epidemiologia ADR-0014): said, and the register kept. */
	public function testThisYearsRegisterGoneAfterLoadingIsSaid(): void {
		$this->config[YearWindow::KEY] = 2026;
		$this->publish(2026);
		$this->publishRegister(2026);
		$deis = $this->repository();
		$deis->check();
		self::assertSame(2026, $this->stored[0]['year']);

		unset($this->registers[2026]);
		$this->now += self::DAY;
		$deis->check();

		self::assertCount(1, $this->stored, 'last year\'s never replaces this year\'s');
		self::assertSame('Base de Establecimientos: El DEIS no publica Base de Establecimientos 2026.xlsx.', $deis->status()['errors']);
	}
}
