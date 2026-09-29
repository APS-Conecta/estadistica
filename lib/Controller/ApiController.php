<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Controller;

use Closure;
use OCA\Estadistica\AppInfo\Application;
use OCA\Estadistica\Domain\Establishment;
use OCA\Estadistica\Exception\ForbiddenException;
use OCA\Estadistica\Exception\RefusedException;
use OCA\Estadistica\Query\QueryExecutor;
use OCA\Estadistica\Query\QuerySpec;
use OCA\Estadistica\Service\EstablishmentConfig;
use OCA\Estadistica\Service\SavedContent;
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
 * OCS decision), under Spanish resource names: fuentes, consultas,
 * preguntas and tableros.
 *
 * Consumers send `OCS-APIRequest: true`, which is also the CSRF protection
 * (Request::passesCSRFCheck), and get the OCS envelope back:
 *
 *     GET /ocs/v2.php/apps/estadistica/api/v1/fuentes
 *     { "ocs": { "meta": {…}, "data": [ …the fuentes… ] } }
 *
 * Everyone reads everything published (D6), so every route is
 * #[NoAdminRequired], and every one carries a #[UserRateLimit] (MetadataTest
 * holds both). Anyone may save a pregunta or a tablero; changing one is its
 * creator's, or belongs to whoever administers Estadística, and SavedContent
 * answers everyone else with a 403.
 */
#[OpenAPI(scope: OpenAPI::SCOPE_DEFAULT)]
class ApiController extends OCSController {
	/**
	 * Reads: a ceiling against a runaway consumer, not a quota (farmacia's
	 * and epi's words). A page of the builder spends one or two, and a
	 * tablero one consulta per card.
	 */
	private const READ_LIMIT = 120;

	/**
	 * Writes: preguntas and tableros are saved by a person, one at a time
	 * (farmacia's 20). A card moved up or down is one write.
	 */
	private const WRITE_LIMIT = 20;

	private const PERIOD = 60;

	public function __construct(
		IRequest $request,
		private Sources $sources,
		private QueryExecutor $executor,
		private EstablishmentConfig $establishment,
		private YearWindow $window,
		private SavedContent $saved,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Every fuente this install holds: REM Serie A and P, year by year, the
	 * establishments, and FONASA's Población Inscrita Validada (piv). Each says
	 * where it comes from (origen: datos públicos DEIS, or FONASA), up to when
	 * (fecha_corte: YYYY-MM for REM, the newest corte's YYYY-MM-DD for the
	 * PIV), how many rows it holds, and whether it is whole (estado: ok,
	 * incompleto, sin datos). A REM year also says how many of its códigos no
	 * dictionary explains (sin_descripcion) and whose dictionary labels it
	 * (etiquetas_de).
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: string, nombre: string, origen: string, fecha_corte: ?string, filas: int, estado: string, anios: list<array{anio: int, fecha_corte: ?string, filas: int, estado: string, sin_descripcion: int, etiquetas_de: ?int}>}>, array{}>
	 *
	 * 200: The fuentes, REM Serie A, REM Serie P, the establishments, then the PIV
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
	 * carry it. For piv, FONASA's cortes, newest first, each with how many
	 * establishments and inscritos it holds.
	 *
	 * An anio outside 2009 to the current year cannot be a REM year, and is
	 * refused rather than answered with an empty catálogo (epi's blank lesson:
	 * an empty answer is only for a question that was understood).
	 *
	 * @param string $id rem-a, rem-p, establecimientos or piv
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
	 * summed and the fuente it came from. The builder and the tableros ask
	 * through here, so the API returns the UI's numbers (a15).
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
	 * Every pregunta: a consulta saved under a title for everyone to read
	 * (D6), the most recently changed first. consulta is the spec as its
	 * author sent it, which POST consultas answers; tipo is how it is drawn
	 * (barra, linea or tabla). editable says whether the person asking may
	 * change it: its creator, or whoever administers Estadística.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: int, titulo: string, consulta: array<string, mixed>, tipo: string, autor: string, autor_nombre: string, actualizada: int, editable: bool}>, array{}>
	 *
	 * 200: The preguntas
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/preguntas')]
	public function preguntas(): DataResponse {
		return new DataResponse($this->saved->listQuestions());
	}

	/**
	 * One pregunta.
	 *
	 * @param int $id the pregunta's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK, array{id: int, titulo: string, consulta: array<string, mixed>, tipo: string, autor: string, autor_nombre: string, actualizada: int, editable: bool}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: The pregunta
	 * 404: No pregunta has that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/preguntas/{id}', requirements: ['id' => '\d+'])]
	public function pregunta(int $id): DataResponse {
		try {
			return new DataResponse($this->saved->getQuestion($id));
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Saves a consulta as a pregunta, visible to all the staff (D6). The
	 * consulta must be one POST consultas would answer, and is kept as sent.
	 *
	 * @param string $titulo what the staff see in the list, up to 200 characters
	 * @param array<string, mixed> $consulta the spec, as POST consultas takes it (docs/adr/0006)
	 * @param string $tipo barra, linea or tabla
	 * @return DataResponse<Http::STATUS_CREATED, array{id: int, titulo: string, consulta: array<string, mixed>, tipo: string, autor: string, autor_nombre: string, actualizada: int, editable: bool}, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: The stored pregunta
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/preguntas')]
	public function crearPregunta(string $titulo, array $consulta, string $tipo = 'barra'): DataResponse {
		try {
			return new DataResponse($this->saved->createQuestion($titulo, $consulta, $tipo), Http::STATUS_CREATED);
		} catch (RefusedException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	/**
	 * Changes a pregunta: what is left out stays as it was. Its creator or
	 * whoever administers Estadística may; anyone else gets a 403.
	 *
	 * @param int $id the pregunta's id, as the list served it
	 * @param ?string $titulo a new title
	 * @param ?array<string, mixed> $consulta a new spec, kept as sent
	 * @param ?string $tipo barra, linea or tabla
	 * @return DataResponse<Http::STATUS_OK, array{id: int, titulo: string, consulta: array<string, mixed>, tipo: string, autor: string, autor_nombre: string, actualizada: int, editable: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: The pregunta as it now reads
	 * 403: Not this person's to change — {message: the Spanish sentence}
	 * 404: No pregunta has that id
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/preguntas/{id}', requirements: ['id' => '\d+'])]
	public function editarPregunta(int $id, ?string $titulo = null, ?array $consulta = null, ?string $tipo = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->saved->updateQuestion($id, $titulo, $consulta, $tipo)));
	}

	/**
	 * Deletes a pregunta. A tablero that shows it keeps its card, which says
	 * the pregunta was deleted. Its creator or whoever administers Estadística
	 * may; anyone else gets a 403.
	 *
	 * @param int $id the pregunta's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, list<empty>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Deleted
	 * 403: Not this person's to delete — {message: the Spanish sentence}
	 * 404: No pregunta has that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/preguntas/{id}', requirements: ['id' => '\d+'])]
	public function eliminarPregunta(int $id): DataResponse {
		return $this->write(function () use ($id): DataResponse {
			$this->saved->deleteQuestion($id);

			return new DataResponse([]);
		});
	}

	/**
	 * Every tablero, the most recently changed first. tarjetas are its cards
	 * in order: each names a pregunta by id and spans 1, 2 or 3 of the
	 * tablero's columns (ancho). A card's pregunta may since have been
	 * deleted; the card then says so.
	 *
	 * @return DataResponse<Http::STATUS_OK, list<array{id: int, titulo: string, tarjetas: list<array{pregunta: int, ancho: int}>, autor: string, autor_nombre: string, actualizado: int, editable: bool}>, array{}>
	 *
	 * 200: The tableros
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tableros')]
	public function tableros(): DataResponse {
		return new DataResponse($this->saved->listDashboards());
	}

	/**
	 * One tablero.
	 *
	 * @param int $id the tablero's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK, array{id: int, titulo: string, tarjetas: list<array{pregunta: int, ancho: int}>, autor: string, autor_nombre: string, actualizado: int, editable: bool}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: The tablero
	 * 404: No tablero has that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::READ_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tableros/{id}', requirements: ['id' => '\d+'])]
	public function tablero(int $id): DataResponse {
		try {
			return new DataResponse($this->saved->getDashboard($id));
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Saves a tablero, visible to all the staff (D6). Each card must name a
	 * pregunta that exists.
	 *
	 * @param string $titulo what the staff see in the list, up to 200 characters
	 * @param list<array{pregunta: int, ancho?: int}> $tarjetas the cards in order; ancho is 1, 2 or 3 and 1 when left out
	 * @return DataResponse<Http::STATUS_CREATED, array{id: int, titulo: string, tarjetas: list<array{pregunta: int, ancho: int}>, autor: string, autor_nombre: string, actualizado: int, editable: bool}, array{}>|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>
	 *
	 * 201: The stored tablero
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tableros')]
	public function crearTablero(string $titulo, array $tarjetas = []): DataResponse {
		try {
			return new DataResponse($this->saved->createDashboard($titulo, $tarjetas), Http::STATUS_CREATED);
		} catch (RefusedException $e) {
			return $this->refuse($e->getMessage());
		}
	}

	/**
	 * Changes a tablero: what is left out stays as it was, and tarjetas
	 * replaces the whole list. A card it gains must name a pregunta that
	 * exists; one it keeps may name a deleted one. Its creator or whoever
	 * administers Estadística may; anyone else gets a 403.
	 *
	 * @param int $id the tablero's id, as the list served it
	 * @param ?string $titulo a new title
	 * @param ?list<array{pregunta: int, ancho?: int}> $tarjetas the cards in their new order
	 * @return DataResponse<Http::STATUS_OK, array{id: int, titulo: string, tarjetas: list<array{pregunta: int, ancho: int}>, autor: string, autor_nombre: string, actualizado: int, editable: bool}, array{}>|DataResponse<Http::STATUS_FORBIDDEN|Http::STATUS_UNPROCESSABLE_ENTITY, array{message: string}, array{}>|DataResponse<Http::STATUS_NOT_FOUND, list<empty>, array{}>
	 *
	 * 200: The tablero as it now reads
	 * 403: Not this person's to change — {message: the Spanish sentence}
	 * 404: No tablero has that id
	 * 422: Understood and refused — {message: the Spanish sentence}
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/tableros/{id}', requirements: ['id' => '\d+'])]
	public function editarTablero(int $id, ?string $titulo = null, ?array $tarjetas = null): DataResponse {
		return $this->write(fn (): DataResponse => new DataResponse($this->saved->updateDashboard($id, $titulo, $tarjetas)));
	}

	/**
	 * Deletes a tablero; its preguntas stay. Its creator or whoever
	 * administers Estadística may; anyone else gets a 403.
	 *
	 * @param int $id the tablero's id, as the list served it
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_NOT_FOUND, list<empty>, array{}>|DataResponse<Http::STATUS_FORBIDDEN, array{message: string}, array{}>
	 *
	 * 200: Deleted
	 * 403: Not this person's to delete — {message: the Spanish sentence}
	 * 404: No tablero has that id
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: self::WRITE_LIMIT, period: self::PERIOD)]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/tableros/{id}', requirements: ['id' => '\d+'])]
	public function eliminarTablero(int $id): DataResponse {
		return $this->write(function () use ($id): DataResponse {
			$this->saved->deleteDashboard($id);

			return new DataResponse([]);
		});
	}

	/**
	 * A change to a pregunta or a tablero, with its three refusals answered
	 * in one place: not found (404), not this person's (403), and understood
	 * and refused (422).
	 *
	 * @param Closure(): DataResponse $change
	 */
	private function write(Closure $change): DataResponse {
		try {
			return $change();
		} catch (DoesNotExistException) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
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
