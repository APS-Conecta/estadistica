<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The Resumen (docs/adr/0009): the view that opens first. One card per meta
 * of the newest metas seed, for one establishment: the percentage and «N de
 * D» of the meta's last month with a figure, and whether it cumple.
 *
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
 *   no denominador comprometido is typed (GoalEvaluator's falta_oficial).
 *
 * Built here, the one way; ResumenStore keeps the configured
 * establishment's once per change. ponytail: the past years are their own
 * ticket (#48).
 *
 * @psalm-import-type Meta from GoalEvaluator
 * @psalm-type Tarjeta = array{codigo: string, nombre: string, indicador: string, meta_nacional: float, meta_comprometida: ?float, manual: bool, estado: ?string, mes: ?string, datos_al: ?string, numerador: ?float, denominador: ?float, porcentaje: ?float, cumple: ?bool}
 * @psalm-type Respuesta = array{anio: int, nombre: string, establecimiento: ?string, metas: list<Tarjeta>}
 */
class Resumen {
	public function __construct(
		private Goals $goals,
		private GoalEvaluator $evaluator,
	) {
	}

	/**
	 * @return Respuesta
	 * @throws DoesNotExistException no metas are loaded
	 * @throws RefusedException
	 */
	public function build(Establishment $local): array {
		$answer = $this->evaluator->evaluate($this->goals->years()[0] ?? null, $local, [], false);

		return self::response($answer['anio'], $answer['nombre'], $answer['establecimiento'], array_map(
			static fn (array $meta): array => self::card($meta, $answer['anio'], $answer['fecha_corte_local']),
			$answer['indicadores'],
		));
	}

	/**
	 * The answer's shape, in one place, so ApiContractTest reads the served
	 * keys from it.
	 *
	 * @param list<Tarjeta> $metas
	 * @return Respuesta
	 */
	public static function response(int $anio, string $nombre, ?string $establecimiento, array $metas): array {
		return ['anio' => $anio, 'nombre' => $nombre, 'establecimiento' => $establecimiento, 'metas' => $metas];
	}

	/**
	 * A meta's card, from its months as GoalEvaluator counts them.
	 *
	 * @param Meta $meta
	 * @param ?array<string, ?string> $fechaCorteLocal the establishment's own last month (YYYY-MM) by fuente; null without one
	 * @return Tarjeta
	 */
	public static function card(array $meta, int $anio, ?array $fechaCorteLocal): array {
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
			'estado' => match (true) {
				$waiting => 'esperando ruta oficial',
				$meta['poblacion']['falta_oficial'] ?? false => sprintf('falta el denominador oficial de %d', $anio),
				default => null,
			},
			'mes' => $last['mes'] ?? null,
			'datos_al' => $fechaCorteLocal[$meta['numerador']['fuente'] ?? ''] ?? null,
			'numerador' => $last['numerador'] ?? null,
			'denominador' => $last['denominador'] ?? null,
			'porcentaje' => $porcentaje,
			'cumple' => $porcentaje === null ? null : $porcentaje >= ($meta['meta_comprometida'] ?? $meta['meta_nacional']),
		];
	}
}
