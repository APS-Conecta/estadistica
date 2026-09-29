<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;

/**
 * One year's Metas Sanitarias as its seed file writes them (acceptance a9,
 * docs/adr/0008). The metas are data, not code: the app ships 2026's
 * (seeds/metas-2026.json), and a later year arrives as another file through
 * the admin section's door, with no release.
 *
 *     {
 *       "anio": 2026, "version": 1, "nombre": "…", "referencia": "…", "nota": "…",
 *       "diccionarios": {"rem-a": "SA_25_V1.5", "rem-p": "SP_25_V1.2"},
 *       "indicadores": [
 *         {"codigo": "I", "nombre": "…", "indicador": "…", "meta_nacional": 90, "peso": 12.5, "nota": "…",
 *          "numerador":   {"fuente": "rem-a", "periodo": {"desde": "2026-01", "hasta": "2026-12"},
 *                          "sumar": [{"codigos": ["02010420", "03500366"], "columnas": ["col08", "col09"]}]},
 *          "denominador": {"fuente": "rem-a", "periodo": {"desde": "2025-10", "hasta": "2026-09"},
 *                          "sumar": [{…}], "restar": [{…}]}},
 *         {"codigo": "II", …, "denominador": {"poblacion": [{"desde": 25, "hasta": 64, "sexo": "mujeres"}]}},
 *         {"codigo": "VIII", …, "manual": true}
 *       ]
 *     }
 *
 * - A numerador is REM: one fuente, a periodo that ends in the metas' year,
 *   and the códigos × columnas to add (sumar) and, where the OOTT takes some
 *   away, to subtract (restar: Meta I's denominator). Each is one consulta,
 *   judged by QuerySpec::from() as POST consultas judges one, so a seed can
 *   name nothing a consulta could not (a16).
 * - A denominador is REM the same way; or FONASA's población inscrita as age
 *   groups, each of one sex or both, with a prevalence in percent where the
 *   OOTT estimates the sick among the inscritos (IV.A, V, VII); or
 *   {"comprometido": true}, a figure only the establishment's commitment gives.
 * - A manual meta (VIII) has neither: an admin types its cumplimiento.
 * - Percentages are written as percentages: meta_nacional 90 is 90 %, peso
 *   12.5 is 12.5 %, prevalencia 1.8 is 1.8 %.
 *
 * parse() is the only way in. It refuses in Spanish whatever it does not
 * understand, an unknown key included, and names the meta. It is pure.
 *
 * @psalm-type Parte = array{fuente: string, serie: string, desde: int, hasta: int, sumar: non-empty-list<QuerySpec>, restar: list<QuerySpec>}
 * @psalm-type Grupo = array{desde: int, hasta: ?int, sexo: ?string, prevalencia: float}
 * @psalm-type Indicador = array{codigo: string, nombre: string, indicador: string, meta_nacional: float, peso: float, nota: ?string, manual: bool, base: ?string, numerador: ?Parte, denominador: ?Parte, poblacion: list<Grupo>, ruta: array{numerador: ?array<string, mixed>, denominador: ?array<string, mixed>}}
 * @psalm-type Metas = array{anio: int, version: int, nombre: string, referencia: string, nota: ?string, diccionarios: array<string, string>, indicadores: non-empty-list<Indicador>}
 */
final class GoalSeed {
	/** What a denominador counts: REM figures, FONASA's inscritos, or only what the establishment commits. */
	public const BASE_REM = 'rem';

	public const BASE_POPULATION = 'poblacion';

	public const BASE_COMMITTED = 'comprometido';

	/** The seed's sexes, as Population counts them. */
	public const SEXES = ['mujeres' => 'M', 'hombres' => 'H'];

	public const MAX_INDICATORS = 30;

	private const MAX_GROUPS = 10;

	private const CODE = '/^[0-9A-Za-z.]{1,8}$/';

	/**
	 * @param array<array-key, mixed> $raw the file, decoded
	 * @return Metas
	 * @throws RefusedException
	 */
	public static function parse(array $raw, int $currentYear): array {
		self::keys($raw, ['anio', 'version', 'nombre', 'referencia', 'nota', 'diccionarios', 'indicadores'], 'El archivo de metas');

		$anio = $raw['anio'] ?? null;
		// Next year's metas are fixed in October (OOTT), so they may arrive early.
		if (!is_int($anio) || $anio < YearWindow::EARLIEST || $anio > $currentYear + 1) {
			throw new RefusedException(sprintf('El año de las metas (anio) debe estar entre %d y %d.', YearWindow::EARLIEST, $currentYear + 1));
		}
		$version = $raw['version'] ?? null;
		if (!is_int($version) || $version < 1) {
			throw new RefusedException('La versión del archivo de metas (version) es un número entero desde 1: una versión mayor reemplaza a la cargada.');
		}

		$diccionarios = $raw['diccionarios'] ?? null;
		if (!is_array($diccionarios) || $diccionarios === [] || array_is_list($diccionarios)) {
			throw new RefusedException('diccionarios dice qué diccionario del REM citan las rutas, por fuente: {"rem-a": "SA_25_V1.5", "rem-p": "SP_25_V1.2"}.');
		}
		self::keys($diccionarios, array_keys(Sources::REM), 'diccionarios');
		foreach ($diccionarios as $fuente => $name) {
			$diccionarios[$fuente] = self::text($name, 64, sprintf('diccionarios.%s es el nombre de un diccionario, como SA_25_V1.5.', $fuente));
		}

		$list = $raw['indicadores'] ?? null;
		if (!is_array($list) || !array_is_list($list) || $list === [] || count($list) > self::MAX_INDICATORS) {
			throw new RefusedException(sprintf('indicadores es la lista de las metas del año, de 1 a %d.', self::MAX_INDICATORS));
		}
		// The consultas a seed names are judged as of its own year, which may be next year.
		$year = max($currentYear, $anio);
		$indicadores = [];
		foreach ($list as $item) {
			$indicador = self::indicator($item, $anio, $year);
			if (isset($indicadores[$indicador['codigo']])) {
				throw new RefusedException(sprintf('La Meta %s viene dos veces.', $indicador['codigo']));
			}
			$indicadores[$indicador['codigo']] = $indicador;
		}

		return [
			'anio' => $anio,
			'version' => $version,
			'nombre' => self::text($raw['nombre'] ?? null, 200, 'nombre es el título de las metas, como «Metas Sanitarias 2026».'),
			'referencia' => self::text($raw['referencia'] ?? null, 500, 'referencia dice de qué documento salen las metas y sus rutas.'),
			'nota' => self::note($raw['nota'] ?? null, 'nota'),
			'diccionarios' => $diccionarios,
			'indicadores' => array_values($indicadores),
		];
	}

	/**
	 * @return Indicador
	 * @throws RefusedException
	 */
	private static function indicator(mixed $raw, int $anio, int $year): array {
		if (!is_array($raw) || array_is_list($raw)) {
			throw new RefusedException('Cada indicador es un objeto con codigo, nombre, indicador, meta_nacional, peso y sus rutas.');
		}
		$codigo = $raw['codigo'] ?? null;
		if (!is_string($codigo) || preg_match(self::CODE, $codigo) !== 1) {
			throw new RefusedException('El codigo de un indicador es el número de la meta, como "I" o "III.A".');
		}
		$meta = 'Meta ' . $codigo;
		self::keys($raw, ['codigo', 'nombre', 'indicador', 'meta_nacional', 'peso', 'nota', 'numerador', 'denominador', 'manual'], $meta);

		$manual = $raw['manual'] ?? false;
		if (!is_bool($manual)) {
			throw new RefusedException(sprintf('%s: manual es true o false.', $meta));
		}

		$indicador = [
			'codigo' => $codigo,
			'nombre' => self::text($raw['nombre'] ?? null, 200, sprintf('%s: nombre es su título corto.', $meta)),
			'indicador' => self::text($raw['indicador'] ?? null, 500, sprintf('%s: indicador es el nombre del indicador, como lo escriben las orientaciones.', $meta)),
			'meta_nacional' => self::percent($raw['meta_nacional'] ?? null, sprintf('%s: meta_nacional', $meta)),
			'peso' => self::percent($raw['peso'] ?? null, sprintf('%s: peso', $meta)),
			'nota' => self::note($raw['nota'] ?? null, sprintf('%s: nota', $meta)),
			'manual' => $manual,
			'base' => null,
			'numerador' => null,
			'denominador' => null,
			'poblacion' => [],
			'ruta' => ['numerador' => null, 'denominador' => null],
		];

		if ($manual) {
			if (array_key_exists('numerador', $raw) || array_key_exists('denominador', $raw)) {
				throw new RefusedException(sprintf('%s es manual: su cumplimiento se escribe a mano, sin numerador ni denominador.', $meta));
			}

			return $indicador;
		}

		$numerador = $raw['numerador'] ?? null;
		$denominador = $raw['denominador'] ?? null;
		if (!is_array($numerador) || !is_array($denominador)) {
			throw new RefusedException(sprintf('%s necesita numerador y denominador, o "manual": true.', $meta));
		}
		$indicador['numerador'] = self::part($numerador, $anio, $year, $meta . ', numerador');
		$indicador['ruta'] = ['numerador' => $numerador, 'denominador' => $denominador];

		if (array_key_exists('poblacion', $denominador)) {
			self::keys($denominador, ['poblacion'], $meta . ', denominador');
			$indicador['base'] = self::BASE_POPULATION;
			$indicador['poblacion'] = self::groups($denominador['poblacion'], $meta . ', denominador');
		} elseif (array_key_exists('comprometido', $denominador)) {
			self::keys($denominador, ['comprometido'], $meta . ', denominador');
			if ($denominador['comprometido'] !== true) {
				throw new RefusedException(sprintf('%s, denominador: "comprometido" solo puede ser true, y entonces lo da el compromiso del establecimiento.', $meta));
			}
			$indicador['base'] = self::BASE_COMMITTED;
		} else {
			$indicador['base'] = self::BASE_REM;
			$indicador['denominador'] = self::part($denominador, $anio, $year, $meta . ', denominador');
		}

		return $indicador;
	}

	/**
	 * @param array<array-key, mixed> $raw
	 * @return Parte
	 * @throws RefusedException
	 */
	private static function part(array $raw, int $anio, int $year, string $what): array {
		self::keys($raw, ['fuente', 'periodo', 'sumar', 'restar'], $what);
		$sumar = $raw['sumar'] ?? null;
		$restar = $raw['restar'] ?? [];
		if (!is_array($sumar) || !array_is_list($sumar) || $sumar === [] || !is_array($restar) || !array_is_list($restar)) {
			throw new RefusedException(sprintf('%s: sumar es una lista de {codigos, columnas}, y restar otra, si hace falta.', $what));
		}

		$specs = [];
		foreach (['sumar' => $sumar, 'restar' => $restar] as $sign => $terms) {
			$specs[$sign] = [];
			foreach ($terms as $term) {
				if (!is_array($term) || array_is_list($term)) {
					throw new RefusedException(sprintf('%s: cada término de %s es {codigos, columnas}.', $what, $sign));
				}
				self::keys($term, ['codigos', 'columnas'], sprintf('%s, %s', $what, $sign));
				try {
					$specs[$sign][] = QuerySpec::from([
						'fuente' => $raw['fuente'] ?? null,
						'periodo' => $raw['periodo'] ?? null,
						'filtros' => ['codigos' => $term['codigos'] ?? [], 'columnas' => $term['columnas'] ?? []],
						'agrupar' => ['mes'],
					], $year);
				} catch (RefusedException $e) {
					throw new RefusedException(sprintf('%s: %s', $what, $e->getMessage()), 0, $e);
				}
			}
		}

		$first = $specs['sumar'][0];
		if ($first->lastYear() !== $anio) {
			throw new RefusedException(sprintf('%s: el periodo debe terminar en %d, el año de las metas.', $what, $anio));
		}

		return [
			'fuente' => $first->fuente,
			'serie' => $first->serie,
			'desde' => $first->desde,
			'hasta' => $first->hasta,
			'sumar' => $specs['sumar'],
			'restar' => $specs['restar'],
		];
	}

	/**
	 * @return list<Grupo>
	 * @throws RefusedException
	 */
	private static function groups(mixed $raw, string $what): array {
		if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_GROUPS) {
			throw new RefusedException(sprintf('%s: poblacion es una lista de 1 a %d grupos de edad, como {"desde": 25, "hasta": 64, "sexo": "mujeres"}.', $what, self::MAX_GROUPS));
		}

		$groups = [];
		foreach ($raw as $group) {
			if (!is_array($group) || array_is_list($group)) {
				throw new RefusedException(sprintf('%s: cada grupo es {desde, hasta, sexo, prevalencia}.', $what));
			}
			self::keys($group, ['desde', 'hasta', 'sexo', 'prevalencia'], $what);
			$desde = $group['desde'] ?? null;
			$hasta = $group['hasta'] ?? null;
			if (!is_int($desde) || $desde < 0 || $desde > 120 || ($hasta !== null && (!is_int($hasta) || $hasta < $desde || $hasta > 120))) {
				throw new RefusedException(sprintf('%s: un grupo va desde una edad hasta otra igual o mayor, en años de 0 a 120; sin hasta, no tiene tope.', $what));
			}
			$sexo = $group['sexo'] ?? null;
			if ($sexo !== null && !isset(self::SEXES[$sexo])) {
				throw new RefusedException(sprintf('%s: sexo es "mujeres" o "hombres"; sin sexo, cuentan todos.', $what));
			}
			$prevalencia = $group['prevalencia'] ?? 100;
			if ((!is_int($prevalencia) && !is_float($prevalencia)) || $prevalencia <= 0 || $prevalencia > 100) {
				throw new RefusedException(sprintf('%s: prevalencia es un porcentaje mayor que 0 y hasta 100, como 1.8.', $what));
			}
			$groups[] = ['desde' => $desde, 'hasta' => $hasta, 'sexo' => $sexo === null ? null : self::SEXES[$sexo], 'prevalencia' => (float)$prevalencia];
		}

		return $groups;
	}

	/** @throws RefusedException */
	private static function percent(mixed $value, string $what): float {
		if ((!is_int($value) && !is_float($value)) || $value < 0 || $value > 100) {
			throw new RefusedException(sprintf('%s es un porcentaje de 0 a 100, como 90 o 12.5.', $what));
		}

		return (float)$value;
	}

	/** @throws RefusedException */
	private static function text(mixed $value, int $max, string $refusal): string {
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
			throw new RefusedException($refusal);
		}

		return trim($value);
	}

	/** @throws RefusedException */
	private static function note(mixed $value, string $what): ?string {
		return $value === null ? null : self::text($value, 500, sprintf('%s es un texto de hasta 500 caracteres.', $what));
	}

	/**
	 * QuerySpec's rule, with the seed's words: an unknown key is refused,
	 * never ignored (a16).
	 *
	 * @param array<array-key, mixed> $object
	 * @param list<string> $allowed
	 * @throws RefusedException
	 */
	private static function keys(array $object, array $allowed, string $what): void {
		foreach (array_keys($object) as $key) {
			if (!in_array($key, $allowed, true)) {
				$shown = mb_strlen((string)$key) > 40 ? mb_substr((string)$key, 0, 40) . '…' : (string)$key;
				throw new RefusedException(sprintf('%s no admite «%s»: admite %s.', $what, $shown, implode(', ', $allowed)));
			}
		}
	}
}
