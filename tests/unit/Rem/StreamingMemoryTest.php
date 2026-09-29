<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use Generator;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\MemberLines;
use OCA\Estadistica\Rem\RemReader;
use OCA\Estadistica\Rem\ZipDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance a17: a national-sized member streams. The whole read path (zip
 * directory, raw inflate, lines, era normalization) runs over a generated
 * member of 1M rows, larger inflated than the bound it must stay under, so a
 * pass means it streamed rather than held the member.
 */
class StreamingMemoryTest extends TestCase {
	private const ROWS = 1_000_000;

	private const BOUND = 32 * 1024 * 1024;

	protected function tearDown(): void {
		ZipFixture::cleanup();
	}

	/**
	 * Generated in ≈ 64 KiB chunks, so the member never exists whole here
	 * either. Figures are pseudo-random, so it compresses like real data rather
	 * than like one row repeated.
	 *
	 * @return Generator<int, string>
	 */
	private static function member(): Generator {
		yield ZipFixture::header();
		mt_srand(20260928);
		$chunk = '';
		for ($i = 0; $i < self::ROWS; $i++) {
			$chunk .= ZipFixture::row(
				2024,
				$i % 12 + 1,
				(string)(900001 + $i % 2500),
				sprintf('%08d', intdiv($i, 30000)),
				[1 => (string)mt_rand(0, 999), 7 => (string)mt_rand(0, 99), 23 => mt_rand(0, 9) . ',' . mt_rand(0, 99)],
				(string)(91 + $i % 7),
			);
			if (strlen($chunk) > 65536) {
				yield $chunk;
				$chunk = '';
			}
		}
		yield $chunk;
	}

	public function testANationalSizedMemberIsReadInBoundedMemory(): void {
		$range = new LocalRange(ZipFixture::zip(['SerieA2024.csv' => self::member()]));
		$member = ZipDirectory::read($range)->member('A');
		self::assertGreaterThan(2 * self::BOUND, $member['size'], 'the member must inflate past the bound, or the bound proves nothing');

		gc_collect_cycles();
		memory_reset_peak_usage();
		$before = memory_get_usage();

		$rows = RemReader::rows(MemberLines::of($range, $member), 2024);
		$count = 0;
		foreach ($rows as $row) {
			$count++;
		}
		$peak = memory_get_peak_usage() - $before;

		self::assertSame(self::ROWS, $count);
		self::assertSame(self::ROWS, $rows->getReturn()['rows']);
		self::assertLessThan(self::BOUND, $peak, sprintf(
			'reading peaked %.1f MiB above its start: it must stream, not hold the member (a17)',
			$peak / 1048576,
		));
	}
}
