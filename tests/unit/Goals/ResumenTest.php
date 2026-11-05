<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Goals;

use OCA\Estadistica\Goals\Resumen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Resumen's label rule (docs/adr/0009): a past year compares only when
 * its diccionario writes the ruta's labels as the current year's does, once
 * case, accents and plurals are folded. The figures are GoalsTest's.
 */
final class ResumenTest extends TestCase {
	/** @return array<string, array{0: string, 1: string, 2: bool}> */
	public static function labels(): array {
		return [
			'the same' => ['A03 › SECCIÓN A › Evaluación › Niños de 12 a 17 meses', 'A03 › SECCIÓN A › Evaluación › Niños de 12 a 17 meses', true],
			'capitals and accents' => ['Evaluación inicial', 'EVALUACION INICIAL', true],
			'plurals, either way' => ['Controles de salud', 'Control de saludes', true],
			'another word' => ['Evaluación inicial con rezago', 'Evaluación inicial con riesgo', false],
			'a word more' => ['Evaluación inicial', 'Evaluación inicial presencial', false],
			'another age' => ['Niños de 12 a 17 meses', 'Niños de 12 a 18 meses', false],
			'another hoja' => ['A03 › Evaluación', 'A04 › Evaluación', false],
		];
	}

	#[DataProvider('labels')]
	public function testALabelIsFoldedOnlyForCaseAccentsAndPlurals(string $a, string $b, bool $same): void {
		self::assertSame($same, Resumen::sameLabel($a, $b));
		self::assertSame($same, Resumen::sameLabel($b, $a));
	}
}
