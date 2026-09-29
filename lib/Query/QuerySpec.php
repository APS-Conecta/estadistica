<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Query;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\RemReader;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;

/**
 * One consulta: the question the builder, the tableros, the metas view, the
 * OCS API and M3's AI all ask in the same words (docs/adr/0006).
 *
 *     {
 *       "fuente":   "rem-a",
 *       "periodo":  {"desde": "2025-10", "hasta": "2026-09"},
 *       "filtros":  {"hoja": "A03", "seccion": "…", "codigos": ["02010420"], "columnas": ["col08", "col09"]},
 *       "agrupar":  ["mes"],
 *       "metrica":  "promedio_establecimiento",
 *       "comparar": {"nacional": true, "pares": {"tipo": true, "servicio": "…"}}
 *     }
 *
 * - fuente is a fuente id from GET fuentes (rem-a, rem-p). It is the
 *   discriminator M2's file fuentes extend (M2 contract notes).
 * - periodo is a range of months, which is how Meta I's October-to-September
 *   window crosses a year. It is required, because a consulta over every year
 *   scans the whole table.
 * - filtros must say what is summed: a hoja (optionally one of its
 *   secciones) or códigos, and the columns. Each REM column counts something
 *   different (an age band, a sex, a total), so none is assumed.
 * - agrupar takes at most two DIMENSIONS. mes is the periodo's month
 *   (YYYY-MM), so a cross-year series stays in order.
 * - metrica is suma, or promedio_establecimiento: the suma ÷ the
 *   establishments that reported (a7). It defaults to suma.
 * - comparar adds the national series and a peer group's. A peer trait is a
 *   value from the Base de Establecimientos, or true for the local
 *   establishment's own.
 *
 * from() is the only way in. It refuses in Spanish whatever it does not
 * understand, so nothing after it sees an unknown key, a column outside
 * col01…col50 or an aggregate that is not one of two (a16). The keys are
 * Spanish because the spec is the public contract (Naming). Once preguntas
 * store specs (Slice 7), no key is renamed or repurposed: later milestones
 * only add.
 *
 * It is pure (no Nextcloud class), so the rules are unit-tested in bare PHP.
 */
final class QuerySpec {
	public const METRICS = ['suma', 'promedio_establecimiento'];

	/**
	 * The establishment traits a peer group is chosen by, and a series is
	 * grouped by. They are estadistica_establishment's own column names.
	 */
	public const TRAITS = ['tipo', 'dependencia', 'nivel', 'servicio', 'region', 'comuna'];

	public const DIMENSIONS = ['anio', 'mes', 'codigo', 'columna', ...self::TRAITS];

	public const MAX_DIMENSIONS = 2;

	/** A whole hoja is ≈ 200 códigos. */
	public const MAX_CODES = 1000;

	/** RemReader's charset for a código: what can be stored can be asked for. */
	private const CODE = '/^[0-9A-Za-z._-]{1,16}$/';

	/**
	 * @param int $desde the first month, YYYYMM
	 * @param int $hasta the last month, YYYYMM
	 * @param list<string> $codigos
	 * @param list<int> $columnas 1–50, ascending
	 * @param list<string> $agrupar
	 * @param array<string, string|true> $pares trait → value, in TRAITS order
	 */
	private function __construct(
		public readonly string $fuente,
		public readonly string $serie,
		public readonly int $desde,
		public readonly int $hasta,
		public readonly ?string $hoja,
		public readonly ?string $seccion,
		public readonly array $codigos,
		public readonly array $columnas,
		public readonly array $agrupar,
		public readonly string $metrica,
		public readonly bool $nacional,
		public readonly array $pares,
	) {
	}

	/**
	 * @param array<array-key, mixed> $raw the consulta as it arrived: a decoded JSON object
	 * @throws RefusedException
	 */
	public static function from(array $raw, int $currentYear): self {
		self::keys($raw, ['fuente', 'periodo', 'filtros', 'agrupar', 'metrica', 'comparar'], 'La consulta');

		$fuente = $raw['fuente'] ?? null;
		if (!is_string($fuente) || !isset(Sources::REM[$fuente])) {
			throw new RefusedException(sprintf('La fuente de una consulta es %s.', self::either(array_keys(Sources::REM))));
		}

		$periodo = $raw['periodo'] ?? null;
		if (!is_array($periodo)) {
			throw new RefusedException('La consulta debe decir su periodo: desde y hasta, cada uno como AAAA-MM.');
		}
		self::keys($periodo, ['desde', 'hasta'], 'periodo');
		$desde = self::month($periodo['desde'] ?? null, $currentYear);
		$hasta = self::month($periodo['hasta'] ?? null, $currentYear);
		if ($desde > $hasta) {
			throw new RefusedException('El periodo termina antes de empezar: desde debe ser anterior o igual a hasta.');
		}

		$filtros = $raw['filtros'] ?? [];
		if (!is_array($filtros)) {
			throw new RefusedException('filtros es un objeto con hoja, seccion, codigos y columnas.');
		}
		self::keys($filtros, ['hoja', 'seccion', 'codigos', 'columnas'], 'filtros');
		$hoja = self::name($filtros['hoja'] ?? null, 16, 'La hoja es su nombre en el REM, como A03.');
		$seccion = self::name($filtros['seccion'] ?? null, 500, 'La sección es su nombre, tal como lo da el catálogo de la fuente.');
		if ($seccion !== null && $hoja === null) {
			throw new RefusedException('Una sección se elige dentro de su hoja: falta la hoja.');
		}
		$codigos = self::codes($filtros['codigos'] ?? []);
		if ($hoja === null && $codigos === []) {
			throw new RefusedException('Elija una hoja o al menos una prestación: sumar toda la serie mezcla cifras que no se suman.');
		}

		$metrica = $raw['metrica'] ?? 'suma';
		if (!in_array($metrica, self::METRICS, true)) {
			throw new RefusedException(sprintf('La métrica %s no existe: es %s.', self::quote($metrica), self::either(self::METRICS)));
		}

		[$nacional, $pares] = self::comparison($raw['comparar'] ?? []);

		return new self(
			$fuente,
			Sources::REM[$fuente],
			$desde,
			$hasta,
			$hoja,
			$seccion,
			$codigos,
			self::columns($filtros['columnas'] ?? []),
			self::dimensions($raw['agrupar'] ?? []),
			$metrica,
			$nacional,
			$pares,
		);
	}

	public function firstYear(): int {
		return intdiv($this->desde, 100);
	}

	public function lastYear(): int {
		return intdiv($this->hasta, 100);
	}

	/**
	 * @param array<array-key, mixed> $object
	 * @param list<string> $allowed
	 * @throws RefusedException
	 */
	private static function keys(array $object, array $allowed, string $what): void {
		foreach (array_keys($object) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new RefusedException(sprintf('%s no admite «%s»: admite %s.', $what, self::clip((string)$key), self::either($allowed)));
			}
		}
	}

	/** @throws RefusedException */
	private static function month(mixed $value, int $currentYear): int {
		if (!is_string($value) || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $match) !== 1) {
			throw new RefusedException('El periodo va de desde a hasta, cada uno como AAAA-MM (por ejemplo 2026-01).');
		}
		$anio = (int)$match[1];
		if ($anio < YearWindow::EARLIEST || $anio > $currentYear) {
			throw new RefusedException(sprintf('El año debe estar entre %d y %d.', YearWindow::EARLIEST, $currentYear));
		}

		return $anio * 100 + (int)$match[2];
	}

	/** @throws RefusedException */
	private static function name(mixed $value, int $max, string $refusal): ?string {
		if ($value === null) {
			return null;
		}
		if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
			throw new RefusedException($refusal);
		}

		return trim($value);
	}

	/**
	 * @return list<string>
	 * @throws RefusedException
	 */
	private static function codes(mixed $value): array {
		if (!is_array($value) || !array_is_list($value)) {
			throw new RefusedException('Las prestaciones (codigos) son una lista de códigos, como ["02010420"].');
		}
		if (count($value) > self::MAX_CODES) {
			throw new RefusedException(sprintf('Una consulta nombra hasta %s prestaciones.', number_format(self::MAX_CODES, 0, ',', '.')));
		}
		foreach ($value as $codigo) {
			if (!is_string($codigo) || preg_match(self::CODE, $codigo) !== 1) {
				throw new RefusedException(sprintf('%s no es un código de prestación: los códigos van entre comillas, como "02010420".', self::quote($codigo)));
			}
		}

		return array_values(array_unique($value));
	}

	/**
	 * @return list<int>
	 * @throws RefusedException
	 */
	private static function columns(mixed $value): array {
		if (!is_array($value) || !array_is_list($value) || $value === []) {
			throw new RefusedException(sprintf('Elija al menos una columna, de col01 a col%02d: cada columna del REM cuenta algo distinto.', RemReader::COLUMNS));
		}
		$columns = [];
		foreach ($value as $column) {
			if (!is_string($column) || preg_match('/^col(\d{2})$/', $column, $match) !== 1 || (int)$match[1] < 1 || (int)$match[1] > RemReader::COLUMNS) {
				throw new RefusedException(sprintf('%s no es una columna del REM: van de col01 a col%02d.', self::quote($column), RemReader::COLUMNS));
			}
			$columns[(int)$match[1]] = (int)$match[1];
		}
		ksort($columns);

		return array_values($columns);
	}

	/**
	 * @return list<string>
	 * @throws RefusedException
	 */
	private static function dimensions(mixed $value): array {
		if (!is_array($value) || !array_is_list($value)) {
			throw new RefusedException('agrupar es una lista de dimensiones, como ["mes"].');
		}
		foreach ($value as $dimension) {
			if (!in_array($dimension, self::DIMENSIONS, true)) {
				throw new RefusedException(sprintf('No se puede agrupar por %s: se agrupa por %s.', self::quote($dimension), self::either(self::DIMENSIONS)));
			}
		}
		if (count($value) !== count(array_unique($value))) {
			throw new RefusedException('La consulta agrupa dos veces por lo mismo.');
		}
		if (count($value) > self::MAX_DIMENSIONS) {
			throw new RefusedException(sprintf('Se agrupa por %d dimensiones como máximo.', self::MAX_DIMENSIONS));
		}

		return $value;
	}

	/**
	 * @return array{bool, array<string, string|true>}
	 * @throws RefusedException
	 */
	private static function comparison(mixed $value): array {
		if (!is_array($value)) {
			throw new RefusedException('comparar es un objeto con nacional y pares.');
		}
		self::keys($value, ['nacional', 'pares'], 'comparar');

		$nacional = $value['nacional'] ?? false;
		if (!is_bool($nacional)) {
			throw new RefusedException('comparar.nacional es true o false.');
		}

		$pares = $value['pares'] ?? null;
		if ($pares === null) {
			return [$nacional, []];
		}
		if (!is_array($pares) || $pares === []) {
			throw new RefusedException(sprintf('Elija en qué se parecen los pares: %s.', self::either(self::TRAITS)));
		}
		self::keys($pares, self::TRAITS, 'comparar.pares');

		$chosen = [];
		foreach (self::TRAITS as $trait) {
			if (!array_key_exists($trait, $pares)) {
				continue;
			}
			$like = $pares[$trait];
			if ($like !== true && (!is_string($like) || trim($like) === '' || mb_strlen($like) > 200)) {
				throw new RefusedException(sprintf('pares.%s es un valor de la Base de Establecimientos, o true para el del propio establecimiento.', $trait));
			}
			$chosen[$trait] = $like === true ? true : trim($like);
		}

		return [$nacional, $chosen];
	}

	/** @param list<string> $options */
	private static function either(array $options): string {
		$last = array_pop($options);

		return $options === [] ? (string)$last : implode(', ', $options) . ' o ' . $last;
	}

	/** Whatever arrived, as a short printable quote: a refusal names it without echoing a page of it back. */
	private static function quote(mixed $value): string {
		return '«' . self::clip(is_string($value) ? $value : (string)json_encode($value)) . '»';
	}

	private static function clip(string $text): string {
		return mb_strlen($text) > 40 ? mb_substr($text, 0, 40) . '…' : $text;
	}
}
