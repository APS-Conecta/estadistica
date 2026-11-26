<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Rem;

use OCA\Estadistica\Rem\Completeness;
use PHPUnit\Framework\TestCase;

/** When a member that read cleanly is still «incompleto». */
class CompletenessTest extends TestCase {
	/** @param array<string, int> $profile servicio → establishments reporting across a whole year */
	private static function summary(array $profile = ['91' => 10], bool $cut = false, int $malformed = 0, int $otherYear = 0): array {
		return ['rows' => 100, 'lastMonth' => 12, 'cut' => $cut, 'malformed' => $malformed, 'otherYear' => $otherYear, 'profile' => self::byDecember($profile)];
	}

	/**
	 * @param array<string, int> $profile
	 * @return array{anio: int, profile: array<string, array<int, int>>}
	 */
	private static function neighbour(int $anio, array $profile): array {
		return ['anio' => $anio, 'profile' => self::byDecember($profile)];
	}

	/**
	 * @param array<string, int> $profile
	 * @return array<string, array<int, int>>
	 */
	private static function byDecember(array $profile): array {
		return array_map(static fn (int $count): array => [12 => $count], $profile);
	}

	public function testAWholeMemberHasNothingToSay(): void {
		self::assertSame([], Completeness::of(self::summary(), self::neighbour(2023, ['91' => 10])));
	}

	public function testTheMembersOwnShortfallsAreEachASentence(): void {
		self::assertSame([
			'La última fila venía cortada (el archivo no termina en salto de línea) y se omitió.',
			'Se omitieron 1.234 filas ilegibles: con campos de más o de menos, o con valores que no son números.',
			'Se omitieron 2 filas de otro año.',
		], Completeness::of(self::summary(cut: true, malformed: 1234, otherYear: 2), null));
	}

	/** The 2025 Serie A shape: one servicio collapsed, the other intact. */
	public function testAServicioThatCollapsedAgainstItsNeighbourIsNamed(): void {
		self::assertSame(
			['Servicio de salud 91: informan 2 establecimientos, frente a 10 en 2024.'],
			Completeness::of(self::summary(['91' => 2, '92' => 10]), self::neighbour(2024, ['91' => 10, '92' => 10])),
		);
	}

	public function testOrdinaryDriftIsNotAGap(): void {
		self::assertSame([], Completeness::of(self::summary(['91' => 8, '92' => 11]), self::neighbour(2024, ['91' => 10, '92' => 10])));
	}

	public function testServiciosTooSmallForARatioAreNotJudged(): void {
		self::assertSame([], Completeness::of(self::summary(['91' => 10]), self::neighbour(2024, ['91' => 10, '93' => 4])));
	}

	/** Research: IdServicio drifts by era. New codes everywhere is a recoding, not 100% missing. */
	public function testCodesThatChangedBetweenErasAreNotAGap(): void {
		self::assertSame([], Completeness::of(
			self::summary(['10' => 10, '20' => 10, '30' => 10]),
			self::neighbour(2013, ['1' => 10, '2' => 10, '3' => 10]),
		));
	}
}
