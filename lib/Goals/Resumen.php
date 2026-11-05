<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use APS\Common\Text;
use Closure;
use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The Resumen (docs/adr/0009): the view that opens first. One card per meta
 * of the newest metas seed, for one establishment: the percentage and «N de
 * D» of the meta's last month with a figure in the current year, whether it
 * cumple, and its histórico.
 *
 * - **The current year** is the latest with REM figures, and never past the
 *   metas' own: October's metas of next year are counted over this year's
 *   figures, their rutas moved back a year (GoalEvaluator::history).
 * - **Datos al** is the establishment's own last month in the meta's serie
 *   (GoalEvaluator's fecha_corte_local, #25), not the month of the figure: a
 *   Serie P meta's November shows its June corte. None for a manual meta or
 *   without an establishment.
 *
 * - **One formula path.** The cards are GoalEvaluator's answer for the
 *   establishment alone, so a card's N, D and % are GET indicadores' local
 *   series for the same month: the same compiled consultas, in one read-only
 *   snapshot (docs/adr/0006 amendment).
 * - **Cumple** against the meta comprometida when one is typed, else the
 *   meta nacional. A manual meta (VIII) is its cumplimiento informado.
 * - **Estado** says why a meta shows no figure: «esperando ruta oficial»
 *   when its seed marks a ruta "oficial": false (absent means oficial), or
 *   «falta el denominador oficial de <año>» when its ages cut a PIV band and
 *   no denominador comprometido is typed for that year (GoalEvaluator's
 *   falta_oficial).
 * - **Histórico.** The newest metas over every year of the ventana up to the
 *   current one, oldest first, each periodo moved by whole years. A past
 *   year is comparable only when its moved periodos lie whole in the
 *   ventana, and its own diccionario gives every código × columna of the
 *   ruta the label the current year's gives it, once case, accents and
 *   plurals are folded (sameLabel). A borrowed diccionario never compares.
 *   Any other year is «sin datos comparables» and shows nothing. Serie A
 *   shows each month's running sum, Serie P its June and December cortes,
 *   each only in its own month: a corte not reported is sin informar, never
 *   the one before it. A year already over carries its cierre: December's %.
 * - **Its source**, which a click on any figure shows (docs/adr/0009): each
 *   month's origen and the fecha de corte of its year's load (origin()),
 *   each year's ruta with its periodos moved (ruta()), each código labelled
 *   by that year's diccionario as POST consultas labels it
 *   (QueryExecutor::label), and the metas' referencia.
 *
 * Built here, the one way; ResumenStore keeps the configured
 * establishment's once per change.
 *
 * @psalm-import-type Meta from GoalEvaluator
 * @psalm-import-type Carried from GoalEvaluator
 * @psalm-import-type Metas from GoalSeed
 * @psalm-import-type Label from Catalog
 * @psalm-import-type Parte from GoalSeed
 * @psalm-import-type Etiqueta from QueryExecutor
 * @psalm-import-type Fuente from Sources
 * @psalm-type Ruta = array{fuente: string, nombre: string, desde: ?string, hasta: ?string, sumar: list<list<Etiqueta>>, restar: list<list<Etiqueta>>}
 * @psalm-type Cifra = array{mes: string, numerador: ?float, denominador: ?float, porcentaje: ?float, origen: ?string, fecha_corte: ?string}
 * @psalm-type Historico = array{anio: int, estado: ?string, cierre: ?float, meses: list<Cifra>, ruta: array{numerador: ?Ruta, denominador: ?Ruta}}
 * @psalm-type Tarjeta = array{codigo: string, nombre: string, indicador: string, meta_nacional: float, meta_comprometida: ?float, manual: bool, estado: ?string, mes: ?string, datos_al: ?string, numerador: ?float, denominador: ?float, porcentaje: ?float, cumple: ?bool, historico: list<Historico>, origen: ?string, fecha_corte: ?string}
 * @psalm-type Respuesta = array{anio: int, nombre: string, referencia: string, establecimiento: ?string, metas: list<Tarjeta>}
 */
class Resumen {
	public const NOT_COMPARABLE = 'sin datos comparables';

	public function __construct(
		private Goals $goals,
		private GoalEvaluator $evaluator,
		private Catalog $catalog,
		private YearWindow $window,
		private Sources $sources,
	) {
	}

	/**
	 * @return Respuesta
	 * @throws DoesNotExistException no metas are loaded
	 * @throws RefusedException
	 */
	public function build(Establishment $local): array {
		$anio = $this->goals->years()[0] ?? null;
		$metas = $anio === null ? null : $this->goals->get($anio);
		if ($anio === null || $metas === null) {
			throw new DoesNotExistException('No hay Metas Sanitarias cargadas.');
		}

		$firstYear = $this->window->firstYear();
		$history = $this->evaluator->history($metas, $firstYear, $local);
		$current = array_key_last($history);
		$labels = $this->labels($metas, array_keys($history));
		$fuentes = array_column($this->sources->all(), null, 'id');
		$moved = [];
		foreach (array_keys($history) as $year) {
			$moved[$year] = GoalSeed::shift($metas, $year - $anio)['indicadores'];
		}
		$cards = [];
		foreach ($metas['indicadores'] as $i => $indicador) {
			$fuente = $fuentes[$indicador['numerador']['fuente'] ?? ''] ?? null;
			$historico = [];
			foreach ($history as $year => $counted) {
				$comparable = $year === $current || self::comparable(GoalSeed::terms($indicador), $labels, $year, $current, $year - $anio, $firstYear);
				$label = static fn (QuerySpec $spec): array => array_map(
					static fn (string $codigo): array => QueryExecutor::label($labels[$year][$spec->serie][$codigo], $year, $spec->columnas),
					$spec->codigos,
				);
				$fonasa = $indicador['base'] === GoalSeed::BASE_POPULATION && !($counted['indicadores'][$i]['poblacion']['comprometida'] ?? false);
				$ruta = [
					'numerador' => self::ruta($moved[$year][$i]['numerador'], $fuentes, $label),
					'denominador' => $fonasa ? self::population($fuentes[Sources::PIV]) : self::ruta($moved[$year][$i]['denominador'], $fuentes, $label),
				];
				$historico[] = self::year($counted['indicadores'][$i], $counted['carried'][$i], $year, $comparable, $year < $this->window->currentYear(), $fuente, $ruta);
			}
			$cards[] = self::card($history[$current]['indicadores'][$i], $current, $history[$current]['fecha_corte_local'], $historico, $fuente);
		}

		return self::response($anio, $metas['nombre'], $metas['referencia'], $local->isConfigured() ? $local->deisCode : null, $cards);
	}

	/**
	 * The answer's shape, in one place, so ApiContractTest reads the served
	 * keys from it.
	 *
	 * @param list<Tarjeta> $metas
	 * @return Respuesta
	 */
	public static function response(int $anio, string $nombre, string $referencia, ?string $establecimiento, array $metas): array {
		return ['anio' => $anio, 'nombre' => $nombre, 'referencia' => $referencia, 'establecimiento' => $establecimiento, 'metas' => $metas];
	}

	/**
	 * A meta's card, from its months in the current year as GoalEvaluator
	 * counts them, and its histórico (year()).
	 *
	 * @param Meta $meta
	 * @param int $anio the current year
	 * @param ?array<string, ?string> $fechaCorteLocal the establishment's own last month (YYYY-MM) by fuente; null without one
	 * @param list<Historico> $historico
	 * @param ?Fuente $fuente the numerador's, as GET fuentes serves it
	 * @return Tarjeta
	 */
	public static function card(array $meta, int $anio, ?array $fechaCorteLocal, array $historico, ?array $fuente): array {
		$waiting = ($meta['numerador']['oficial'] ?? true) === false || ($meta['denominador']['oficial'] ?? true) === false;
		$last = null;
		foreach ($waiting ? [] : $meta['meses'] as $mes) {
			$last = $mes['porcentaje'] === null ? $last : $mes;
		}
		$porcentaje = $meta['manual'] ? $meta['cumplimiento'] : $last['porcentaje'] ?? null;

		return [
			'codigo' => $meta['codigo'],
			'nombre' => $meta['nombre'],
			'indicador' => $meta['indicador'],
			'meta_nacional' => $meta['meta_nacional'],
			'meta_comprometida' => $meta['meta_comprometida'],
			'manual' => $meta['manual'],
			'estado' => $waiting ? 'esperando ruta oficial' : self::missing($meta, $anio),
			'mes' => $last['mes'] ?? null,
			'datos_al' => $fechaCorteLocal[$meta['numerador']['fuente'] ?? ''] ?? null,
			'numerador' => $last['numerador'] ?? null,
			'denominador' => $last['denominador'] ?? null,
			'porcentaje' => $porcentaje,
			'cumple' => $porcentaje === null ? null : $porcentaje >= ($meta['meta_comprometida'] ?? $meta['meta_nacional']),
			'historico' => $waiting || $meta['manual'] ? [] : $historico,
			...self::origin($fuente, $last['mes'] ?? null),
		];
	}

	/**
	 * Where a month's figures come from (docs/adr/0010): its fuente's origen
	 * and the fecha de corte of that year's load; nothing without one.
	 * ponytail: a month a planilla covers is «planilla del establecimiento»
	 * with the file's date (#54).
	 *
	 * @param ?Fuente $fuente
	 * @return array{origen: ?string, fecha_corte: ?string}
	 */
	public static function origin(?array $fuente, ?string $mes): array {
		$load = $mes === null ? null : array_column($fuente['anios'] ?? [], null, 'anio')[(int)substr($mes, 0, 4)] ?? null;

		return ['origen' => $load === null ? null : ($fuente['origen'] ?? null), 'fecha_corte' => $load['fecha_corte'] ?? null];
	}

	/**
	 * A part of a meta's ruta as the source panel shows it: its fuente, named
	 * as GET fuentes names it, its periodo (YYYY-MM) and each consulta's
	 * códigos, labelled by $labels.
	 *
	 * @param ?Parte $part
	 * @param array<string, Fuente> $fuentes by id, as GET fuentes serves them
	 * @param Closure(QuerySpec): list<Etiqueta> $labels
	 * @return ?Ruta
	 */
	public static function ruta(?array $part, array $fuentes, Closure $labels): ?array {
		$month = static fn (int $yyyymm): string => sprintf('%04d-%02d', intdiv($yyyymm, 100), $yyyymm % 100);

		return $part === null ? null : [
			'fuente' => $part['fuente'],
			'nombre' => $fuentes[$part['fuente']]['nombre'],
			'desde' => $month($part['desde']),
			'hasta' => $month($part['hasta']),
			'sumar' => array_map($labels, $part['sumar']),
			'restar' => array_map($labels, $part['restar']),
		];
	}

	/**
	 * A denominador of FONASA's inscritos: no periodo, no códigos.
	 *
	 * @param Fuente $piv as GET fuentes serves it
	 * @return Ruta
	 */
	private static function population(array $piv): array {
		return ['fuente' => $piv['id'], 'nombre' => $piv['nombre'], 'desde' => null, 'hasta' => null, 'sumar' => [], 'restar' => []];
	}

	/**
	 * One year of a meta's histórico: nothing when it does not compare, else
	 * its months (Serie P's at its cortes, June and December, a carried one
	 * sin informar) and, when the year is over, its cierre.
	 *
	 * @param Meta $meta the newest metas' meta, counted over $anio
	 * @param Carried $carried the months its Serie P figures only carry an earlier corte (GoalEvaluator::history)
	 * @param ?Fuente $fuente the numerador's, for each month's origin()
	 * @param array{numerador: ?Ruta, denominador: ?Ruta} $ruta the year's, its periodos moved
	 * @return Historico
	 */
	public static function year(array $meta, array $carried, int $anio, bool $comparable, bool $over, ?array $fuente, array $ruta): array {
		if (!$comparable) {
			return ['anio' => $anio, 'estado' => self::NOT_COMPARABLE, 'cierre' => null, 'meses' => [], 'ruta' => $ruta];
		}

		$cortes = (Sources::REM[$meta['numerador']['fuente'] ?? ''] ?? null) === 'P';
		$meses = [];
		foreach ($meta['meses'] as $mes) {
			if (!$cortes || in_array(substr($mes['mes'], 5), ['06', '12'], true)) {
				$numerador = in_array($mes['mes'], $carried['numerador'], true) ? null : $mes['numerador'];
				$denominador = in_array($mes['mes'], $carried['denominador'], true) ? null : $mes['denominador'];
				$meses[] = ['mes' => $mes['mes'], 'numerador' => $numerador, 'denominador' => $denominador, 'porcentaje' => $numerador === null || $denominador === null ? null : $mes['porcentaje'], ...self::origin($fuente, $mes['mes'])];
			}
		}

		return [
			'anio' => $anio,
			'estado' => self::missing($meta, $anio),
			'cierre' => $over ? array_column($meses, 'porcentaje', 'mes')["$anio-12"] ?? null : null,
			'meses' => $meses,
			'ruta' => $ruta,
		];
	}

	/**
	 * Whether two labels read the same once case, accents and plurals are
	 * folded (docs/adr/0009): «EVALUACIONES INICIALES» is «Evaluación
	 * inicial». Case and accents by Text::fold, the es-CL rule (L0-03); then
	 * word by word, where one word may be the other plus «s» or «es», and
	 * everything between the words must match as folded.
	 */
	public static function sameLabel(string $a, string $b): bool {
		$words = static fn (string $label): array => preg_split('/(\P{L}+)/u', Text::fold($label), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
		[$x, $y] = [$words($a), $words($b)];
		if (count($x) !== count($y)) {
			return false;
		}
		foreach ($x as $i => $word) {
			if (!in_array($y[$i], [$word, $word . 's', $word . 'es'], true) && !in_array($word, [$y[$i] . 's', $y[$i] . 'es'], true)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a past year compares with the current one: each moved periodo
	 * of the ruta lies whole in the ventana, and every código × columna has
	 * the same label in both years' own diccionarios.
	 *
	 * @param list<QuerySpec> $terms the newest metas' ruta
	 * @param array<int, array<string, array<string, Label>>> $labels by year, serie and código
	 * @param int $shift the years from the metas' year to $year
	 */
	private static function comparable(array $terms, array $labels, int $year, int $current, int $shift, int $firstYear): bool {
		foreach ($terms as $spec) {
			if ($spec->desde + 100 * $shift <= $firstYear * 100) {
				return false;
			}
			foreach ($spec->codigos as $codigo) {
				foreach ($spec->columnas as $col) {
					$then = self::label($labels[$year][$spec->serie][$codigo] ?? null, $year, $col);
					$now = self::label($labels[$current][$spec->serie][$codigo] ?? null, $current, $col);
					if ($then === null || $now === null || !self::sameLabel($then, $now)) {
						return false;
					}
				}
			}
		}

		return true;
	}

	/**
	 * A código × columna's whole label in $year's own diccionario: its hoja,
	 * sección, prestación and column. Null when the year borrows it or names
	 * no such column.
	 *
	 * @param ?Label $label
	 */
	private static function label(?array $label, int $year, int $col): ?string {
		if ($label === null || $label['de'] !== $year || ($label['columnas'][$col] ?? '') === '') {
			return null;
		}
		$described = Catalog::describe($label, $year, $col);

		return implode(' › ', [$described['hoja'], $described['seccion'], $described['prestacion'], $described['columna']]);
	}

	/**
	 * Each year's labels of the códigos the metas' rutas name.
	 *
	 * @param Metas $metas
	 * @param list<int> $years
	 * @return array<int, array<string, array<string, Label>>> by year, serie and código
	 */
	private function labels(array $metas, array $years): array {
		$codigos = [];
		foreach ($metas['indicadores'] as $indicador) {
			foreach (GoalSeed::terms($indicador) as $spec) {
				$codigos[$spec->serie] = [...$codigos[$spec->serie] ?? [], ...$spec->codigos];
			}
		}

		$labels = [];
		foreach ($years as $year) {
			foreach ($codigos as $serie => $list) {
				$labels[$year][$serie] = $this->catalog->labels($year, $serie, array_values(array_unique($list)));
			}
		}

		return $labels;
	}

	/** @param Meta $meta */
	private static function missing(array $meta, int $anio): ?string {
		return ($meta['poblacion']['falta_oficial'] ?? false) ? sprintf('falta el denominador oficial de %d', $anio) : null;
	}
}
