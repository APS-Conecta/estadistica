<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Piv\Population;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Register\Register;
use OCA\Estadistica\Service\Sources;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * The Metas Sanitarias month by month (acceptance a12, a13): for each meta of
 * a year, this install's establishment's numerador, denominador and %, its
 * gaps to the meta comprometida and to the meta nacional, and the country's
 * and a peer group's %.
 *
 * - **A month is the progress up to it.** A Serie A route adds up from its
 *   periodo's first month to that month, and no further than the periodo's
 *   last: Meta I's denominator stops in September. A Serie P route is a
 *   snapshot at a corte (June and December, measured on SERIE_REM_2025), so a
 *   month shows the latest corte up to it, and nothing before the first.
 * - **No figure past the data.** A Serie A month after the meta year's fecha
 *   de corte has none yet, rather than repeating the last month's.
 * - **Denominators.** REM, like a numerador; FONASA's inscritos for the meta
 *   year (Population: its corte rule and its band rule), each age group times
 *   its prevalence; or the establishment's commitment alone. A committed
 *   figure wins for the local series only; the country and the peers stay
 *   FONASA's (docs/adr/0007).
 * - **National and peer % where the denominator is nationally computable**:
 *   REM, or FONASA's inscritos when a corte serves the year. A meta whose
 *   denominator only a commitment gives, and a manual meta, have none.
 * - **Peers are «like ours»**: the register's establishments that share the
 *   chosen traits with this one (owner, Slice 9), as a consulta's pares: true.
 * - Every REM route is asked through QueryExecutor, in one read-only snapshot,
 *   with the statement a consulta compiles to (docs/adr/0006), so a meta shows
 *   the numbers POST consultas would give for the same códigos (a15).
 *
 * The month rules are pure and public (GoalEvaluatorTest).
 *
 * @psalm-import-type Metas from GoalSeed
 * @psalm-import-type Indicador from GoalSeed
 * @psalm-import-type Parte from GoalSeed
 * @psalm-import-type Grupo from GoalSeed
 * @psalm-import-type Commitment from Goals
 * @psalm-import-type Punto from \OCA\Estadistica\Query\QueryCompiler
 * @psalm-import-type Inscritos from Population
 * @psalm-import-type Fuente from Sources
 * @psalm-type Mes = array{mes: string, numerador: ?float, denominador: ?float, porcentaje: ?float, brecha_comprometida: ?float, brecha_nacional: ?float, nacional: ?float, pares: ?float}
 * @psalm-type Poblacion = array{comprometida: bool, corte: ?string, factor: ?float, prorrateado: bool}
 * @psalm-type Meta = array{codigo: string, nombre: string, indicador: string, meta_nacional: float, meta_comprometida: ?float, peso: float, nota: ?string, numerador: ?array<string, mixed>, denominador: ?array<string, mixed>, manual: bool, cumplimiento: ?float, denominador_comprometido: ?float, poblacion: ?Poblacion, meses: list<Mes>}
 * @psalm-type Respuesta = array{anios: list<int>, anio: int, nombre: string, referencia: string, nota: ?string, diccionarios: array<string, string>, version: int, establecimiento: ?string, pares: ?array<string, string>, aviso: ?string, fuentes: list<Fuente>, indicadores: list<Meta>}
 */
class GoalEvaluator {
	/** The scopes a meta is measured in, as a consulta's series. */
	private const SCOPES = ['local', 'nacional', 'pares'];

	public function __construct(
		private Goals $goals,
		private QueryExecutor $executor,
		private Population $population,
		private Register $register,
		private Sources $sources,
		private ITimeFactory $time,
	) {
	}

	/**
	 * @param ?int $anio the metas' year; null for the newest with metas up to the current year (else the newest)
	 * @param list<string> $traits what the peer group shares with this install's establishment, each one of
	 *                             QuerySpec::TRAITS (the caller's check: ApiController); none for no peers
	 * @return Respuesta
	 * @throws DoesNotExistException no metas for that year
	 * @throws RefusedException
	 */
	public function evaluate(?int $anio, Establishment $local, array $traits): array {
		$now = $this->time->getTime();
		$currentYear = (int)gmdate('Y', $now);
		$years = $this->goals->years();
		$anio ??= self::defaultYear($years, $currentYear);
		$metas = $anio === null ? null : $this->goals->get($anio);
		if ($anio === null || $metas === null) {
			throw new DoesNotExistException('No hay Metas Sanitarias cargadas para ese año.');
		}

		$estab = $local->isConfigured() ? (int)$local->deisCode : null;
		[$peers, $aviso] = $this->peers($estab, array_values(array_unique($traits)));
		$months = self::months($anio, $currentYear, (int)gmdate('n', $now));
		$fuentes = array_column($this->sources->all(), null, 'id');
		$through = self::through($fuentes['rem-a'], $anio);
		$commitments = $estab === null ? [] : $this->goals->commitments($anio, $estab);
		$answers = $this->ask($metas, $estab, $peers);
		$inscritos = in_array(GoalSeed::BASE_POPULATION, array_column($metas['indicadores'], 'base'), true)
			? [
				'local' => $estab === null ? null : $this->population->local($anio, $estab),
				'nacional' => $this->population->national($anio),
				'pares' => $peers === null ? null : $this->population->peers($anio, $peers),
			]
			: ['local' => null, 'nacional' => null, 'pares' => null];

		$indicadores = [];
		foreach ($metas['indicadores'] as $meta) {
			$commitment = $commitments[$meta['codigo']] ?? ['meta' => null, 'denominador' => null, 'cumplimiento' => null];
			$indicadores[] = self::indicator($meta, $commitment, $answers, $inscritos, $estab !== null, $anio, $months, $through);
		}

		return self::response($years, $metas, $estab === null ? null : $local->deisCode, $peers, $aviso,
			[$fuentes['rem-a'], $fuentes['rem-p'], $fuentes['piv']], $indicadores);
	}

	/**
	 * The answer's shape, in one place, so ApiContractTest reads the served
	 * keys from it (QueryExecutor::response's way).
	 *
	 * @param list<int> $years
	 * @param Metas $metas
	 * @param ?array<string, string> $pares
	 * @param list<Fuente> $fuentes
	 * @param list<Meta> $indicadores
	 * @return Respuesta
	 */
	public static function response(array $years, array $metas, ?string $establecimiento, ?array $pares, ?string $aviso, array $fuentes, array $indicadores): array {
		return [
			'anios' => $years,
			'anio' => $metas['anio'],
			'nombre' => $metas['nombre'],
			'referencia' => $metas['referencia'],
			'nota' => $metas['nota'],
			'diccionarios' => $metas['diccionarios'],
			'version' => $metas['version'],
			'establecimiento' => $establecimiento,
			'pares' => $pares,
			'aviso' => $aviso,
			'fuentes' => $fuentes,
			'indicadores' => $indicadores,
		];
	}

	/**
	 * One meta as the view and the API read it, from its routes' answers.
	 *
	 * @param Indicador $meta
	 * @param Commitment $commitment
	 * @param array<int, array<string, ?list<Punto>>> $answers each route's series, by spl_object_id of its QuerySpec
	 * @param array<string, ?Inscritos> $inscritos
	 * @return Meta
	 */
	public static function indicator(array $meta, array $commitment, array $answers, array $inscritos, bool $configured, int $anio, int $months, ?int $through): array {
		$counted = in_array($meta['base'], [GoalSeed::BASE_POPULATION, GoalSeed::BASE_COMMITTED], true);
		$indicator = [
			'codigo' => $meta['codigo'],
			'nombre' => $meta['nombre'],
			'indicador' => $meta['indicador'],
			'meta_nacional' => $meta['meta_nacional'],
			'meta_comprometida' => $commitment['meta'],
			'peso' => $meta['peso'],
			'nota' => $meta['nota'],
			'numerador' => $meta['ruta']['numerador'],
			'denominador' => $meta['ruta']['denominador'],
			'manual' => $meta['manual'],
			'cumplimiento' => $meta['manual'] ? $commitment['cumplimiento'] : null,
			'denominador_comprometido' => $counted ? $commitment['denominador'] : null,
			'poblacion' => null,
			'meses' => [],
		];
		if ($meta['manual'] || $meta['numerador'] === null) {
			return $indicator;
		}

		$numerador = [];
		$denominador = [];
		foreach (self::SCOPES as $scope) {
			$numerador[$scope] = self::route($meta['numerador'], $answers, $scope, $anio, $months, $through);
			$denominador[$scope] = $meta['denominador'] === null
				? null
				: self::route($meta['denominador'], $answers, $scope, $anio, $months, $through);
		}

		$committed = $configured ? $commitment['denominador'] : null;
		if ($meta['base'] === GoalSeed::BASE_POPULATION) {
			$people = array_map(static fn (?array $read): ?array => self::people($meta['poblacion'], $read), $inscritos);
			$described = $configured ? 'local' : 'nacional';
			$indicator['poblacion'] = [
				'comprometida' => $committed !== null,
				'corte' => $committed === null ? ($inscritos[$described]['corte'] ?? null) : null,
				'factor' => $committed === null ? ($inscritos[$described]['factor'] ?? null) : null,
				'prorrateado' => $committed === null && ($people[$described]['prorrateado'] ?? false),
			];
			foreach (self::SCOPES as $scope) {
				$valor = $scope === 'local' && $committed !== null ? $committed : ($people[$scope]['valor'] ?? null);
				$denominador[$scope] = $valor === null || ($scope === 'local' && !$configured) ? null : array_fill(0, $months, $valor);
			}
		} elseif ($meta['base'] === GoalSeed::BASE_COMMITTED) {
			$denominador = ['local' => $committed === null ? null : array_fill(0, $months, $committed), 'nacional' => null, 'pares' => null];
		}

		for ($i = 0; $i < $months; $i++) {
			$local = [$numerador['local'][$i] ?? null, $denominador['local'][$i] ?? null];
			$indicator['meses'][] = self::month(
				sprintf('%04d-%02d', $anio, $i + 1),
				$local[0],
				$local[1],
				$commitment['meta'],
				$meta['meta_nacional'],
				self::percent($numerador['nacional'][$i] ?? null, $denominador['nacional'][$i] ?? null),
				self::percent($numerador['pares'][$i] ?? null, $denominador['pares'][$i] ?? null),
			);
		}

		return $indicator;
	}

	/**
	 * One month of a meta: the local figures, their % and gaps, and the
	 * country's and the peers' %. The shape in one place, so
	 * ApiContractTest reads the served keys from it.
	 *
	 * @return Mes
	 */
	public static function month(string $mes, ?float $numerador, ?float $denominador, ?float $comprometida, float $nacional, ?float $porcentajeNacional, ?float $porcentajePares): array {
		$porcentaje = self::percent($numerador, $denominador);

		return [
			'mes' => $mes,
			'numerador' => $numerador,
			'denominador' => $denominador,
			'porcentaje' => $porcentaje,
			'brecha_comprometida' => self::gap($porcentaje, $comprometida),
			'brecha_nacional' => self::gap($porcentaje, $nacional),
			'nacional' => $porcentajeNacional,
			'pares' => $porcentajePares,
		];
	}

	/**
	 * A route's figure at each month of the meta year (index 0 is January).
	 * - Serie A: the sum from the periodo's first month to that month, and no
	 *   further than its last. Null after $through, the meta year's fecha de
	 *   corte, and while nothing in that range was reported.
	 * - Serie P: the latest corte up to that month within the periodo; null
	 *   before the first.
	 *
	 * @param list<Punto> $points one consulta's series, grouped by mes
	 * @param int $desde YYYYMM
	 * @param int $hasta YYYYMM
	 * @param ?int $through YYYYMM
	 * @return list<?float>
	 */
	public static function monthly(array $points, string $serie, int $desde, int $hasta, int $anio, int $months, ?int $through): array {
		$byMonth = [];
		foreach ($points as $point) {
			$byMonth[(int)str_replace('-', '', (string)$point['grupo'][0])] = $point['valor'];
		}
		ksort($byMonth);

		$values = [];
		for ($m = 1; $m <= $months; $m++) {
			$month = $anio * 100 + $m;
			$upTo = min($month, $hasta);
			$inRange = array_filter($byMonth, static fn (int $at): bool => $at >= $desde && $at <= $upTo, ARRAY_FILTER_USE_KEY);
			if ($serie === 'P') {
				$values[] = $inRange === [] ? null : (float)end($inRange);
			} else {
				$values[] = $through === null || $month > $through || $inRange === [] ? null : (float)array_sum($inRange);
			}
		}

		return $values;
	}

	/**
	 * A route's months: its sumar terms added and its restar terms taken
	 * away. A month is null when no sumar term has a figure for it; a restar
	 * term without one takes nothing away.
	 *
	 * @param list<list<?float>> $sumar
	 * @param list<list<?float>> $restar
	 * @return list<?float>
	 */
	public static function combine(array $sumar, array $restar, int $months): array {
		$values = [];
		for ($i = 0; $i < $months; $i++) {
			$added = array_filter(array_column($sumar, $i), static fn (?float $value): bool => $value !== null);
			$values[] = $added === [] ? null : array_sum($added) - array_sum(array_filter(array_column($restar, $i), static fn (?float $value): bool => $value !== null));
		}

		return $values;
	}

	/**
	 * A meta's people from FONASA's inscritos: each group counted by
	 * Population's band rule, times its prevalence.
	 *
	 * @param list<Grupo> $groups
	 * @param ?Inscritos $inscritos
	 * @return ?array{valor: float, prorrateado: bool}
	 */
	public static function people(array $groups, ?array $inscritos): ?array {
		if ($inscritos === null) {
			return null;
		}

		$valor = 0.0;
		$cut = false;
		foreach ($groups as $group) {
			$count = Population::count($inscritos, $group['desde'], $group['hasta'], $group['sexo']);
			$valor += $count['valor'] * $group['prevalencia'] / 100;
			$cut = $cut || $count['prorrateado'];
		}

		return ['valor' => $valor, 'prorrateado' => $cut];
	}

	public static function percent(?float $numerador, ?float $denominador): ?float {
		return $numerador === null || $denominador === null || $denominador <= 0.0 ? null : round($numerador / $denominador * 100, 2);
	}

	public static function gap(?float $porcentaje, ?float $meta): ?float {
		return $porcentaje === null || $meta === null ? null : round($porcentaje - $meta, 2);
	}

	/** How many of the meta year's months have passed: all of a past year, none of a future one. */
	public static function months(int $anio, int $currentYear, int $currentMonth): int {
		return $anio < $currentYear ? 12 : ($anio === $currentYear ? $currentMonth : 0);
	}

	/**
	 * The year GET indicadores answers when none is asked for: the newest with
	 * metas up to the current one, else the newest (next year's, loaded early).
	 *
	 * @param list<int> $years newest first
	 */
	public static function defaultYear(array $years, int $currentYear): ?int {
		foreach ($years as $year) {
			if ($year <= $currentYear) {
				return $year;
			}
		}

		return $years[0] ?? null;
	}

	/**
	 * Every REM route of the year's metas, each asked for the three scopes in
	 * one read-only snapshot, so no meta mixes two loads.
	 *
	 * @param Metas $metas
	 * @param ?array<string, string> $peers
	 * @return array<int, array<string, ?list<Punto>>> by spl_object_id of the route's QuerySpec
	 */
	private function ask(array $metas, ?int $estab, ?array $peers): array {
		$specs = [];
		foreach ($metas['indicadores'] as $meta) {
			foreach ([$meta['numerador'], $meta['denominador']] as $part) {
				foreach ($part === null ? [] : [...$part['sumar'], ...$part['restar']] as $spec) {
					$specs[spl_object_id($spec)] = $spec;
				}
			}
		}

		return $this->executor->readOnly(function () use ($specs, $estab, $peers): array {
			$answers = [];
			foreach ($specs as $id => $spec) {
				$answers[$id] = [
					'local' => $estab === null ? null : $this->executor->series($spec, $spec->codigos, ['estab' => $estab]),
					'nacional' => $this->executor->series($spec, $spec->codigos, []),
					'pares' => $peers === null ? null : $this->executor->series($spec, $spec->codigos, $peers),
				];
			}

			return $answers;
		});
	}

	/**
	 * @param Parte $part
	 * @param array<int, array<string, ?list<Punto>>> $answers
	 * @return ?list<?float> null when the scope was not asked
	 */
	private static function route(array $part, array $answers, string $scope, int $anio, int $months, ?int $through): ?array {
		$terms = [];
		foreach (['sumar', 'restar'] as $sign) {
			$terms[$sign] = [];
			foreach ($part[$sign] as $spec) {
				$points = $answers[spl_object_id($spec)][$scope] ?? null;
				if ($points === null) {
					return null;
				}
				$terms[$sign][] = self::monthly($points, $part['serie'], $part['desde'], $part['hasta'], $anio, $months, $through);
			}
		}

		return self::combine($terms['sumar'], $terms['restar'], $months);
	}

	/**
	 * The peer group «like ours», or null and why not. Without an
	 * establishment there is no «ours»: the page already says so.
	 *
	 * @param list<string> $traits
	 * @return array{0: ?array<string, string>, 1: ?string}
	 */
	private function peers(?int $estab, array $traits): array {
		if ($traits === [] || $estab === null) {
			return [null, null];
		}
		$own = $this->register->traits($estab);
		if ($own === null) {
			return [null, sprintf('El establecimiento %d no está en la Base de Establecimientos cargada: no hay pares con quién compararlo.', $estab)];
		}

		$peers = [];
		foreach ($traits as $trait) {
			if (($own[$trait] ?? null) === null) {
				return [null, sprintf('La Base de Establecimientos no tiene «%s» para el establecimiento %d: elija los pares por otro rasgo.', $trait, $estab)];
			}
			$peers[$trait] = (string)$own[$trait];
		}

		return [$peers, null];
	}

	/**
	 * The meta year's Serie A fecha de corte as YYYYMM, or null when the year
	 * is not loaded.
	 *
	 * @param Fuente $remA
	 */
	private static function through(array $remA, int $anio): ?int {
		foreach ($remA['anios'] as $year) {
			if ($year['anio'] === $anio && $year['filas'] > 0 && $year['fecha_corte'] !== null) {
				return (int)str_replace('-', '', $year['fecha_corte']);
			}
		}

		return null;
	}
}
