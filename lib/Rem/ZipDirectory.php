<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;

/**
 * The central directory of a DEIS REM zip, read from the zip's tail.
 *
 * Sizes and CRCs come from here and never from the local headers, which
 * 2024's zip leaves at zero because it writes data descriptors (research).
 * Only what DEIS publishes is supported: deflate, no zip64, no encryption, one
 * disk. Anything else is refused with a sentence, not guessed at.
 *
 * @psalm-type Member = array{name: string, flags: int, method: int, crc: int, compressedSize: int, size: int, offset: int}
 */
final class ZipDirectory {
	/**
	 * Every Serie A and P member name DEIS has used since 2009, under its four
	 * directory prefixes and seven file-name patterns (research: SerieA2009.csv,
	 * SerieA.txt, SerieA_txt.txt, SerieA_2019.txt, Datos/SerieA2024.csv…).
	 * BS, BM and D never match: the letter after «serie» must be followed by
	 * the year, «_txt» or the extension.
	 */
	public const SERIES = '~(?:^|/)serie([ap])(?:_?\d{4}|_txt)?\.(?:csv|txt)$~i';

	private const NOT_A_ZIP = 'El archivo no es un ZIP (no se encontró su índice).';

	private const DAMAGED = 'El índice del ZIP está dañado.';

	/** The end-of-directory record plus the longest comment a zip may carry. */
	private const TAIL = 22 + 0xFFFF;

	/** @param list<Member> $members */
	private function __construct(
		private array $members,
	) {
	}

	/** @throws RefusedException */
	public static function read(RangeReader $range): self {
		$size = $range->size();
		$tailLength = min($size, self::TAIL);
		$tail = $range->read($size - $tailLength, $tailLength);

		$at = strrpos($tail, "PK\x05\x06");
		if ($at === false || strlen($tail) - $at < 22) {
			throw new RefusedException(self::NOT_A_ZIP);
		}

		$end = unpack('vdisk/vdirectoryDisk/vdiskEntries/ventries/Vlength/Voffset', substr($tail, $at + 4, 16));
		if ($end['entries'] === 0xFFFF || $end['length'] === 0xFFFFFFFF || $end['offset'] === 0xFFFFFFFF) {
			throw new RefusedException('El ZIP usa el formato ZIP64, que esta aplicación no lee. Los ZIP del DEIS no lo usan: descargue de nuevo el original.');
		}
		if ($end['disk'] !== 0 || $end['directoryDisk'] !== 0 || $end['offset'] + $end['length'] > $size - $tailLength + $at) {
			throw new RefusedException(self::DAMAGED);
		}

		$directory = $range->read($end['offset'], $end['length']);
		$members = [];
		$at = 0;
		for ($i = 0; $i < $end['entries']; $i++) {
			if (strlen($directory) < $at + 46 || substr($directory, $at, 4) !== "PK\x01\x02") {
				throw new RefusedException(self::DAMAGED);
			}
			$entry = unpack(
				'vflags/vmethod/x4/Vcrc/VcompressedSize/Vsize/vnameLength/vextraLength/vcommentLength/x8/Voffset',
				substr($directory, $at + 8, 38),
			);
			$members[] = [
				'name' => substr($directory, $at + 46, $entry['nameLength']),
				'flags' => $entry['flags'],
				'method' => $entry['method'],
				'crc' => $entry['crc'],
				'compressedSize' => $entry['compressedSize'],
				'size' => $entry['size'],
				'offset' => $entry['offset'],
			];
			$at += 46 + $entry['nameLength'] + $entry['extraLength'] + $entry['commentLength'];
		}

		return new self($members);
	}

	/** @return list<Member> */
	public function members(): array {
		return $this->members;
	}

	/** @return list<string> the series this zip carries: 'A', 'P' or both, in that order */
	public function series(): array {
		$found = [];
		foreach ($this->members as $member) {
			if (preg_match(self::SERIES, $member['name'], $match) === 1) {
				$found[strtoupper($match[1])] = true;
			}
		}
		ksort($found);

		return array_keys($found);
	}

	/**
	 * The one member of Serie $serie ('A' or 'P').
	 *
	 * @return Member
	 * @throws RefusedException
	 */
	public function member(string $serie): array {
		$matches = array_values(array_filter(
			$this->members,
			static fn (array $member): bool => preg_match(self::SERIES, $member['name'], $match) === 1
				&& strtoupper($match[1]) === $serie,
		));

		if ($matches === []) {
			throw new RefusedException(sprintf('El ZIP no trae la Serie %s del REM.', $serie));
		}
		if (count($matches) > 1) {
			throw new RefusedException(sprintf(
				'El ZIP trae más de un archivo de la Serie %s (%s): no se sabe cuál cargar.',
				$serie,
				implode(', ', array_map(static fn (array $member): string => self::printable($member['name']), $matches)),
			));
		}

		$member = $matches[0];
		if (($member['flags'] & 0x1) !== 0) {
			throw new RefusedException(sprintf('El archivo de la Serie %s está cifrado.', $serie));
		}
		if ($member['method'] !== 8) {
			throw new RefusedException(sprintf(
				'El archivo de la Serie %s está comprimido de una forma que esta aplicación no lee. Los ZIP del DEIS usan «deflate»: descargue de nuevo el original.',
				$serie,
			));
		}

		return $member;
	}

	/**
	 * Zip names are CP437 bytes and not flagged UTF-8 (research: «CαDIGOS»),
	 * and a refusal is JSON-encoded on its way to the admin section.
	 */
	private static function printable(string $name): string {
		return (string)preg_replace('/[^\x20-\x7E]/', '?', $name);
	}
}
