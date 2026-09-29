<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A zip on a web server that answers Range requests: DEIS's (DeisRepository).
 *
 * Every byte comes from a Range request for exactly those bytes, and every
 * body is streamed, never held whole (acceptance a3, a17). The central
 * directory takes two ≈ 66 KB requests. A member is one request, and
 * MemberLines inflates its body as it arrives. Measured through Nextcloud 34's
 * own client on the 2026 zip (design, 2026-09-28): Serie A, 72.7 MB compressed
 * and 509 MB inflated, took 5.6 s with a 27.5 MiB peak.
 *
 * The HEAD that learns the size also learns the ETag. Every read after it
 * carries that ETag in If-Range, so if DEIS replaces the zip mid-load it
 * answers 200 with the new file instead of 206. That read is refused before
 * any of its bytes are used: offsets taken from one version are never applied
 * to another.
 *
 * Every failure is a RefusedException. Its message is the sentence the admin
 * section shows, and its code is the HTTP status, or 0 when nothing answered.
 * A caller reads a 404 differently: it is a year DEIS has not published.
 */
final class HttpRange implements RangeReader {
	public const NOT_FOUND = 404;

	/**
	 * Seconds. On a streamed body this bounds each read, not the transfer
	 * (Guzzle's StreamHandler sets it as http.timeout), so a slow member still
	 * finishes and a stalled one does not hang.
	 */
	private const TIMEOUT = 15;

	private ?int $size = null;

	private string $etag = '';

	public function __construct(
		private IClient $client,
		private string $url,
		private string $userAgent,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * One HEAD. It answers whether the zip differs from the version $etag names;
	 * a 304 says it does not. Otherwise the size and ETag are kept for every
	 * read after.
	 *
	 * @throws RefusedException
	 */
	public function changedSince(string $etag): bool {
		$headers = $this->headers();
		if ($etag !== '') {
			$headers['If-None-Match'] = $etag;
		}
		$response = $this->send('head', $headers);

		if ($response->getStatusCode() === 304) {
			return false;
		}

		$length = $response->getHeader('Content-Length');
		if ($response->getStatusCode() !== 200 || !ctype_digit($length)) {
			throw new RefusedException(sprintf(
				'El DEIS no dijo el tamaño de %s (respondió %d), y sin él no se puede leer por partes.',
				$this->name(),
				$response->getStatusCode(),
			));
		}
		$this->size = (int)$length;
		$this->etag = $response->getHeader('ETag');

		return true;
	}

	/** The ETag the last HEAD saw. Empty before one, or when DEIS sends none. */
	public function etag(): string {
		return $this->etag;
	}

	public function size(): int {
		if ($this->size === null) {
			$this->changedSince('');
		}

		return (int)$this->size;
	}

	public function read(int $offset, int $length): string {
		if ($length === 0) {
			return '';
		}

		$stream = $this->stream($offset, $length);
		try {
			$bytes = stream_get_contents($stream, $length);
		} finally {
			fclose($stream);
		}

		if ($bytes === false || strlen($bytes) !== $length) {
			throw new RefusedException(sprintf('La descarga de %s llegó cortada.', $this->name()));
		}

		return $bytes;
	}

	public function stream(int $offset, int $length) {
		if ($length === 0) {
			return fopen('php://memory', 'rb');
		}

		$last = $offset + $length - 1;
		$expected = sprintf('bytes %d-%d/%d', $offset, $last, $this->size());

		$headers = $this->headers();
		$headers['Range'] = sprintf('bytes=%d-%d', $offset, $last);
		if ($this->etag !== '') {
			$headers['If-Range'] = $this->etag;
		}
		$response = $this->send('get', $headers);
		$body = $response->getBody();

		if ($response->getStatusCode() === 206 && $response->getHeader('Content-Range') === $expected && is_resource($body)) {
			return $body;
		}

		// Closed unread: a 200 here carries the whole zip.
		if (is_resource($body)) {
			fclose($body);
		}
		throw new RefusedException($response->getStatusCode() === 200
			? sprintf('El DEIS cambió %s mientras se leía, o lo entregó entero en vez de la parte pedida. Se volverá a intentar.', $this->name())
			: sprintf('El DEIS entregó una parte de %s distinta de la pedida.', $this->name()));
	}

	/**
	 * @param array<string, string> $headers
	 * @throws RefusedException
	 */
	private function send(string $method, array $headers): IResponse {
		try {
			// Every GET is streamed, so no body is ever held whole, not even a
			// 200 that carries the whole zip where one range was asked for.
			return $method === 'head'
				? $this->client->head($this->url, ['timeout' => self::TIMEOUT, 'headers' => $headers])
				: $this->client->get($this->url, ['timeout' => self::TIMEOUT, 'headers' => $headers, 'stream' => true]);
		} catch (Throwable $e) {
			$status = $this->statusOf($e);
			if ($status === self::NOT_FOUND) {
				throw new RefusedException(sprintf('El DEIS no publica %s.', $this->name()), $status, $e);
			}
			if ($status !== null) {
				throw new RefusedException(sprintf('El DEIS respondió %d al pedir %s.', $status, $this->name()), $status, $e);
			}

			// Nothing answered: DNS, TLS or a timeout. Which one is for the log.
			$this->logger->warning('Could not reach DEIS for {url}', ['url' => $this->url, 'exception' => $e]);
			throw new RefusedException(sprintf('No se pudo conectar con el DEIS para pedir %s. Se volverá a intentar.', $this->name()), 0, $e);
		}
	}

	/**
	 * The status a failure carries, or null when nothing answered.
	 * getResponseFromThrowable() rethrows when there is no response
	 * (epidemiologia's Freno::status).
	 */
	private function statusOf(Throwable $e): ?int {
		try {
			return $this->client->getResponseFromThrowable($e)->getStatusCode();
		} catch (Throwable) {
			return null;
		}
	}

	/** @return array<string, string> */
	private function headers(): array {
		return [
			'User-Agent' => $this->userAgent,
			// Offsets and sizes are the file's own, so no transfer encoding may
			// stand between them. Nextcloud's client asks for gzip unless told
			// otherwise.
			'Accept-Encoding' => 'identity',
		];
	}

	/** The file's name, for the sentences a person reads. */
	private function name(): string {
		return basename((string)parse_url($this->url, PHP_URL_PATH));
	}
}
