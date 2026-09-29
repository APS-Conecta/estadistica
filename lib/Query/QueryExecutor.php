<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Query;

use Closure;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Service\Sources;
use OCP\DB\Exception;
use OCP\IDBConnection;
use Throwable;

/**
 * Answers a consulta against the database (acceptance a7, a14, a16). It
 * resolves what the spec names by reference (a hoja's códigos, the peer
 * traits «like ours»), runs one compiled statement per series, and returns
 * the series with their labels and their fuente.
 *
 * **The guard is PostgreSQL's, not this class's.** Every statement runs in a
 * transaction opened with SET TRANSACTION … READ ONLY, with statement_timeout
 * set locally. set_config(…, true) is the SET LOCAL that a bound value can
 * reach. So a write that got this far fails in PostgreSQL, whoever built it,
 * and a statement past the timeout is cancelled and answered as a Spanish
 * refusal. REPEATABLE READ gives the series one snapshot: a load that commits
 * mid-request cannot put the local series and the national one on different
 * data.
 *
 * Nextcloud issues nothing between BEGIN and the transaction's first statement
 * (lib/private/DB/Connection.php; design, Key Discoveries), so SET TRANSACTION
 * comes first, as PostgreSQL requires.
 *
 * @psalm-import-type Punto from QueryCompiler
 * @psalm-import-type Label from Catalog
 * @psalm-import-type Fuente from Sources
 * @psalm-type Etiqueta = array{codigo: string, hoja: string, seccion: string, prestacion: string, columnas: array<string, string>, nota: ?string}
 * @psalm-type Respuesta = array{series: array{local: ?list<Punto>, nacional: ?list<Punto>, pares: ?list<Punto>}, etiquetas: array{local: ?string, pares: ?array<string, string>, codigos: list<Etiqueta>}, fuente: Fuente}
 */
class QueryExecutor {
	/**
	 * Research measured a national-history consulta at ≈ 5–6 s cold over 18
	 * years, so this fits one.
	 */
	public const TIMEOUT_MS = 10_000;

	/** PostgreSQL's query_canceled, which is what a statement_timeout raises. */
	private const CANCELLED = '57014';

	public function __construct(
		private IDBConnection $db,
		private Catalog $catalog,
		private Register $register,
		private Sources $sources,
	) {
	}

	/**
	 * @param Establishment $local the local series' establishment; none() computes no local series
	 * @return Respuesta
	 * @throws RefusedException
	 */
	public function answer(QuerySpec $spec, Establishment $local): array {
		$codigos = $this->codes($spec);
		$pares = $spec->pares === [] ? null : $this->peers($spec->pares, $local);
		$estab = $local->isConfigured() ? (int)$local->deisCode : null;
		if ($estab === null && !$spec->nacional && $pares === null) {
			throw new RefusedException('La consulta no tiene ninguna serie que calcular: no hay establecimiento configurado, y no se compara con el país ni con pares.');
		}

		[$mine, $country, $peers] = $this->readOnly(fn (): array => [
			$estab === null ? null : $this->series($spec, $codigos, ['estab' => $estab]),
			$spec->nacional ? $this->series($spec, $codigos, []) : null,
			$pares === null ? null : $this->series($spec, $codigos, $pares),
		]);

		$anio = $spec->lastYear();
		$labels = array_map(
			static fn (array $label): array => self::label($label, $anio, $spec->columnas),
			array_values($this->catalog->labels($anio, $spec->serie, $codigos)),
		);

		return self::response($mine, $country, $peers, $estab === null ? null : $local->deisCode, $pares, $labels,
			array_column($this->sources->all(), null, 'id')[$spec->fuente]);
	}

	/**
	 * The answer's shape, in one place, so ApiContractTest reads the served
	 * keys from it.
	 *
	 * @param ?list<Punto> $local
	 * @param ?list<Punto> $nacional
	 * @param ?list<Punto> $pares
	 * @param ?array<string, string> $peers the peer traits, «true» resolved
	 * @param list<Etiqueta> $codigos
	 * @param Fuente $fuente
	 * @return Respuesta
	 */
	public static function response(?array $local, ?array $nacional, ?array $pares, ?string $establishment, ?array $peers, array $codigos, array $fuente): array {
		return [
			'series' => ['local' => $local, 'nacional' => $nacional, 'pares' => $pares],
			'etiquetas' => ['local' => $establishment, 'pares' => $peers, 'codigos' => $codigos],
			'fuente' => $fuente,
		];
	}

	/**
	 * A código's label as the series' readers need it: its hoja, sección and
	 * prestación, and the chosen columns' headers. It comes from the periodo's
	 * last year, whose own dictionary or the nearest later one's labels it;
	 * nota says which.
	 *
	 * @param Label $label
	 * @param non-empty-list<int> $columnas
	 * @return Etiqueta
	 */
	public static function label(array $label, int $anio, array $columnas): array {
		$columns = [];
		foreach ($columnas as $col) {
			$columns[sprintf('col%02d', $col)] = Catalog::describe($label, $anio, $col)['columna'];
		}
		$described = Catalog::describe($label, $anio, $columnas[0]);

		return [
			'codigo' => $label['codigo'],
			'hoja' => $described['hoja'],
			'seccion' => $described['seccion'],
			'prestacion' => $described['prestacion'],
			'columnas' => $columns,
			'nota' => $described['nota'],
		];
	}

	/**
	 * Runs $work in a transaction PostgreSQL holds to reading, each statement
	 * under the timeout. It is public for what only the database can prove:
	 * that a write through here fails, and that a slow statement is cancelled
	 * (QueryExecutorTest).
	 *
	 * @template T
	 * @param Closure(): T $work
	 * @return T
	 * @throws RefusedException a statement ran past the timeout
	 */
	public function readOnly(Closure $work, int $timeoutMs = self::TIMEOUT_MS): mixed {
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
			$this->db->executeQuery("SELECT set_config('statement_timeout', ?, true)", [(string)$timeoutMs])->closeCursor();
			$value = $work();
			$this->db->commit();

			return $value;
		} catch (Exception $e) {
			$this->db->rollBack();
			$driver = $e->getPrevious();
			// The driver's SQLSTATE, read without naming Doctrine (OCP only).
			if ($driver !== null && method_exists($driver, 'getSQLState') && $driver->getSQLState() === self::CANCELLED) {
				throw new RefusedException(sprintf(
					'La consulta tardó más de %s s y se canceló: acote el periodo, las prestaciones o la comparación.',
					str_replace('.', ',', (string)($timeoutMs / 1000)),
				));
			}
			throw $e;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The códigos the filtros name: a hoja's (and sección's), resolved
	 * through the dictionaries the periodo reads; the códigos named; or those
	 * named, each checked to be in the hoja named with them.
	 *
	 * @return list<string>
	 * @throws RefusedException
	 */
	private function codes(QuerySpec $spec): array {
		if ($spec->hoja === null) {
			return $spec->codigos;
		}

		$where = $spec->seccion === null
			? sprintf('la hoja «%s»', $spec->hoja)
			: sprintf('la sección «%s» de la hoja «%s»', $spec->seccion, $spec->hoja);
		$scope = $this->catalog->scope($spec->serie, $spec->firstYear(), $spec->lastYear(), $spec->hoja, $spec->seccion);
		if ($scope === []) {
			throw new RefusedException(sprintf('Ningún diccionario del periodo tiene %s.', $where));
		}
		if ($spec->codigos === []) {
			return $scope;
		}

		$outside = array_values(array_diff($spec->codigos, $scope));
		if ($outside !== []) {
			throw new RefusedException(sprintf('La prestación %s no está en %s según los diccionarios del periodo.', $outside[0], $where));
		}

		return $spec->codigos;
	}

	/**
	 * @param array<string, string|true> $traits
	 * @return array<string, string> each trait's value, with true resolved to the local establishment's own
	 * @throws RefusedException
	 */
	private function peers(array $traits, Establishment $local): array {
		$own = null;
		$peers = [];
		foreach ($traits as $trait => $value) {
			if ($value === true) {
				if (!$local->isConfigured()) {
					throw new RefusedException('Para elegir pares parecidos al propio establecimiento hace falta saber cuál es, y no hay establecimiento configurado.');
				}
				$own ??= $this->register->traits((int)$local->deisCode);
				if ($own === null) {
					throw new RefusedException(sprintf('El establecimiento %s no está en la Base de Establecimientos cargada: no se pueden elegir sus pares.', $local->deisCode));
				}
				$value = $own[$trait] ?? null;
				if ($value === null) {
					throw new RefusedException(sprintf('La Base de Establecimientos no tiene «%s» para el establecimiento %s: no se pueden elegir sus pares por ese rasgo.', $trait, $local->deisCode));
				}
			}
			$peers[$trait] = $value;
		}

		return $peers;
	}

	/**
	 * One series of a consulta, as answer() asks it. Public for the metas
	 * (GoalEvaluator), which ask every route of a year inside one readOnly():
	 * it runs only there.
	 *
	 * @param list<string> $codigos
	 * @param array<string, int|string> $who [] for the country; ['estab' => code]; trait → value for a peer group
	 * @return list<Punto>
	 */
	public function series(QuerySpec $spec, array $codigos, array $who): array {
		['sql' => $sql, 'params' => $params] = QueryCompiler::compile($spec, $codigos, $who);
		$result = $this->db->executeQuery($sql, $params);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return QueryCompiler::points($spec, $rows);
	}
}
