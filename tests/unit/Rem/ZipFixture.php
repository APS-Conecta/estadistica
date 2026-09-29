<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

/**
 * Synthetic DEIS REM zips, written to sys_get_temp_dir() at test time. No
 * extract, member or dictionary ships in the repo (acceptance a2), and every
 * establishment code is fabricated: 900001 and up, outside the register,
 * whose six-digit codes all begin with 1 or 2. No real code is written here,
 * not even in a comment: the a2 gate greps for every one of them.
 *
 * The zip writer is here rather than ZipArchive because the fixtures need what
 * ZipArchive will not write:
 * - 2024's data descriptors (local-header CRC and sizes left at zero, research);
 * - members streamed from a generator, so a ≥ 1M-row member never exists in
 *   memory (a17).
 */
final class ZipFixture {
	/** A web server's block page where a zip should be: the garbage 200 (epidemiologia #74). */
	public const GARBAGE = "<html><head><title>Access denied</title></head><body>Request blocked.</body></html>\r\n";

	/** @var list<string> */
	private static array $made = [];

	/**
	 * @param array<string, iterable<string>|string> $members name → content, streamed chunk by chunk
	 */
	public static function zip(array $members, bool $descriptors = false): string {
		$path = self::path();
		$out = fopen($path, 'wb');
		$flags = $descriptors ? 0x08 : 0;
		$directory = '';

		foreach ($members as $name => $content) {
			$offset = ftell($out);
			fwrite($out, pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, 8, 0, 0, 0, 0, 0, strlen($name), 0) . $name);

			$deflate = deflate_init(ZLIB_ENCODING_RAW);
			$hash = hash_init('crc32b');
			$size = 0;
			$compressed = 0;
			foreach (is_string($content) ? [$content] : $content as $chunk) {
				hash_update($hash, $chunk);
				$size += strlen($chunk);
				$compressed += fwrite($out, deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
			}
			$compressed += fwrite($out, deflate_add($deflate, '', ZLIB_FINISH));
			$crc = (int)hexdec(hash_final($hash));

			if ($descriptors) {
				fwrite($out, pack('VVVV', 0x08074b50, $crc, $compressed, $size));
			} else {
				$end = ftell($out);
				fseek($out, $offset + 14);
				fwrite($out, pack('VVV', $crc, $compressed, $size));
				fseek($out, $end);
			}

			$directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, 8, 0, 0, $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
		}

		$at = ftell($out);
		fwrite($out, $directory);
		fwrite($out, pack('VvvvvVVv', 0x06054b50, 0, 0, count($members), count($members), strlen($directory), $at, 0));
		fclose($out);

		return $path;
	}

	/** Any bytes at all, where a zip was expected. */
	public static function file(string $content): string {
		$path = self::path();
		file_put_contents($path, $content);

		return $path;
	}

	/** Flips one bit of the first member's CRC in the central directory: a download damaged in transit. */
	public static function damageDirectoryCrc(string $path): void {
		$zip = fopen($path, 'r+b');
		fseek($zip, -22, SEEK_END);
		$directory = unpack('Voffset', substr((string)fread($zip, 22), 16, 4))['offset'];
		fseek($zip, $directory + 16);
		$crc = unpack('Vcrc', (string)fread($zip, 4))['crc'];
		fseek($zip, $directory + 16);
		fwrite($zip, pack('V', $crc ^ 1));
		fclose($zip);
	}

	/** The header every Serie member carries, as DEIS writes it (CRLF). */
	public static function header(): string {
		$columns = array_map(static fn (int $i): string => sprintf('Col%02d', $i), range(1, 50));

		return 'Mes;IdServicio;Ano;IdEstablecimiento;CodigoPrestacion;IdRegion;IdComuna;' . implode(';', $columns) . "\r\n";
	}

	/**
	 * One data row. $cols maps a column number (1–50) to its cell as DEIS
	 * writes it; every other cell is empty.
	 *
	 * @param array<int, string> $cols
	 */
	public static function row(int $anio, int $mes, string $estab, string $codigo, array $cols = [], string $servicio = '91'): string {
		$cells = array_fill(1, 50, '');
		foreach ($cols as $i => $value) {
			$cells[$i] = $value;
		}

		return implode(';', [(string)$mes, $servicio, (string)$anio, $estab, $codigo, '99', '99001', ...$cells]) . "\r\n";
	}

	/** @return list<string> fabricated establishment codes, 900001 and up */
	public static function establishments(int $count, int $first = 900001): array {
		return array_map('strval', range($first, $first + $count - 1));
	}

	/**
	 * A small national member. Every establishment reports every month for every
	 * código, with col01 = the month and col02 = 1, so sums are predictable.
	 *
	 * @param list<string> $establishments
	 * @param list<string> $codigos
	 * @param array<string, string> $servicioOf estab → servicio (default 91)
	 */
	public static function member(int $anio, array $establishments, array $codigos = ['02010420', '03500366'], int $months = 2, array $servicioOf = []): string {
		$member = self::header();
		foreach ($establishments as $estab) {
			for ($mes = 1; $mes <= $months; $mes++) {
				foreach ($codigos as $codigo) {
					$member .= self::row($anio, $mes, $estab, $codigo, [1 => (string)$mes, 2 => '1'], $servicioOf[$estab] ?? '91');
				}
			}
		}

		return $member;
	}

	/** Removes every file this class wrote. Called from tearDown(). */
	public static function cleanup(): void {
		foreach (self::$made as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
		self::$made = [];
	}

	private static function path(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'estadistica-');
		self::$made[] = $path;

		return $path;
	}
}
