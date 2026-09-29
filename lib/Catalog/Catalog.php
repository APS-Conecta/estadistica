<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Catalog;

use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Rem\MemberLines;
use OCA\Estadistica\Rem\RangeReader;
use OCA\Estadistica\Rem\ZipDirectory;
use OCA\Estadistica\Xlsx\XlsxReader;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What REM's figures mean: for each (año, código, col), the hoja, the
 * sección, the prestación and the column, from that year's own DICCIONARIO
 * CODIGOS (acceptance a6). Códigos and columns change from year to year (DEIS
 * FAQ), so a label is never borrowed from another year when the year has a
 * dictionary of its own.
 *
 * - **The dictionary travels with the figures.** It is read from the same zip,
 *   in the same attempt, as the serie it labels (RemImporter), whether that zip
 *   came from DEIS or was dropped by an admin. Nothing derived from it ships
 *   (a2).
 * - **2009–2016 ship dictionaries as BIFF .xls, which is not read.** Those
 *   years take each código's label from the nearest later year whose
 *   dictionary has it, marked «etiqueta de <año>». A código no dictionary
 *   explains is «sin descripción», and those are counted per (año, serie) for
 *   the admin section.
 * - **A dictionary that cannot be read never holds back the figures.** Its
 *   outcome is recorded on its own row, beside the load's. A transfer cut
 *   leaves the dictionary's identity as it was, so the daily check asks for
 *   it again. Content that is not a dictionary is recorded with its identity,
 *   so the same bytes are not read again. Either way the previous labels stay.
 *
 * @psalm-import-type Member from ZipDirectory
 * @psalm-import-type Parsed from DictionaryParser
 * @psalm-type Label = array{codigo: string, hoja: ?string, seccion: ?string, prestacion: string, columnas: array<int, string>, de: ?int}
 * @psalm-type Status = array{anio: int, serie: string, file: ?string, codes: int, unmapped: int, labelsFrom: ?int, error: ?string, checkedAt: int}
 */
class Catalog {
	public const NO_LABEL = 'sin descripción';

	/** A dictionary inflates to ≈ 2 MB (2023 SA). Past this it is not one. */
	private const MAX_BYTES = 64 * 1024 * 1024;

	public function __construct(
		private IDBConnection $db,
		private ITempManager $tempManager,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Reads (año, serie)'s dictionary from the zip its figures came from,
	 * stores its labels, records what it found, then counts the año's códigos
	 * that are left without a label. Outcomes are recorded, not thrown.
	 */
	public function load(RangeReader $range, ZipDirectory $zip, int $anio, string $serie): void {
		try {
			$member = DictionaryParser::member($zip, $serie);
			if ($member === null) {
				$this->judge($anio, $serie, null, null);
			} elseif (($member['flags'] & 0x1) !== 0 || $member['method'] !== 8 || $member['size'] > self::MAX_BYTES) {
				$this->judge($anio, $serie, $member, sprintf(
					'El diccionario %s no se puede leer: está cifrado, comprimido de otra forma o pesa demasiado.',
					DictionaryParser::fileName($member['name']),
				));
			} else {
				$this->read($range, $member, $anio, $serie);
			}
		} catch (RefusedException $e) {
			// Two of them: there is no identity to judge against, and the daily
			// check compares against the same none (DeisRepository).
			$this->judge($anio, $serie, null, $e->getMessage());
		}

		$this->count($anio, $serie);
	}

	/**
	 * The label of each código for (año, serie).
	 *
	 * @param list<string> $codigos
	 * @return array<string, Label>
	 */
	public function labels(int $anio, string $serie, array $codigos): array {
		if ($codigos === []) {
			return [];
		}

		$own = $this->hasLabels($anio, $serie);
		// Only the operator changes, and it is one of two fixed ones; every value is bound.
		$result = $this->db->executeQuery(
			'SELECT anio, codigo, hoja, seccion, prestacion, columnas FROM *PREFIX*estadistica_code '
			. 'WHERE serie = ? AND anio ' . ($own ? '=' : '>') . ' ? '
			. 'AND codigo IN (SELECT jsonb_array_elements_text(CAST(? AS jsonb)))',
			[$serie, $anio, json_encode(array_values($codigos), JSON_THROW_ON_ERROR)],
		);
		$candidates = [];
		while (($row = $result->fetch()) !== false) {
			$candidates[$row['codigo']][] = [
				'anio' => (int)$row['anio'],
				'hoja' => $row['hoja'],
				'seccion' => $row['seccion'],
				'prestacion' => $row['prestacion'],
				'columnas' => self::columns((string)$row['columnas']),
			];
		}
		$result->closeCursor();

		return self::resolve($anio, $own, $codigos, $candidates);
	}

	/**
	 * Picks each código's label. With a dictionary of its own, a year reads
	 * only that one. Without, each código reads the nearest later year that has
	 * it. Pure, so the rule is unit-tested (CatalogTest).
	 *
	 * @param list<string> $codigos
	 * @param array<string, list<array{anio: int, hoja: string, seccion: string, prestacion: string, columnas: array<int, string>}>> $candidates
	 * @return array<string, Label>
	 */
	public static function resolve(int $anio, bool $own, array $codigos, array $candidates): array {
		$labels = [];
		foreach ($codigos as $codigo) {
			$rows = array_filter(
				$candidates[$codigo] ?? [],
				static fn (array $row): bool => $own ? $row['anio'] === $anio : $row['anio'] > $anio,
			);
			usort($rows, static fn (array $a, array $b): int => $a['anio'] <=> $b['anio']);
			$row = $rows[0] ?? null;

			$labels[$codigo] = $row === null
				? ['codigo' => $codigo, 'hoja' => null, 'seccion' => null, 'prestacion' => self::NO_LABEL, 'columnas' => [], 'de' => null]
				: ['codigo' => $codigo, 'hoja' => $row['hoja'], 'seccion' => $row['seccion'], 'prestacion' => $row['prestacion'], 'columnas' => $row['columnas'], 'de' => $row['anio']];
		}

		return $labels;
	}

	/**
	 * The whole label of one (año, código, col), as a person reads it.
	 *
	 * @param Label $label
	 * @return array{hoja: string, seccion: string, prestacion: string, columna: string, nota: ?string}
	 */
	public static function describe(array $label, int $anio, int $col): array {
		$columna = $label['columnas'][$col] ?? '';

		return [
			'hoja' => $label['hoja'] ?? '',
			'seccion' => $label['seccion'] ?? '',
			'prestacion' => $label['prestacion'],
			'columna' => $columna === '' ? self::NO_LABEL : $columna,
			'nota' => $label['de'] !== null && $label['de'] !== $anio ? sprintf('etiqueta de %d', $label['de']) : null,
		];
	}

	/** @param array<string, Label> $labels */
	public static function unmapped(array $labels): int {
		return count(array_filter($labels, static fn (array $label): bool => $label['de'] === null));
	}

	/**
	 * (año, serie)'s labels as the builder browses them: hojas, their
	 * secciones, each with its columns and prestaciones, in the planilla's
	 * order. A year without a dictionary shows the nearest later year's, which
	 * etiquetas_de names. That dictionary may list códigos the year did not
	 * report yet.
	 *
	 * ponytail: a borrowed catálogo is the later year's whole tree. The upgrade
	 * is to keep only the códigos the year's rows carry (one DISTINCT per read,
	 * ≈ 1 s over a year of Serie A).
	 *
	 * @return array{anio: int, etiquetas_de: ?int, diccionario: ?string, hojas: list<array{hoja: string, titulo: string, secciones: list<array{seccion: string, columnas: list<array{col: string, etiqueta: string}>, prestaciones: list<array{codigo: string, etiqueta: string}>}>}>}
	 */
	public function tree(int $anio, string $serie): array {
		$from = $this->hasLabels($anio, $serie) ? $anio : $this->nearestLater($anio, $serie);
		if ($from === null) {
			return ['anio' => $anio, 'etiquetas_de' => null, 'diccionario' => null, 'hojas' => []];
		}

		$result = $this->db->executeQuery(
			'SELECT name, sheets FROM *PREFIX*estadistica_dictionary WHERE anio = ? AND serie = ?',
			[$from, $serie],
		);
		$dictionary = $result->fetch() ?: [];
		$result->closeCursor();
		$titles = json_decode((string)($dictionary['sheets'] ?? '{}'), true) ?: [];

		$hojas = [];
		$result = $this->db->executeQuery(
			'SELECT codigo, hoja, seccion, prestacion, columnas FROM *PREFIX*estadistica_code WHERE anio = ? AND serie = ? ORDER BY orden',
			[$from, $serie],
		);
		while (($row = $result->fetch()) !== false) {
			$hoja = &$hojas[$row['hoja']];
			$hoja ??= ['hoja' => $row['hoja'], 'titulo' => (string)($titles[$row['hoja']] ?? ''), 'secciones' => []];
			$seccion = &$hoja['secciones'][$row['seccion']];
			$seccion ??= ['seccion' => $row['seccion'], 'columnas' => [], 'prestaciones' => []];
			$seccion['columnas'] += self::columns((string)$row['columnas']);
			$seccion['prestaciones'][] = ['codigo' => $row['codigo'], 'etiqueta' => $row['prestacion']];
			unset($hoja, $seccion);
		}
		$result->closeCursor();

		return [
			'anio' => $anio,
			'etiquetas_de' => $from,
			'diccionario' => $dictionary['name'] ?? null,
			'hojas' => array_values(array_map(static fn (array $hoja): array => [
				...$hoja,
				'secciones' => array_values(array_map(static function (array $seccion): array {
					ksort($seccion['columnas']);
					$seccion['columnas'] = array_map(
						static fn (int $col, string $etiqueta): array => ['col' => sprintf('col%02d', $col), 'etiqueta' => $etiqueta === '' ? self::NO_LABEL : $etiqueta],
						array_keys($seccion['columnas']),
						$seccion['columnas'],
					);

					return $seccion;
				}, $hoja['secciones'])),
			], $hojas)),
		];
	}

	/**
	 * Every (año, serie) a dictionary was looked for, newest first: the admin
	 * section lists it beside the load, and the fuentes carry its counts.
	 *
	 * @return list<Status>
	 */
	public function status(): array {
		$result = $this->db->executeQuery(
			'SELECT anio, serie, name, codes, unmapped, error, checked_at FROM *PREFIX*estadistica_dictionary ORDER BY anio DESC, serie',
		);
		$rows = $result->fetchAll();
		$result->closeCursor();

		$status = [];
		foreach ($rows as $row) {
			$status[] = [
				'anio' => (int)$row['anio'],
				'serie' => $row['serie'],
				'file' => $row['name'],
				'codes' => (int)$row['codes'],
				'unmapped' => (int)$row['unmapped'],
				'labelsFrom' => null,
				'error' => $row['error'],
				'checkedAt' => (int)$row['checked_at'],
			];
		}

		// A year reads its own labels, or the nearest later year's of the same serie.
		foreach ($status as $i => $row) {
			if ($row['codes'] > 0) {
				$status[$i]['labelsFrom'] = $row['anio'];
				continue;
			}
			foreach (array_reverse($status) as $later) {
				if ($later['serie'] === $row['serie'] && $later['anio'] > $row['anio'] && $later['codes'] > 0) {
					$status[$i]['labelsFrom'] = $later['anio'];
					break;
				}
			}
		}

		return $status;
	}

	/**
	 * Whether the dictionary the zip now carries for (año, serie) is the one
	 * last judged: the daily check re-queues the serie when it is not
	 * (DeisRepository), so a dictionary DEIS republishes alone is read again.
	 * A year never looked at is not judged.
	 *
	 * @param ?Member $member null when the zip carries none this app can read
	 */
	public function judged(int $anio, string $serie, ?array $member): bool {
		$result = $this->db->executeQuery(
			'SELECT member_crc, member_size FROM *PREFIX*estadistica_dictionary WHERE anio = ? AND serie = ?',
			[$anio, $serie],
		);
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return false;
		}
		if ($member === null) {
			return $row['member_crc'] === null;
		}

		return $row['member_crc'] !== null
			&& (int)$row['member_crc'] === $member['crc']
			&& (int)$row['member_size'] === $member['size'];
	}

	/** $anio's labels and dictionary rows, both series: a year the window let go (RemImporter::forget, inside its transaction). */
	public function forget(int $anio): void {
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_code WHERE anio = ?', [$anio]);
		$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_dictionary WHERE anio = ?', [$anio]);
	}

	/** @param Member $member */
	private function read(RangeReader $range, array $member, int $anio, string $serie): void {
		$name = DictionaryParser::fileName($member['name']);
		$path = $this->tempManager->getTemporaryFile('.xlsx');
		if ($path === false) {
			$this->fail($anio, $serie, 'No se pudo crear un archivo temporal para leer el diccionario.');
			return;
		}

		try {
			try {
				$this->copy($range, $member, $path);
			} catch (RefusedException $e) {
				// The transfer, not the dictionary: judged nothing, asked again.
				$this->fail($anio, $serie, sprintf('El diccionario %s no se pudo descargar: %s', $name, $e->getMessage()));
				return;
			}

			try {
				$parsed = DictionaryParser::parse(XlsxReader::open($path));
			} catch (RefusedException $e) {
				$this->judge($anio, $serie, $member, sprintf('El diccionario %s no se pudo leer: %s', $name, $e->getMessage()));
				return;
			} catch (Throwable $e) {
				$this->logger->warning('Reading the REM {anio} Serie {serie} dictionary failed', ['anio' => $anio, 'serie' => $serie, 'exception' => $e]);
				$this->fail($anio, $serie, sprintf('El diccionario %s no se pudo leer por un error interno; el detalle quedó en el registro del servidor.', $name));
				return;
			}

			$this->replace($anio, $serie, $member, $parsed);
		} finally {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	/**
	 * @param Member $member
	 * @throws RefusedException
	 */
	private function copy(RangeReader $range, array $member, string $path): void {
		$target = fopen($path, 'wb');
		if ($target === false) {
			throw new RefusedException('no se pudo escribir el archivo temporal.');
		}
		try {
			foreach (MemberLines::chunks($range, $member) as $chunk) {
				fwrite($target, $chunk);
			}
		} finally {
			fclose($target);
		}
	}

	/**
	 * The labels and their dictionary's row, together.
	 *
	 * The labels go in as one JSON document through jsonb_to_recordset: they
	 * are free text, which RemLoader's unquoted array literals cannot carry,
	 * and json_encode does all the escaping. Every value is bound.
	 *
	 * @param Member $member
	 * @param Parsed $parsed
	 */
	private function replace(int $anio, string $serie, array $member, array $parsed): void {
		$rows = [];
		foreach ($parsed['entries'] as $orden => $entry) {
			$rows[] = [
				'codigo' => $entry['codigo'],
				'orden' => $orden,
				'hoja' => $entry['hoja'],
				'seccion' => $entry['seccion'],
				'prestacion' => $entry['prestacion'],
				'columnas' => json_encode($entry['columnas'], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
			];
		}

		$this->db->beginTransaction();
		try {
			$this->db->executeStatement('DELETE FROM *PREFIX*estadistica_code WHERE anio = ? AND serie = ?', [$anio, $serie]);
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_code (anio, serie, codigo, orden, hoja, seccion, prestacion, columnas) '
				. 'SELECT CAST(? AS smallint), ?, t.codigo, t.orden, t.hoja, t.seccion, t.prestacion, t.columnas '
				. 'FROM jsonb_to_recordset(CAST(? AS jsonb)) AS t(codigo text, orden integer, hoja text, seccion text, prestacion text, columnas text)',
				[$anio, $serie, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
			);
			$this->db->executeStatement(
				'INSERT INTO *PREFIX*estadistica_dictionary (anio, serie, name, member_crc, member_size, sheets, codes, error, checked_at) '
				. 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?) '
				. 'ON CONFLICT (anio, serie) DO UPDATE SET name = EXCLUDED.name, member_crc = EXCLUDED.member_crc, '
				. 'member_size = EXCLUDED.member_size, sheets = EXCLUDED.sheets, codes = EXCLUDED.codes, error = NULL, checked_at = EXCLUDED.checked_at',
				[
					$anio, $serie, DictionaryParser::fileName($member['name']), $member['crc'], $member['size'],
					json_encode($parsed['hojas'], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
					count($rows), $this->time->getTime(),
				],
			);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Judged on its content: what the zip carries (a member, or none) becomes
	 * the identity the daily check compares against. The labels stored before
	 * stay.
	 *
	 * @param ?Member $member
	 */
	private function judge(int $anio, string $serie, ?array $member, ?string $error): void {
		$this->db->executeStatement(
			'INSERT INTO *PREFIX*estadistica_dictionary (anio, serie, name, member_crc, member_size, error, checked_at) '
			. 'VALUES (?, ?, ?, ?, ?, ?, ?) '
			. 'ON CONFLICT (anio, serie) DO UPDATE SET name = EXCLUDED.name, member_crc = EXCLUDED.member_crc, '
			. 'member_size = EXCLUDED.member_size, error = EXCLUDED.error, checked_at = EXCLUDED.checked_at',
			[
				$anio, $serie,
				$member === null ? null : DictionaryParser::fileName($member['name']),
				$member['crc'] ?? null, $member['size'] ?? null,
				$error, $this->time->getTime(),
			],
		);
	}

	/** Judged nothing: the error is said, and the identity stays what it was. */
	private function fail(int $anio, string $serie, string $error): void {
		$this->db->executeStatement(
			'INSERT INTO *PREFIX*estadistica_dictionary (anio, serie, error, checked_at) VALUES (?, ?, ?, ?) '
			. 'ON CONFLICT (anio, serie) DO UPDATE SET error = EXCLUDED.error, checked_at = EXCLUDED.checked_at',
			[$anio, $serie, $error, $this->time->getTime()],
		);
	}

	/**
	 * How many of the códigos (año, serie)'s stored rows carry have no label:
	 * one DISTINCT over the rows' primary key (≈ 1 s for a year of Serie A),
	 * paid once per load, in the job.
	 *
	 * ponytail: a year that borrows labels is counted against the later
	 * dictionaries stored when it loads. The upgrade, if a later dictionary
	 * changing should recount it, is to recount every borrowing year after
	 * each dictionary load.
	 */
	private function count(int $anio, string $serie): void {
		$result = $this->db->executeQuery(
			'SELECT DISTINCT codigo FROM *PREFIX*estadistica_rem WHERE anio = ? AND serie = ?',
			[$anio, $serie],
		);
		$codigos = array_map('strval', $result->fetchFirstColumn());
		$result->closeCursor();

		$this->db->executeStatement(
			'UPDATE *PREFIX*estadistica_dictionary SET unmapped = ? WHERE anio = ? AND serie = ?',
			[self::unmapped($this->labels($anio, $serie, $codigos)), $anio, $serie],
		);
	}

	private function hasLabels(int $anio, string $serie): bool {
		$result = $this->db->executeQuery(
			'SELECT 1 FROM *PREFIX*estadistica_code WHERE anio = ? AND serie = ? LIMIT 1',
			[$anio, $serie],
		);
		$has = $result->fetchOne() !== false;
		$result->closeCursor();

		return $has;
	}

	private function nearestLater(int $anio, string $serie): ?int {
		$result = $this->db->executeQuery(
			'SELECT min(anio) FROM *PREFIX*estadistica_code WHERE serie = ? AND anio > ?',
			[$serie, $anio],
		);
		$from = $result->fetchOne();
		$result->closeCursor();

		return $from === null || $from === false ? null : (int)$from;
	}

	/** @return array<int, string> */
	private static function columns(string $json): array {
		$columns = json_decode($json, true) ?: [];
		$typed = [];
		foreach ($columns as $col => $label) {
			$typed[(int)$col] = (string)$label;
		}

		return $typed;
	}
}
