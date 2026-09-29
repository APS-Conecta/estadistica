<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Piv;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use Throwable;

/**
 * The PIV's one writer, and what the admin section reads back from it
 * (acceptance a11, docs/adr/0007). Two things write estadistica_piv, both
 * through insert():
 *
 * - FONASA's «Inscritos APS» CSV. It comes through the drop door
 *   (DroppedFiles) and is read here, in the request: the 2025 file is 22 MB.
 *   Each corte it carries replaces that corte's FONASA rows in one
 *   transaction. So a corte is never half one file and half another, and a
 *   refused file leaves every corte as it was.
 * - An admin's figures for this install's own establishment, typed in the
 *   admin section for the corte of 31 December of a year. They win over
 *   FONASA's for that establishment's own series only; the country and the
 *   peer groups stay FONASA's published counts (owner, Slice 8). A file
 *   loaded later does not touch them.
 *
 * @psalm-import-type Row from PivReader
 * @psalm-type Corte = array{corte: string, rows: int, establishments: int, inscritos: int, loadedAt: int}
 * @psalm-type Cell = array{corte: string, edad: int, sexo: string, fonasa: ?int, manual: ?int}
 */
class PivImporter {
	/** The sexes a figure can be typed for. «Sin información» is FONASA's alone. */
	public const TYPED_SEXES = ['M', 'H'];

	public function __construct(
		private IDBConnection $db,
		private EstablishmentConfig $establishment,
		private YearWindow $window,
		private ITimeFactory $time,
	) {
	}

	/**
	 * Loads a FONASA export from a local file.
	 *
	 * @return array{cortes: list<string>, establishments: int, inscritos: int, unknownAge: int}
	 * @throws RefusedException
	 */
	public function import(string $local): array {
		$stream = fopen($local, 'rb');
		if ($stream === false) {
			throw new RefusedException('No se pudo leer el archivo recibido.');
		}
		try {
			$read = PivReader::read($stream);
		} finally {
			fclose($stream);
		}

		$cortes = array_values(array_unique(array_column($read['rows'], 'corte')));
		rsort($cortes);

		$this->db->beginTransaction();
		try {
			foreach ($cortes as $corte) {
				$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_piv WHERE corte = ? AND manual = 0', [$corte]);
			}
			$this->insert($read['rows'], 0);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return [
			'cortes' => $cortes,
			'establishments' => count(array_unique(array_column($read['rows'], 'estab'))),
			'inscritos' => array_sum(array_column($read['rows'], 'inscritos')),
			'unknownAge' => $read['unknownAge'],
		];
	}

	/**
	 * This install's establishment's inscritos at 31 December of $year, as an
	 * admin typed them. The cells given replace every figure typed before for
	 * that corte, and a cell left out goes back to FONASA's.
	 *
	 * @param list<mixed> $cells each {edad, sexo, inscritos}
	 * @throws RefusedException
	 */
	public function enter(int $year, array $cells): void {
		$establishment = $this->establishment->get();
		if (!$establishment->isConfigured()) {
			throw new RefusedException('Todavía no se ha configurado el establecimiento de esta instalación, así que no hay población propia que escribir.');
		}
		$current = $this->window->currentYear();
		if ($year < YearWindow::EARLIEST || $year > $current) {
			throw new RefusedException(sprintf('El año del corte debe estar entre %d y %d.', YearWindow::EARLIEST, $current));
		}

		$corte = sprintf('%d-12-31', $year);
		$estab = (int)$establishment->deisCode;
		$rows = [];
		foreach ($cells as $cell) {
			$edad = is_array($cell) ? ($cell['edad'] ?? null) : null;
			$sexo = is_array($cell) ? ($cell['sexo'] ?? null) : null;
			if (!in_array($edad, PivReader::BANDS, true) || !in_array($sexo, self::TYPED_SEXES, true)) {
				throw new RefusedException('Cada cifra va en un tramo de edad de FONASA (0 a 9 años … 80 a más años), de Mujeres o de Hombres.');
			}
			$inscritos = $cell['inscritos'] ?? null;
			if (!is_int($inscritos) || $inscritos < 0) {
				throw new RefusedException(sprintf('Los inscritos de %s son un número entero, de cero para arriba.', self::cellName($edad, $sexo)));
			}
			$key = $edad . '|' . $sexo;
			if (isset($rows[$key])) {
				throw new RefusedException(sprintf('La cifra de %s viene dos veces.', self::cellName($edad, $sexo)));
			}
			$rows[$key] = ['corte' => $corte, 'estab' => $estab, 'edad' => $edad, 'sexo' => $sexo, 'inscritos' => $inscritos];
		}

		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_piv WHERE corte = ? AND estab = ? AND manual = 1', [$corte, $estab]);
			$this->insert(array_values($rows), 1);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * FONASA's cortes, and this install's establishment's figures (null when
	 * none is configured): what the admin section shows.
	 *
	 * @return array{cortes: list<Corte>, local: ?array{deisCode: string, cells: list<Cell>}}
	 */
	public function status(): array {
		return ['cortes' => $this->cortes(), 'local' => $this->local()];
	}

	/**
	 * Each corte a FONASA file brought, newest first. Figures typed by hand are
	 * not FONASA's and are not counted here.
	 *
	 * @return list<Corte>
	 */
	public function cortes(): array {
		$result = $this->db->executeQuery(
			'SELECT corte, count(*) AS n, count(DISTINCT estab) AS establishments, sum(inscritos) AS inscritos, max(loaded_at) AS loaded_at '
			. 'FROM *PREFIX*estadistica_piv WHERE manual = 0 GROUP BY corte ORDER BY corte DESC',
		);
		$cortes = array_map(static fn (array $row): array => [
			'corte' => (string)$row['corte'],
			'rows' => (int)$row['n'],
			'establishments' => (int)$row['establishments'],
			'inscritos' => (int)$row['inscritos'],
			'loadedAt' => (int)$row['loaded_at'],
		], $result->fetchAll());
		$result->closeCursor();

		return $cortes;
	}

	/**
	 * Every figure of this install's establishment, FONASA's beside the one
	 * typed for the same cell, newest corte first.
	 *
	 * @return ?array{deisCode: string, cells: list<Cell>}
	 */
	private function local(): ?array {
		$establishment = $this->establishment->get();
		if (!$establishment->isConfigured()) {
			return null;
		}

		$result = $this->db->executeQuery(
			'SELECT corte, edad, sexo, manual, inscritos FROM *PREFIX*estadistica_piv WHERE estab = ? ORDER BY corte DESC, edad, sexo',
			[(int)$establishment->deisCode],
		);
		$cells = [];
		while (($row = $result->fetch()) !== false) {
			$key = $row['corte'] . '|' . $row['edad'] . '|' . $row['sexo'];
			$cells[$key] ??= ['corte' => (string)$row['corte'], 'edad' => (int)$row['edad'], 'sexo' => (string)$row['sexo'], 'fonasa' => null, 'manual' => null];
			$cells[$key][(int)$row['manual'] === 1 ? 'manual' : 'fonasa'] = (int)$row['inscritos'];
		}
		$result->closeCursor();

		return ['deisCode' => $establishment->deisCode, 'cells' => array_values($cells)];
	}

	/**
	 * The table's one INSERT, naming every column (lesson #74). The rows ride
	 * one bound JSON document, the way Catalog and Register write theirs.
	 *
	 * @param list<Row> $rows
	 */
	private function insert(array $rows, int $manual): void {
		if ($rows === []) {
			return;
		}

		$this->db->executeStatement(
			'INSERT INTO *PREFIX*estadistica_piv (corte, estab, edad, sexo, manual, inscritos, loaded_at) '
			. 'SELECT t.corte, t.estab, t.edad, t.sexo, CAST(? AS smallint), t.inscritos, CAST(? AS bigint) '
			. 'FROM jsonb_to_recordset(CAST(? AS jsonb)) AS t(corte date, estab integer, edad smallint, sexo text, inscritos integer)',
			[$manual, $this->time->getTime(), json_encode($rows, JSON_THROW_ON_ERROR)],
		);
	}

	/** «mujeres de 20 a 29 años», for a cell already known to be one. */
	private static function cellName(int $edad, string $sexo): string {
		return sprintf('%s de %s', $sexo === 'M' ? 'mujeres' : 'hombres', (string)array_search($edad, PivReader::BANDS, true));
	}
}
