<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Query;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Service\Sources;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * One answer, one snapshot: what answer() reads besides the series (the
 * hoja's códigos, the peers' traits, the labels, the fuente) is read inside
 * the same read-only transaction, so a dictionary that reloads mid-request
 * cannot put the labels on one version and the figures on another. What the
 * database itself enforces is QueryExecutorTest's, in the integration suite.
 */
final class QueryExecutorTest extends TestCase {
	public function testEverythingAnAnswerReadsIsReadInsideItsTransaction(): void {
		$log = [];
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function () use (&$log): void {
			$log[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function () use (&$log): void {
			$log[] = 'commit';
		});
		$db->method('executeQuery')->willReturn($result);

		$catalog = $this->createMock(Catalog::class);
		$catalog->method('scope')->willReturnCallback(function () use (&$log): array {
			$log[] = 'códigos';
			return ['02010420'];
		});
		$catalog->method('labels')->willReturnCallback(function () use (&$log): array {
			$log[] = 'labels';
			return [];
		});
		$register = $this->createMock(Register::class);
		$register->method('traits')->willReturnCallback(function () use (&$log): array {
			$log[] = 'traits';
			return ['tipo' => 'Centro de Salud Familiar'];
		});
		$sources = $this->createMock(Sources::class);
		$sources->method('all')->willReturnCallback(function () use (&$log): array {
			$log[] = 'fuente';
			return [['id' => 'rem-a']];
		});

		$spec = QuerySpec::from([
			'fuente' => 'rem-a',
			'periodo' => ['desde' => '2026-01', 'hasta' => '2026-03'],
			'filtros' => ['hoja' => 'A03', 'columnas' => ['col01']],
			'comparar' => ['pares' => ['tipo' => true]],
		], 2026);
		(new QueryExecutor($db, $catalog, $register, $sources))->answer($spec, Establishment::of('123456'));

		self::assertSame(['begin', 'códigos', 'traits', 'labels', 'fuente', 'commit'], $log);
	}
}
