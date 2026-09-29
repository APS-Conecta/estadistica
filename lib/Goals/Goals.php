<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use JsonException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Throwable;

/**
 * The metas' one writer, and what the admin section and the metas view read
 * back (acceptance a9, a10, a12; docs/adr/0008). Two things write here:
 *
 * - A year's seed file: the one the app ships (EnsureSeedData), or one an
 *   admin picks in Files through the drop door (DroppedFiles). It replaces
 *   that year's metas whole, and is kept as it was sent, once GoalSeed
 *   accepts it.
 * - This install's establishment's commitments for a year, typed in the admin
 *   section: each meta's meta comprometida, the denominador comprometido of a
 *   meta counted over people, and the cumplimiento of a manual meta (VIII).
 *   They replace what was typed before for that year. A new seed for the year
 *   leaves them alone: they name their meta by codigo.
 *
 * @psalm-import-type Metas from GoalSeed
 * @psalm-type Commitment = array{meta: ?float, denominador: ?float, cumplimiento: ?float}
 */
class Goals {
	/** A seed is a few KB. This stops a mistaken pick before it is decoded. */
	public const MAX_BYTES = 1024 * 1024;

	public function __construct(
		private IDBConnection $db,
		private EstablishmentConfig $establishment,
		private YearWindow $window,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Loads a seed file from a local path (the drop door).
	 *
	 * @return array{anio: int, version: int, indicadores: int}
	 * @throws RefusedException
	 */
	public function import(string $local): array {
		$size = filesize($local);
		if ($size === false || $size > self::MAX_BYTES) {
			throw new RefusedException('Un archivo de metas pesa unos pocos KB, y este pesa más de 1 MB: no es uno.');
		}
		// Bounded above: a seed is read whole, as JSON is.
		$text = (string)file_get_contents($local);
		try {
			$raw = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new RefusedException('El archivo de metas no es un JSON válido: revise las comas, las comillas y las llaves. Nada se guardó.');
		}
		if (!is_array($raw)) {
			throw new RefusedException('Un archivo de metas es un objeto JSON con anio, version, nombre e indicadores. Nada se guardó.');
		}

		return $this->store($raw);
	}

	/**
	 * Replaces a year's metas with $raw, once GoalSeed accepts it.
	 *
	 * @param array<array-key, mixed> $raw the file, decoded
	 * @return array{anio: int, version: int, indicadores: int}
	 * @throws RefusedException
	 */
	public function store(array $raw): array {
		$metas = GoalSeed::parse($raw, $this->window->currentYear());

		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_goal WHERE anio = ?', [$metas['anio']]);
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_goal (anio, version, document, loaded_at) VALUES (?, ?, ?, ?)',
				[$metas['anio'], $metas['version'], json_encode($raw, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), $this->time->getTime()],
			);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return ['anio' => $metas['anio'], 'version' => $metas['version'], 'indicadores' => count($metas['indicadores'])];
	}

	/** The version of $anio's stored metas, or null when there are none. */
	public function version(int $anio): ?int {
		$result = $this->db->executeQuery('SELECT version FROM *PREFIX*estadistica_goal WHERE anio = ?', [$anio]);
		$version = $result->fetchOne();
		$result->closeCursor();

		return $version === false ? null : (int)$version;
	}

	/** @return list<int> every year with metas, newest first */
	public function years(): array {
		$result = $this->db->executeQuery('SELECT anio FROM *PREFIX*estadistica_goal ORDER BY anio DESC');
		$years = array_map('intval', $result->fetchFirstColumn());
		$result->closeCursor();

		return $years;
	}

	/**
	 * $anio's metas, judged again as they are read (docs/adr/0008), or null
	 * when the year has none.
	 *
	 * @return ?Metas
	 * @throws RefusedException
	 */
	public function get(int $anio): ?array {
		$result = $this->db->executeQuery('SELECT document FROM *PREFIX*estadistica_goal WHERE anio = ?', [$anio]);
		$document = $result->fetchOne();
		$result->closeCursor();

		return $document === false ? null : $this->parse((string)$document);
	}

	/**
	 * An establishment's commitments for $anio, by the meta's codigo.
	 *
	 * @return array<string, Commitment>
	 */
	public function commitments(int $anio, int $estab): array {
		$result = $this->db->executeQuery(
			'SELECT codigo, meta, denominador, cumplimiento FROM *PREFIX*estadistica_commitment WHERE anio = ? AND estab = ? ORDER BY codigo',
			[$anio, $estab],
		);
		$commitments = [];
		while (($row = $result->fetch()) !== false) {
			$commitments[(string)$row['codigo']] = self::commitment($row);
		}
		$result->closeCursor();

		return $commitments;
	}

	/**
	 * This install's establishment's commitments for $anio, as an admin typed
	 * them. They replace every commitment typed before for that year; a meta
	 * left out has none. Refused while no establishment is configured: the
	 * commitments are its own.
	 *
	 * @param list<mixed> $commitments each {codigo, meta?, denominador?, cumplimiento?}
	 * @throws RefusedException
	 */
	public function commit(int $anio, array $commitments): void {
		$establishment = $this->establishment->get();
		if (!$establishment->isConfigured()) {
			throw new RefusedException('Todavía no se ha configurado el establecimiento de esta instalación, así que no hay metas propias que comprometer.');
		}
		$metas = $this->get($anio);
		if ($metas === null) {
			throw new RefusedException(sprintf('No hay Metas Sanitarias cargadas para %d.', $anio));
		}

		$byCode = array_column($metas['indicadores'], null, 'codigo');
		$rows = [];
		foreach ($commitments as $item) {
			$codigo = is_array($item) ? ($item['codigo'] ?? null) : null;
			if (!is_string($codigo) || !isset($byCode[$codigo])) {
				throw new RefusedException(sprintf('Cada compromiso nombra una meta de %d: %s.', $anio, implode(', ', array_keys($byCode))));
			}
			if (isset($rows[$codigo])) {
				throw new RefusedException(sprintf('El compromiso de la Meta %s viene dos veces.', $codigo));
			}
			$meta = $byCode[$codigo];
			$row = [
				'codigo' => $codigo,
				'meta' => self::number($item['meta'] ?? null, 100, sprintf('La meta comprometida de la Meta %s es un porcentaje de 0 a 100.', $codigo)),
				'denominador' => self::number($item['denominador'] ?? null, null, sprintf('El denominador comprometido de la Meta %s es un número de personas, de cero para arriba.', $codigo)),
				'cumplimiento' => self::number($item['cumplimiento'] ?? null, 100, sprintf('El cumplimiento de la Meta %s es un porcentaje de 0 a 100.', $codigo)),
			];
			if ($row['denominador'] !== null && !in_array($meta['base'], [GoalSeed::BASE_POPULATION, GoalSeed::BASE_COMMITTED], true)) {
				throw new RefusedException($meta['manual']
					? sprintf('La Meta %s se informa a mano: no tiene denominador.', $codigo)
					: sprintf('El denominador de la Meta %s sale de las cifras REM: no se compromete.', $codigo));
			}
			if ($row['cumplimiento'] !== null && !$meta['manual']) {
				throw new RefusedException(sprintf('La Meta %s se calcula de las cifras: su cumplimiento no se escribe a mano.', $codigo));
			}
			if ($row['meta'] !== null || $row['denominador'] !== null || $row['cumplimiento'] !== null) {
				$rows[$codigo] = $row;
			}
		}

		$estab = (int)$establishment->deisCode;
		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_commitment WHERE anio = ? AND estab = ?', [$anio, $estab]);
			if ($rows !== []) {
				// The table's one INSERT, naming every column (lesson #74); the rows
				// ride one bound JSON document, the way PivImporter writes its.
				$this->db->executeStatement(
					'INSERT INTO *PREFIX*estadistica_commitment (anio, codigo, estab, meta, denominador, cumplimiento, updated_at) '
					. 'SELECT CAST(? AS smallint), t.codigo, CAST(? AS integer), t.meta, t.denominador, t.cumplimiento, CAST(? AS bigint) '
					. 'FROM jsonb_to_recordset(CAST(? AS jsonb)) AS t(codigo text, meta double precision, denominador double precision, cumplimiento double precision)',
					[$anio, $estab, $this->time->getTime(), json_encode(array_values($rows), JSON_THROW_ON_ERROR)],
				);
			}
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * What the admin section shows: each year's metas, newest first, and this
	 * install's establishment's commitments (null when none is configured).
	 *
	 * @return array{years: list<array{anio: int, version: int, nombre: string, loadedAt: int, indicadores: list<array{codigo: string, nombre: string, metaNacional: float, peso: float, base: ?string, manual: bool}>}>, local: ?array{deisCode: string, commitments: list<array{anio: int, codigo: string, meta: ?float, denominador: ?float, cumplimiento: ?float}>}}
	 * @throws RefusedException
	 */
	public function status(): array {
		$result = $this->db->executeQuery('SELECT anio, version, document, loaded_at FROM *PREFIX*estadistica_goal ORDER BY anio DESC');
		$years = [];
		while (($row = $result->fetch()) !== false) {
			$metas = $this->parse((string)$row['document']);
			$years[] = [
				'anio' => (int)$row['anio'],
				'version' => (int)$row['version'],
				'nombre' => $metas['nombre'],
				'loadedAt' => (int)$row['loaded_at'],
				'indicadores' => array_map(static fn (array $meta): array => [
					'codigo' => $meta['codigo'],
					'nombre' => $meta['nombre'],
					'metaNacional' => $meta['meta_nacional'],
					'peso' => $meta['peso'],
					'base' => $meta['base'],
					'manual' => $meta['manual'],
				], $metas['indicadores']),
			];
		}
		$result->closeCursor();

		return ['years' => $years, 'local' => $this->local()];
	}

	/** @return ?array{deisCode: string, commitments: list<array{anio: int, codigo: string, meta: ?float, denominador: ?float, cumplimiento: ?float}>} */
	private function local(): ?array {
		$establishment = $this->establishment->get();
		if (!$establishment->isConfigured()) {
			return null;
		}

		$result = $this->db->executeQuery(
			'SELECT anio, codigo, meta, denominador, cumplimiento FROM *PREFIX*estadistica_commitment WHERE estab = ? ORDER BY anio DESC, codigo',
			[(int)$establishment->deisCode],
		);
		$commitments = array_map(
			static fn (array $row): array => ['anio' => (int)$row['anio'], 'codigo' => (string)$row['codigo']] + self::commitment($row),
			$result->fetchAll(),
		);
		$result->closeCursor();

		return ['deisCode' => $establishment->deisCode, 'commitments' => $commitments];
	}

	/**
	 * @return Metas
	 * @throws RefusedException
	 */
	private function parse(string $document): array {
		return GoalSeed::parse(json_decode($document, true, 64, JSON_THROW_ON_ERROR), $this->window->currentYear());
	}

	/**
	 * @param array<string, mixed> $row
	 * @return Commitment
	 */
	private static function commitment(array $row): array {
		return [
			'meta' => $row['meta'] === null ? null : (float)$row['meta'],
			'denominador' => $row['denominador'] === null ? null : (float)$row['denominador'],
			'cumplimiento' => $row['cumplimiento'] === null ? null : (float)$row['cumplimiento'],
		];
	}

	/**
	 * A typed figure: null when left blank, else a number from 0 to $max
	 * (no ceiling when null).
	 *
	 * @throws RefusedException
	 */
	private static function number(mixed $value, ?int $max, string $refusal): ?float {
		if ($value === null) {
			return null;
		}
		if ((!is_int($value) && !is_float($value)) || $value < 0 || ($max !== null && $value > $max)) {
			throw new RefusedException($refusal);
		}

		return (float)$value;
	}
}
