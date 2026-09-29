<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\LocalRange;
use OCA\Estadistica\Rem\MemberLines;
use OCA\Estadistica\Rem\RangeReader;
use OCA\Estadistica\Rem\ZipDirectory;
use PHPUnit\Framework\TestCase;

/** The zip layer: ZipDirectory, MemberLines and LocalRange, over generated zips. */
class ZipDirectoryTest extends TestCase {
	protected function tearDown(): void {
		ZipFixture::cleanup();
	}

	/** @return list<string> */
	private static function linesOf(RangeReader $range, array $member, ?bool &$complete = null): array {
		$lines = MemberLines::of($range, $member);
		$all = iterator_to_array($lines, false);
		$complete = $lines->getReturn();

		return $all;
	}

	/** @return array<string, array{string, string}> */
	public static function deisNames(): array {
		return [
			'2009, in a directory' => ['SERIE_REM_2009/SerieA2009.csv', 'SERIE_REM_2009/SerieP2009.csv'],
			'no year' => ['SerieA.txt', 'SerieP.txt'],
			'_txt, in 2021/' => ['2021/SerieA_txt.txt', '2021/SerieP_txt.txt'],
			'_year' => ['SerieA_2019.txt', 'SerieP_2019.txt'],
			'2024, in Datos/' => ['Datos/SerieA2024.csv', 'Datos/SerieP2024.csv'],
		];
	}

	/** @dataProvider deisNames */
	public function testEveryNameDeisHasUsedIsFoundAndNoOtherSeriesIs(string $a, string $p): void {
		$range = new LocalRange(ZipFixture::zip([
			'SerieBS2024.csv' => 'bs',
			$a => 'a',
			'SerieBM2024.csv' => 'bm',
			'SerieD2024.csv' => 'd',
			$p => 'p',
			'DICCIONARIOS/SA_24_V1.2.xlsm' => 'x',
		]));
		$zip = ZipDirectory::read($range);

		self::assertSame(['A', 'P'], $zip->series());
		self::assertSame($a, $zip->member('A')['name']);
		self::assertSame($p, $zip->member('P')['name']);
	}

	/** 2024's shape: the local header says nothing; the directory says everything. */
	public function testMembersAreReadFromTheCentralDirectoryNotTheLocalHeaders(): void {
		$range = new LocalRange(ZipFixture::zip(['SerieA2024.csv' => "uno\r\ndos\r\n"], descriptors: true));

		self::assertSame(['uno', 'dos'], self::linesOf($range, ZipDirectory::read($range)->member('A'), $complete));
		self::assertTrue($complete);
	}

	/**
	 * Acceptance a3: after the central directory, only the Serie A and P
	 * members are read — never BS, BM, D or a dictionary.
	 */
	public function testAfterTheDirectoryOnlyTheSerieMembersAreRead(): void {
		$path = ZipFixture::zip([
			'SerieBS2024.csv' => str_repeat("bs\r\n", 1000),
			'SerieA2024.csv' => str_repeat("a\r\n", 1000),
			'SerieD2024.csv' => str_repeat("d\r\n", 1000),
			'SerieP2024.csv' => str_repeat("p\r\n", 1000),
			'DICCIONARIOS/SA_24_V1.2.xlsm' => str_repeat('x', 1000),
		]);
		$range = new LocalRange($path);
		$zip = ZipDirectory::read($range);
		foreach (['A', 'P'] as $serie) {
			self::linesOf($range, $zip->member($serie));
		}

		$reads = $range->reads();
		$size = filesize($path);
		self::assertSame($size, $reads[0][0] + $reads[0][1], 'the first read is the tail, where the directory is found');

		$spans = [];
		foreach (['A', 'P'] as $serie) {
			$member = $zip->member($serie);
			$spans[] = [$member['offset'], $member['offset'] + 30 + strlen($member['name']) + $member['compressedSize']];
		}
		foreach (array_slice($reads, 2) as [$offset, $length]) {
			$inside = false;
			foreach ($spans as [$from, $to]) {
				$inside = $inside || ($offset >= $from && $offset + $length <= $to);
			}
			self::assertTrue($inside, "a read at $offset (+$length) falls outside the Serie A and P members");
		}
		self::assertCount(2 + 2 * 2, $reads, 'the tail, the directory, then a header and a data stream per member');
	}

	/** The 2025 Serie A shape: cut mid-row by its publisher, CRC intact. */
	public function testAMemberItsPublisherCutReturnsFalseAndNeverYieldsTheFragment(): void {
		$range = new LocalRange(ZipFixture::zip(['SerieA.txt' => "cabecera\r\nfila uno\r\nfila do"]));

		self::assertSame(['cabecera', 'fila uno'], self::linesOf($range, ZipDirectory::read($range)->member('A'), $complete));
		self::assertFalse($complete);
	}

	public function testATransferCutShortIsRefused(): void {
		$range = new LocalRange(ZipFixture::zip(['SerieA.txt' => str_repeat("fila\r\n", 50000)]));
		$halfway = new class($range) implements RangeReader {
			public function __construct(private RangeReader $inner) {
			}

			public function size(): int {
				return $this->inner->size();
			}

			public function read(int $offset, int $length): string {
				return $this->inner->read($offset, $length);
			}

			public function stream(int $offset, int $length) {
				$cut = fopen('php://memory', 'w+b');
				fwrite($cut, $this->inner->read($offset, intdiv($length, 2)));
				rewind($cut);

				return $cut;
			}
		};

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('llegó cortado');

		self::linesOf($halfway, ZipDirectory::read($halfway)->member('A'));
	}

	public function testBytesThatDoNotMatchTheDirectoryAreRefused(): void {
		$path = ZipFixture::zip(['SerieA.txt' => "fila\r\n"]);
		ZipFixture::damageDirectoryCrc($path);
		$range = new LocalRange($path);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no coincide con el índice');

		self::linesOf($range, ZipDirectory::read($range)->member('A'));
	}

	public function testSomethingThatIsNotAZipIsRefusedInSpanish(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no es un ZIP');

		ZipDirectory::read(new LocalRange(ZipFixture::file(ZipFixture::GARBAGE)));
	}

	public function testAMissingSerieIsRefusedInSpanish(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no trae la Serie P');

		ZipDirectory::read(new LocalRange(ZipFixture::zip(['SerieA.txt' => 'a'])))->member('P');
	}

	public function testTwoMembersOfOneSerieAreRefusedRatherThanGuessedBetween(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('más de un archivo de la Serie A');

		ZipDirectory::read(new LocalRange(ZipFixture::zip(['SerieA.txt' => 'a', 'Datos/SerieA2024.csv' => 'a'])))->member('A');
	}
}
