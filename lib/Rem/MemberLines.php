<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use Generator;
use OCA\Estadistica\Exception\RefusedException;

/**
 * One zip member's lines, inflated as they arrive and never held whole
 * (acceptance a17). Serie A inflates to 675 MB in the largest year; this
 * holds one 64 KiB compressed chunk and the lines it opens up.
 *
 * The generator yields complete lines without their line ending, and RETURNS
 * whether the member ended with one. False is the 2025 Serie A shape: a member
 * its publisher cut mid-row (research). The fragment is not a row and is never
 * yielded.
 *
 * The member is checked against the central directory, not the local header:
 * at the end, the inflated size and the CRC-32 must match it. A download cut
 * or damaged in transit is therefore refused, not read as data. A publisher's
 * own cut passes that check, because the CRC is of the bytes they published,
 * and is reported by the return value instead.
 *
 * @psalm-import-type Member from ZipDirectory
 */
final class MemberLines {
	/** Compressed bytes per read. This data inflates ≈ 10–20×. */
	private const CHUNK = 65536;

	/**
	 * @param Member $member
	 * @return Generator<int, string, mixed, bool>
	 * @throws RefusedException
	 */
	public static function of(RangeReader $range, array $member): Generator {
		$pending = '';
		foreach (self::chunks($range, $member) as $data) {
			$lines = explode("\n", $pending . $data);
			$pending = array_pop($lines);
			foreach ($lines as $line) {
				yield rtrim($line, "\r");
			}
		}

		return $pending === '';
	}

	/**
	 * The member's inflated bytes, a chunk at a time, for a member that is not
	 * lines: a year's DICCIONARIO is itself a workbook (Catalog). The same
	 * checks hold: it is refused if it is cut short or does not match the
	 * directory.
	 *
	 * @param Member $member
	 * @return Generator<int, string, mixed, void>
	 * @throws RefusedException
	 */
	public static function chunks(RangeReader $range, array $member): Generator {
		// Only the local header's name and extra lengths are read: they say where
		// the data starts, which the central directory does not.
		$header = $range->read($member['offset'], 30);
		if (!str_starts_with($header, "PK\x03\x04")) {
			throw new RefusedException('El ZIP está dañado: su índice apunta a un lugar donde no empieza ningún archivo.');
		}
		['name' => $nameLength, 'extra' => $extraLength] = unpack('vname/vextra', substr($header, 26, 4));

		$stream = $range->stream($member['offset'] + 30 + $nameLength + $extraLength, $member['compressedSize']);
		try {
			$inflate = inflate_init(ZLIB_ENCODING_RAW);
			$crc = hash_init('crc32b');
			$size = 0;
			$left = $member['compressedSize'];

			while ($left > 0) {
				$chunk = fread($stream, min(self::CHUNK, $left));
				if ($chunk === false || $chunk === '') {
					throw new RefusedException('El archivo llegó cortado: terminó antes de lo que anuncia el índice del ZIP.');
				}
				$left -= strlen($chunk);

				// A data error is reported by the false return; the warning PHP
				// also raises would only repeat it in the log.
				$data = @inflate_add($inflate, $chunk, $left > 0 ? ZLIB_SYNC_FLUSH : ZLIB_FINISH);
				if ($data === false) {
					throw new RefusedException('El archivo llegó dañado: no se pudo descomprimir.');
				}
				hash_update($crc, $data);
				$size += strlen($data);

				yield $data;
			}

			if (inflate_get_status($inflate) !== ZLIB_STREAM_END
				|| $size !== $member['size']
				|| (int)hexdec(hash_final($crc)) !== $member['crc']) {
				throw new RefusedException('El archivo llegó dañado: su contenido no coincide con el índice del ZIP (tamaño o CRC distintos).');
			}
		} finally {
			fclose($stream);
		}
	}
}
