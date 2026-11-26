<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Query;

use LogicException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryCompiler;
use OCA\Estadistica\Query\QuerySpec;
use PHPUnit\Framework\TestCase;

/**
 * Exact SQL (acceptance a7, a16): each filter, the two metrics, the local,
 * national and peer series from one spec, and a statement that carries no
 * value from the spec, only placeholders.
 *
 * The hoja and sección filters reach the compiler already resolved to their
 * códigos, so here they are the same bound list as códigos named one by one.
 * Their resolution through the dictionaries is QueryExecutorTest's
 * (integration).
 */
final class QueryCompilerTest extends TestCase {
	private const YEAR = 2026;

	/** The fabricated local establishment (fixtures run 900001 and up). */
	private const ESTAB = 900001;

	private const CODES = ['02010420', '03500366'];

	/** A row's two chosen cells, summed: NULL when neither is reported. */
	private const TOTAL = 'sum(CASE WHEN COALESCE(r.col08, r.col09) IS NOT NULL THEN COALESCE(r.col08, 0) + COALESCE(r.col09, 0) END) AS valor';

	private const WHERE = ' WHERE r.serie = ? AND r.anio BETWEEN ? AND ? AND r.anio * 100 + r.mes BETWEEN ? AND ?'
		. ' AND r.codigo = ANY(CAST(? AS text[]))';

	/** Meta I's shape: an October-to-September window, two columns, by month, each establishment summed first (#27). */
	private const INNER = 'SELECT r.anio * 100 + r.mes AS g0, r.estab, ' . self::TOTAL . ' FROM *PREFIX*estadistica_rem r' . self::WHERE;

	/** The establishments' sums, summed and counted by month. */
	private static function byMonth(string $inner, string $metric = 'sum(s.valor)'): string {
		return "SELECT s.g0, $metric AS valor, count(*) AS informantes FROM ($inner GROUP BY g0, r.estab) s"
			. ' WHERE s.valor IS NOT NULL GROUP BY g0 ORDER BY g0 LIMIT 5001';
	}

	/** @param array<string, mixed> $changes */
	private static function spec(array $changes = []): QuerySpec {
		return QuerySpec::from($changes + [
			'fuente' => 'rem-a',
			'periodo' => ['desde' => '2025-10', 'hasta' => '2026-09'],
			'filtros' => ['codigos' => self::CODES, 'columnas' => ['col08', 'col09']],
			'agrupar' => ['mes'],
		], self::YEAR);
	}

	/**
	 * @param list<string> $codigos
	 * @return list<int|string>
	 */
	private static function params(QuerySpec $spec, array $codigos = self::CODES): array {
		return QueryCompiler::compile($spec, $codigos, [])['params'];
	}

	/**
	 * The national statement, for one establishment, over its rows as the
	 * planillas overlay them (docs/adr/0010; the figures are GoalsTest's).
	 */
	public function testTheLocalSeriesIsTheNationalOneForOneEstablishmentOverItsPlanillas(): void {
		self::assertSame(
			[
				'sql' => self::byMonth(str_replace('*PREFIX*estadistica_rem r ', QueryCompiler::local() . ' r ', self::INNER) . ' AND r.estab = ?'),
				'params' => ['A', 2025, 2026, 202510, 202609, '{"02010420","03500366"}', self::ESTAB],
			],
			QueryCompiler::compile(self::spec(), self::CODES, ['estab' => self::ESTAB]),
		);
	}

	public function testTheNationalSeriesCountsEveryEstablishment(): void {
		self::assertSame(
			['sql' => self::byMonth(self::INNER), 'params' => ['A', 2025, 2026, 202510, 202609, '{"02010420","03500366"}']],
			QueryCompiler::compile(self::spec(), self::CODES, []),
		);
	}

	public function testThePeerSeriesCountsTheEstablishmentsTheRegisterMatches(): void {
		self::assertSame(
			[
				'sql' => self::byMonth(self::INNER
					. ' AND r.estab IN (SELECT p.estab FROM *PREFIX*estadistica_establishment p WHERE p.tipo = ? AND p.servicio = ?)'),
				'params' => ['A', 2025, 2026, 202510, 202609, '{"02010420","03500366"}', 'Centro de Salud Familiar (CESFAM)', 'Servicio de Salud Uno'],
			],
			QueryCompiler::compile(self::spec(), self::CODES, ['tipo' => 'Centro de Salud Familiar (CESFAM)', 'servicio' => 'Servicio de Salud Uno']),
		);
	}

	/** a7: promedio por establecimiento = SUM ÷ the establishments informantes, one summed row each. */
	public function testPromedioDividesTheSumByTheEstablishmentsThatReported(): void {
		$sql = QueryCompiler::compile(self::spec(['metrica' => 'promedio_establecimiento']), self::CODES, [])['sql'];

		self::assertSame(self::byMonth(self::INNER, 'sum(s.valor) / count(*)'), $sql);
	}

	/** a7, año: a whole year is the periodo from its January to its December. */
	public function testTheYearFilterIsItsTwelveMonths(): void {
		$spec = self::spec(['periodo' => ['desde' => '2026-01', 'hasta' => '2026-12']]);

		self::assertSame(self::byMonth(self::INNER), QueryCompiler::compile($spec, self::CODES, [])['sql']);
		self::assertSame(['A', 2026, 2026, 202601, 202612], array_slice(self::params($spec), 0, 5));
	}

	/** a7, mes: one month is a periodo that starts and ends on it. */
	public function testTheMonthFilterIsAPeriodoOfOneMonth(): void {
		self::assertSame(['A', 2026, 2026, 202603, 202603], array_slice(self::params(self::spec(['periodo' => ['desde' => '2026-03', 'hasta' => '2026-03']])), 0, 5));
	}

	/** a7, serie: the fuente is the serie. */
	public function testTheSerieFilterIsTheFuente(): void {
		self::assertSame('P', self::params(self::spec(['fuente' => 'rem-p']))[0]);
	}

	/** a7, REM, sección and prestación: whatever named them, the statement reads one bound list of códigos. */
	public function testTheHojaSeccionAndPrestacionFiltersAreOneBoundListOfCodigos(): void {
		$byHoja = QueryCompiler::compile(self::spec(['filtros' => ['hoja' => 'A03', 'columnas' => ['col08', 'col09']]]), ['02010420', '03500366', '03500400'], []);
		$bySeccion = QueryCompiler::compile(self::spec(['filtros' => ['hoja' => 'A03', 'seccion' => 'SECCIÓN A', 'columnas' => ['col08', 'col09']]]), self::CODES, []);
		$byCode = QueryCompiler::compile(self::spec(['filtros' => ['codigos' => ['03500366'], 'columnas' => ['col08', 'col09']]]), ['03500366'], []);

		foreach ([$byHoja, $bySeccion, $byCode] as $compiled) {
			self::assertSame(self::byMonth(self::INNER), $compiled['sql']);
		}
		self::assertSame('{"02010420","03500366","03500400"}', $byHoja['params'][5]);
		self::assertSame('{"02010420","03500366"}', $bySeccion['params'][5]);
		self::assertSame('{"03500366"}', $byCode['params'][5]);
	}

	/** The bound text[] literal quotes every código, so a quote, a backslash, a comma or a brace stays inside it. */
	public function testTheCodigosAreOneQuotedArrayLiteral(): void {
		self::assertSame('{}', self::params(self::spec(), [])[5], 'no código is an empty array');
		self::assertSame('{"a\\"b","c\\\\d","e,f}"}', self::params(self::spec(), ['a"b', 'c\\d', 'e,f}'])[5]);
	}

	/** Only by columna are the columns unpivoted, one value per reported cell. */
	public function testGroupingByColumnaKeepsEachColumnApart(): void {
		self::assertSame(
			'SELECT s.g0, s.g1, sum(s.valor) AS valor, count(*) AS informantes FROM (SELECT r.codigo AS g0, c.columna AS g1, r.estab, sum(c.valor) AS valor'
			. ' FROM *PREFIX*estadistica_rem r CROSS JOIN LATERAL (VALUES (8, r.col08), (9, r.col09)) AS c(columna, valor)'
			. self::WHERE . ' AND c.valor IS NOT NULL GROUP BY g0, g1, r.estab) s'
			. ' WHERE s.valor IS NOT NULL GROUP BY g0, g1 ORDER BY g0, g1 LIMIT 5001',
			QueryCompiler::compile(self::spec(['agrupar' => ['codigo', 'columna']]), self::CODES, [])['sql'],
		);
	}

	/** A trait is the establishment's: the register joins its sums, not its rows. */
	public function testGroupingByATraitJoinsTheRegister(): void {
		self::assertSame(
			'SELECT e.servicio AS g0, sum(s.valor) AS valor, count(*) AS informantes'
			. ' FROM (SELECT r.estab, ' . self::TOTAL . ' FROM *PREFIX*estadistica_rem r' . self::WHERE . ' GROUP BY r.estab) s'
			. ' LEFT JOIN *PREFIX*estadistica_establishment e ON e.estab = s.estab'
			. ' WHERE s.valor IS NOT NULL GROUP BY g0 ORDER BY g0 LIMIT 5001',
			QueryCompiler::compile(self::spec(['agrupar' => ['servicio']]), self::CODES, [])['sql'],
		);
	}

	public function testWithoutGroupingTheStatementIsOneTotal(): void {
		self::assertSame(
			'SELECT sum(s.valor) AS valor, count(*) AS informantes'
			. ' FROM (SELECT r.estab, ' . self::TOTAL . ' FROM *PREFIX*estadistica_rem r' . self::WHERE . ' GROUP BY r.estab) s WHERE s.valor IS NOT NULL',
			QueryCompiler::compile(self::spec(['agrupar' => []]), self::CODES, [])['sql'],
		);
	}

	/** a16: the SQL is the same whatever the values, and none of them is in it. */
	public function testTheSqlCarriesNoValueFromTheSpec(): void {
		$one = self::spec(['comparar' => ['pares' => ['servicio' => 'Servicio de Salud Uno']]]);
		$other = QuerySpec::from([
			'fuente' => 'rem-p',
			'periodo' => ['desde' => '2012-02', 'hasta' => '2013-07'],
			'filtros' => ['codigos' => ['P1206010', 'A0200002', 'x.y-z'], 'columnas' => ['col08', 'col09']],
			'agrupar' => ['mes'],
			'comparar' => ['pares' => ['servicio' => "Servicio'); DROP TABLE oc_users; --"]],
		], self::YEAR);

		foreach ([['estab' => self::ESTAB], [], ['servicio' => 'x']] as $who) {
			$sql = QueryCompiler::compile($one, self::CODES, $who)['sql'];
			self::assertSame($sql, QueryCompiler::compile($other, ['P1206010', 'A0200002', 'x.y-z'], $who)['sql']);
			self::assertStringNotContainsString("'", $sql, 'no string literal at all');
			foreach (['2025', '2026', '202510', '02010420', '03500366', (string)self::ESTAB, 'Servicio'] as $value) {
				self::assertStringNotContainsString($value, $sql);
			}
		}
	}

	public function testATraitOutsideTheClosedSetIsAProgrammingError(): void {
		$this->expectException(LogicException::class);

		QueryCompiler::compile(self::spec(), self::CODES, ['nombre = nombre OR 1 = 1 --' => 'x']);
	}

	public function testRowsBecomePointsInAgruparsOrder(): void {
		$spec = self::spec(['agrupar' => ['servicio', 'columna'], 'metrica' => 'promedio_establecimiento']);

		self::assertSame(
			[
				['grupo' => ['Servicio de Salud Uno', 'col08'], 'valor' => 8.5, 'informantes' => 2],
				['grupo' => [null, 'col09'], 'valor' => 3.0, 'informantes' => 1],
			],
			QueryCompiler::points($spec, [
				['g0' => 'Servicio de Salud Uno', 'g1' => 8, 'valor' => '8.5000000000000000', 'informantes' => 2],
				['g0' => null, 'g1' => '9', 'valor' => '3', 'informantes' => '1'],
			]),
		);
		self::assertSame(
			[['grupo' => ['2025-10', 2026], 'valor' => 12.0, 'informantes' => 3]],
			QueryCompiler::points(self::spec(['agrupar' => ['mes', 'anio']]), [['g0' => 202510, 'g1' => 2026, 'valor' => '12.000000', 'informantes' => 3]]),
		);
	}

	public function testATotalOfNothingReportedIsNoPoint(): void {
		self::assertSame([], QueryCompiler::points(self::spec(['agrupar' => []]), [['valor' => null, 'informantes' => 0]]));
	}

	public function testMoreGroupsThanTheCeilingIsRefusedInSpanish(): void {
		$rows = array_fill(0, QueryCompiler::MAX_GROUPS + 1, ['g0' => 202601, 'valor' => '1', 'informantes' => 1]);

		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('La consulta da más de 5.000 grupos');
		QueryCompiler::points(self::spec(), $rows);
	}
}
