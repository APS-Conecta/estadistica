<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use Generator;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\RemReader;
use PHPUnit\Framework\TestCase;

/**
 * The era normalization, fed lines the way MemberLines yields them. Codes are
 * fabricated (900001…, and SS-LLL 99-001 → 199001), all outside the register.
 */
class RemReaderTest extends TestCase {
	/** @return Generator<int, string, mixed, bool> */
	private static function lines(bool $complete, string ...$lines): Generator {
		foreach ($lines as $line) {
			yield rtrim($line, "\r\n");
		}

		return $complete;
	}

	/** @return array{0: list<list<int|string|null>>, 1: array<string, mixed>} the rows and the summary */
	private static function read(string ...$lines): array {
		$rows = RemReader::rows(self::lines(true, ZipFixture::header(), ...$lines), 2024);
		$all = iterator_to_array($rows, false);

		return [$all, $rows->getReturn()];
	}

	public function testARowIsItsMonthEstablishmentCodigoAndFiftyFigures(): void {
		[$rows] = self::read(ZipFixture::row(2024, 3, '900001', '02010420', [1 => '5', 3 => '0']));

		self::assertCount(53, $rows[0]);
		self::assertSame([3, 900001, '02010420', '5', null, '0'], array_slice($rows[0], 0, 6));
		self::assertSame(array_fill(0, 47, null), array_slice($rows[0], 6), 'an empty cell is null, never 0');
	}

	public function testTheBomAndA2009LowercaseHeaderAreTheSameHeader(): void {
		$header = "\u{FEFF}" . str_replace(['Col42', 'Col50'], ['col42', 'col50'], ZipFixture::header());
		$rows = RemReader::rows(self::lines(true, $header, ZipFixture::row(2024, 1, '900001', 'A1')), 2024);

		self::assertCount(1, iterator_to_array($rows, false));
	}

	public function testThePre2014SsLllCodeIsTheSixDigitCode(): void {
		[$rows] = self::read(ZipFixture::row(2024, 1, '99-001', 'A1'));

		self::assertSame(199001, $rows[0][1]);
	}

	public function testCommaDecimalsAndExponentsReadAsNumbers(): void {
		[$rows] = self::read(ZipFixture::row(2024, 1, '900001', 'A1', [1 => '1,5', 2 => '5,0000000000000003E-2', 3 => '111111111111', 4 => '-3']));

		self::assertSame(['1.5', '5.0000000000000003E-2', '111111111111', '-3'], array_slice($rows[0], 3, 4));
	}

	public function testARowThatDoesNotFitIsSkippedAndCountedNeverGuessedAt(): void {
		$fiftySix = implode(';', array_slice(explode(';', rtrim(ZipFixture::row(2024, 1, '900001', 'A1'), "\r\n")), 0, 56));
		[$rows, $summary] = self::read(
			ZipFixture::row(2024, 1, '900001', 'A1'),
			$fiftySix,
			ZipFixture::row(2024, 13, '900001', 'A1'),
			ZipFixture::row(2024, 1, 'CESFAM', 'A1'),
			ZipFixture::row(2024, 1, '900001', 'A1', [1 => 'x1']),
			ZipFixture::row(2024, 1, '900001', 'A 1'),
		);

		self::assertCount(1, $rows);
		self::assertSame(5, $summary['malformed']);
	}

	public function testAMemberItsPublisherCutIsReportedInTheSummary(): void {
		$rows = RemReader::rows(self::lines(false, ZipFixture::header(), ZipFixture::row(2024, 1, '900001', 'A1')), 2024);
		iterator_to_array($rows, false);

		self::assertTrue($rows->getReturn()['cut']);
	}

	public function testAHeaderThatIsNotRemRefusesTheMember(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('encabezado de una serie REM');

		iterator_to_array(RemReader::rows(self::lines(true, ZipFixture::GARBAGE), 2024), false);
	}

	public function testAFirstRowOfAnotherYearRefusesTheMember(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('trae cifras de 2023, no de 2024');

		self::read(ZipFixture::row(2023, 1, '900001', 'A1'));
	}

	public function testLaterRowsOfAnotherYearAreSkippedAndCounted(): void {
		[$rows, $summary] = self::read(ZipFixture::row(2024, 1, '900001', 'A1'), ZipFixture::row(2023, 1, '900002', 'A1'));

		self::assertCount(1, $rows);
		self::assertSame(1, $summary['otherYear']);
	}

	public function testNoRowsIsARefusalNotAnEmptyYear(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('no trae filas de datos');

		self::read();
	}

	public function testTheSummaryProfilesEstablishmentsPerServicio(): void {
		[$rows, $summary] = self::read(
			ZipFixture::row(2024, 1, '900001', 'A1', servicio: '91'),
			ZipFixture::row(2024, 2, '900001', 'A1', servicio: '91'),
			ZipFixture::row(2024, 1, '900002', 'A1', servicio: '91'),
			ZipFixture::row(2024, 1, '900003', 'A1', servicio: '92'),
		);

		self::assertCount(4, $rows);
		self::assertSame(
			['rows' => 4, 'lastMonth' => 2, 'cut' => false, 'malformed' => 0, 'otherYear' => 0, 'profile' => ['91' => 2, '92' => 1]],
			$summary,
		);
	}

	public function testTheYearOfAMemberIsItsFirstRows(): void {
		self::assertSame(2024, RemReader::year(self::lines(true, ZipFixture::header(), ZipFixture::row(2024, 1, '900001', 'A1'))));
	}
}
