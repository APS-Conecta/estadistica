<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;

/**
 * A zip on this server's disk: an admin's drop, copied out of the app's
 * storage by DroppedFiles, or a fixture a test generated.
 *
 * It keeps the list of what it was asked for. That list is the a3 evidence
 * that the importer reads the central directory and then only Serie A/P
 * members, never BS, BM or D (ZipDirectoryTest).
 */
final class LocalRange implements RangeReader {
	private const UNREADABLE = 'No se pudo leer el archivo recibido.';

	/** @var list<array{int, int}> every (offset, length) asked for, in order */
	private array $reads = [];

	public function __construct(
		private string $path,
	) {
	}

	public function size(): int {
		clearstatcache(true, $this->path);
		$size = is_file($this->path) ? filesize($this->path) : false;
		if ($size === false) {
			throw new RefusedException(self::UNREADABLE);
		}

		return $size;
	}

	public function read(int $offset, int $length): string {
		$stream = $this->stream($offset, $length);
		try {
			$bytes = $length === 0 ? '' : stream_get_contents($stream, $length);
		} finally {
			fclose($stream);
		}

		if ($bytes === false || strlen($bytes) !== $length) {
			throw new RefusedException('El archivo termina antes de lo que dice su índice: está incompleto o dañado.');
		}

		return $bytes;
	}

	public function stream(int $offset, int $length) {
		$this->reads[] = [$offset, $length];

		$stream = is_file($this->path) ? fopen($this->path, 'rb') : false;
		if ($stream === false) {
			throw new RefusedException(self::UNREADABLE);
		}
		if (fseek($stream, $offset) !== 0) {
			fclose($stream);
			throw new RefusedException(self::UNREADABLE);
		}

		return $stream;
	}

	/** @return list<array{int, int}> */
	public function reads(): array {
		return $this->reads;
	}
}
