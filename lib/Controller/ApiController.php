<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\Sources;
use OCA\Estadistica\Service\YearWindow;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * Estadística over OCS: the contract suite apps and M3 read (gestion
 * docs/CONTRACTS.md, Slice 10). farmacia's resource-per-endpoint shape (the
 * OCS decision), under Spanish resource names: fuentes and consultas.
 *
 * Consumers send `OCS-APIRequest: true`, which is also the CSRF protection
 * (Request::passesCSRFCheck), and get the OCS envelope back:
 *
 *     GET /ocs/v2.php/apps/estadistica/api/v1/fuentes
 *     { "ocs": { "meta": {…}, "data": [ …the fuentes… ] } }
 *
 * Everyone reads everything published (D6), so every route is
 * #[NoAdminRequired], and every one carries a #[UserRateLimit] (MetadataTest
 * holds both).
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/**
	 * Reads: a ceiling against a runaway consumer, not a quota (farmacia's
	 * and epi's words). A page of the builder spends one or two, and a
	 * tablero one consulta per card.
	 */
	private const READ_LIMIT = 120;

	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private Sources $sources,
		private QueryExecutor $executor,
		private EstablishmentConfig $establishment,
		private YearWindow $window,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Every fuente this install holds: REM Serie A and P, year by year, and the
	 * establishments. Each says where it comes from (origen: datos públicos
	 * DEIS), up to when (fecha_corte: YYYY-MM for REM), how many rows it holds,
	 * and whether it is whole (estado: ok, incompleto, sin datos). A REM year
	 * also says how many of its códigos no dictionary explains
	 * (sin_descripcion) and whose dictionary labels it (etiquetas_de).
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: string, nombre: string, origen: string, fecha_corte: ?string, filas: int, estado: string, anios: list<array{anio: int, fecha_corte: ?string, filas: int, estado: string, sin_descripcion: int, etiquetas_de: ?int}>}>, array{}>
	 *
	 * 200: The fuentes, REM Serie A, REM Serie P, then the establishments
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fuentes')]
	public function fuentes(): DataResponse {
		return new DataResponse($this->sources->all());
	}

	/**
	 * One fuente, with its catálogo. For rem-a and rem-p, one year's hojas,
	 * secciones, prestaciones (código and label) and columns (col01…col50 and
	 * label), from that year's DICCIONARIO CODIGOS or, for a year without one,
	 * from the nearest later year's (etiquetas_de). For establecimientos, the
	 * values a peer group is chosen from, each with how many establishments
	 * carry it.
	 *
	 * An anio outside 2009 to the current year cannot be a REM year, and is
	 * refused rather than answered with an empty catálogo (epi's blank lesson:
	 * an empty answer is only for a question that was understood).
	 *
	 * @param string $id rem-a, rem-p or establecimientos
	 * @param ?int $anio for REM, the year whose catálogo to read; the newest loaded year when omitted
	 * @return DataResponse<Http::STATUS_OK, array{id: string, nombre: string, origen: string, fecha_corte: ?string, filas: int, estado: string, anios: list<array{anio: int, fecha_corte: ?string, filas: int, estado: string, sin_descripcion: int, etiquetas_de: ?int}>, catalogo: array<string, mixed>}, array{}>|DataResponse<Http::STATUS_BAD_REQUEST, array{message: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: The fuente and its catálogo
	 * 400: The year cannot be a REM year — {message: the Spanish sentence}
	 * 404: No fuente has that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/fuentes/{id}', requirements: ['id' => '[a-z-]+'])]
	public function fuente(string $id, ?int $anio = null): DataResponse {
		try {
			return new DataResponse($this->sources->one($id, $anio));
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (RefusedException $e) {
			// 400, not 422: the request itself is unintelligible (farmacia's
			// index() rule). The sentence still ships.
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Answers one consulta: the local establishment's series and, when the
	 * spec asks to compare, the national and the peer group's. Each is summed
	 * and grouped as the spec says, and comes with the labels of what was
	 * summed and the fuente it came from. The builder asks through here, so
	 * the API returns the UI's numbers (a15).
	 *
	 * The spec is refused in Spanish, before any SQL, when it names anything
	 * it may not: an unknown key, a column outside col01…col50, a metric that
	 * is not suma or promedio_establecimiento (a16). A consulta that runs past
	 * 10 s is cancelled and refused the same way.
	 *
	 * The local series is this install's establishment (app config), unless
	 * the request names another DEIS code: a parameter, not an implicit
	 * «ours» (gestion ADR-0013). The app's own pages never send one, because a
	 * clinic selector is a governance decision nobody has taken.
	 *
	 * It is a POST because the spec is a JSON document, and it spends the read
	 * limit because it writes nothing.
	 *
	 * @param array<string, mixed> $consulta the spec: fuente, periodo, filtros, agrupar, metrica and comparar (docs/adr/0006)
	 * @param ?string $establecimiento the six-digit DEIS code of the local series; this install's when omitted
	 * @return DataResponse<Http::STATUS_OK, array{series: array{local: ?list<array{grupo: list<int|string|null>, valor: float, informantes: int}>, nacional: ?list<array{grupo: list<int|string|null>, valor: float, informantes: int}>, pares: ?list<array{grupo: list<int|string|null>, valor: float, informantes: int}>}, etiquetas: array{local: ?string, pares: ?array<string, string>, codigos: list<array{codigo: string, hoja: string, seccion: string, prestacion: string, columnas: array<string, string>, nota: ?string}>}, fuente: array{id: string, nombre: string, origen: string, fecha_corte: ?string, filas: int, estado: string, anios: list<array{anio: int, fecha_corte: ?string, filas: int, estado: string, sin_descripcion: int, etiquetas_de: ?int}>}}, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 200: The series (null where not asked for, or no establishment is configured), their labels and their fuente
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/consultas')]
	public function consultas(array $consulta, ?string $establecimiento = null): DataResponse {
		try {
			$local = $establecimiento === null ? $this->establishment->get() : Establishment::of($establecimiento);

			return new DataResponse($this->executor->answer(QuerySpec::from($consulta, $this->window->currentYear()), $local));
		} catch (RefusedException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	/**
	 * Understood and refused: the app's 422, the OCS half of aps-common's
	 * Refuses, which answers the internal routes (farmacia's refuse()).
	 */
	private function refuse(string $message): DataResponse {
		return new DataResponse(['message' => $message], Http::STATUS_UNPROCESSABLE_ENTITY);
	}
}
