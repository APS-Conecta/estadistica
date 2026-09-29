<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Service;

use OCA\Estadistica\Catalog\Catalog;
use OCA\Estadistica\Db\IngestState;
use OCA\Estadistica\Db\IngestStateMapper;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Register\Register;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * The fuentes: what this install holds, where it comes from, up to when, and
 * how whole it is (acceptance a14). The builder shows them beside every
 * figure, and suite apps and M3 read them over OCS.
 *
 * M1's fuentes are system fuentes with fixed ids (M2 contract notes): REM
 * Serie A and P, and the establishments. The origen is the publisher, whatever
 * carried the file here: a dropped zip holds DEIS's figures all the same.
 *
 * The payload is the public contract, so its keys are Spanish (the Naming
 * decision); the shapers are pure, so ApiContractTest reads the served keys
 * from them.
 *
 * @psalm-import-type Status from Catalog
 * @psalm-type Anio = array{anio: int, fecha_corte: ?string, filas: int, estado: string, sin_descripcion: int, etiquetas_de: ?int}
 * @psalm-type Fuente = array{id: string, nombre: string, origen: string, fecha_corte: ?string, filas: int, estado: string, anios: list<Anio>}
 */
class Sources {
	public const ORIGIN_DEIS = 'datos públicos DEIS';

	/** Fuente id → the REM serie it is. */
	public const REM = ['rem-a' => 'A', 'rem-p' => 'P'];

	public const REGISTER = 'establecimientos';

	public const OK = 'ok';

	public const INCOMPLETE = 'incompleto';

	public const EMPTY = 'sin datos';

	public function __construct(
		private IngestStateMapper $states,
		private Catalog $catalog,
		private Register $register,
		private YearWindow $window,
	) {
	}

	/** @return list<Fuente> */
	public function all(): array {
		$states = $this->states->findAll();
		$dictionaries = $this->catalog->status();

		return [
			...array_map(static fn (string $id): array => self::rem($id, $states, $dictionaries), array_keys(self::REM)),
			self::register($this->register->status()),
		];
	}

	/**
	 * One fuente with its catálogo: for REM, one year's hojas, secciones,
	 * prestaciones and columns (the newest loaded year when none is asked
	 * for); for the establishments, the values a peer group is chosen from.
	 *
	 * @return Fuente&array{catalogo: array<string, mixed>}
	 * @throws DoesNotExistException no fuente has that id
	 * @throws RefusedException the year is not one DEIS could have published
	 */
	public function one(string $id, ?int $anio): array {
		if ($id === self::REGISTER) {
			return self::register($this->register->status()) + ['catalogo' => $this->register->dimensions()];
		}
		if (!isset(self::REM[$id])) {
			throw new DoesNotExistException(sprintf('No hay una fuente «%s».', $id));
		}

		$fuente = self::rem($id, $this->states->findAll(), $this->catalog->status());
		$current = $this->window->currentYear();
		$anio ??= $fuente['anios'][0]['anio'] ?? $current;
		if ($anio < YearWindow::EARLIEST || $anio > $current) {
			throw new RefusedException(sprintf('El año debe estar entre %d y %d.', YearWindow::EARLIEST, $current));
		}

		return $fuente + ['catalogo' => $this->catalog->tree($anio, self::REM[$id])];
	}

	/**
	 * A REM fuente from the ingest rows and the dictionary rows: one entry per
	 * year this install knows, newest first, as IngestStateMapper::findAll
	 * orders them.
	 *
	 * @param list<IngestState> $states
	 * @param list<Status> $dictionaries
	 * @return Fuente
	 */
	public static function rem(string $id, array $states, array $dictionaries): array {
		$serie = self::REM[$id];
		$labels = [];
		foreach ($dictionaries as $dictionary) {
			if ($dictionary['serie'] === $serie) {
				$labels[$dictionary['anio']] = $dictionary;
			}
		}

		$anios = [];
		foreach ($states as $state) {
			if ($state->getSerie() !== $serie) {
				continue;
			}
			$anios[] = [
				'anio' => $state->getAnio(),
				'fecha_corte' => $state->getFechaCorte(),
				'filas' => $state->getRowCount(),
				'estado' => match (true) {
					$state->getRowCount() === 0 => self::EMPTY,
					$state->isIncomplete() => self::INCOMPLETE,
					default => self::OK,
				},
				'sin_descripcion' => $labels[$state->getAnio()]['unmapped'] ?? 0,
				'etiquetas_de' => $labels[$state->getAnio()]['labelsFrom'] ?? null,
			];
		}

		$loaded = array_filter($anios, static fn (array $anio): bool => $anio['filas'] > 0);
		$cortes = array_filter(array_column($loaded, 'fecha_corte'));

		return [
			'id' => $id,
			'nombre' => 'REM Serie ' . $serie,
			'origen' => self::ORIGIN_DEIS,
			'fecha_corte' => $cortes === [] ? null : max($cortes),
			'filas' => array_sum(array_column($anios, 'filas')),
			'estado' => match (true) {
				$loaded === [] => self::EMPTY,
				in_array(self::INCOMPLETE, array_column($loaded, 'estado'), true) => self::INCOMPLETE,
				default => self::OK,
			},
			'anios' => $anios,
		];
	}

	/**
	 * The establishments as a fuente. Its fecha de corte is the year DEIS put
	 * on the file, or the day a dropped one was loaded.
	 *
	 * @param array{origin: ?string, year: ?int, loadedAt: ?int, rows: int} $status
	 * @return Fuente
	 */
	public static function register(array $status): array {
		return [
			'id' => self::REGISTER,
			'nombre' => 'Establecimientos de salud (Base de Establecimientos)',
			'origen' => self::ORIGIN_DEIS,
			'fecha_corte' => match (true) {
				$status['year'] !== null => (string)$status['year'],
				$status['loadedAt'] !== null => gmdate('Y-m-d', $status['loadedAt']),
				default => null,
			},
			'filas' => $status['rows'],
			'estado' => $status['rows'] > 0 ? self::OK : self::EMPTY,
			'anios' => [],
		];
	}
}
