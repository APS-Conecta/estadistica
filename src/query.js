/**
 * The builder's half of the consulta (docs/adr/0006): its four steps, Datos →
 * Filtrar → Resumir → Visualizar, and the one spec they become. The builder
 * posts that spec to the OCS route, so the page shows exactly what the API
 * answers (a15).
 *
 * Everything here but the three calls at the bottom is pure, so the spec the
 * builder emits is pinned in node (query.test.js, a7). The server's
 * QuerySpec::from() stays the judge: this module only avoids asking what it
 * would refuse.
 */
import { ocsGet, ocsPost } from './api.js'

/** Mirrors QuerySpec::MAX_DIMENSIONS. */
export const MAX_DIMENSIONS = 2

/** The grouping dimensions (QuerySpec::DIMENSIONS), in Spanish. */
export const DIMENSIONS = [
	{ id: 'anio', nombre: 'Año' },
	{ id: 'mes', nombre: 'Mes' },
	{ id: 'codigo', nombre: 'Prestación' },
	{ id: 'columna', nombre: 'Columna' },
	{ id: 'tipo', nombre: 'Tipo de establecimiento' },
	{ id: 'dependencia', nombre: 'Dependencia' },
	{ id: 'nivel', nombre: 'Nivel de atención' },
	{ id: 'servicio', nombre: 'Servicio de salud' },
	{ id: 'region', nombre: 'Región' },
	{ id: 'comuna', nombre: 'Comuna' },
]

/**
 * The traits a peer group is chosen by (QuerySpec::TRAITS), each with the key
 * its values carry in the establecimientos catálogo (Register::dimensions).
 * comuna has no values list: it is offered only as the establishment's own.
 */
export const TRAITS = [
	{ id: 'tipo', valores: 'tipos' },
	{ id: 'dependencia', valores: 'dependencias' },
	{ id: 'nivel', valores: 'niveles' },
	{ id: 'servicio', valores: 'servicios' },
	{ id: 'region', valores: 'regiones' },
	{ id: 'comuna', valores: null },
]

/** How an answer is drawn. */
export const CHART_TYPES = [
	{ id: 'barra', nombre: 'Barras' },
	{ id: 'linea', nombre: 'Líneas' },
	{ id: 'tabla', nombre: 'Solo tabla' },
]

const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
	'septiembre', 'octubre', 'noviembre', 'diciembre']

/** 'AAAA-MM' as a person reads it: «agosto de 2026». */
export function monthName(yearMonth) {
	const [year, month] = String(yearMonth).split('-')
	return `${MONTHS[Number(month) - 1] ?? month} de ${year}`
}

export function dimensionName(id) {
	return DIMENSIONS.find((dimension) => dimension.id === id)?.nombre ?? id
}

/**
 * The months a REM fuente can be asked about, oldest first: every month of
 * each loaded year up to that year's fecha de corte, so no choice points at
 * figures the install does not hold.
 */
export function monthsOf(fuente) {
	const months = []
	for (const anio of [...(fuente?.anios ?? [])].filter((a) => a.filas > 0).sort((a, b) => a.anio - b.anio)) {
		const last = anio.fecha_corte ? Number(anio.fecha_corte.split('-')[1]) : 12
		for (let month = 1; month <= last; month++) {
			const id = `${anio.anio}-${String(month).padStart(2, '0')}`
			months.push({ id, label: monthName(id) })
		}
	}
	return months
}

/**
 * The Filtrar step as a fuente starts it: its newest year, from January to
 * the fecha de corte, and nothing chosen yet.
 */
export function freshFilters(fuente) {
	const newest = monthsOf(fuente).at(-1)?.id ?? null
	return {
		desde: newest === null ? null : `${newest.slice(0, 4)}-01`,
		hasta: newest,
		hoja: null,
		seccion: null,
		codigos: [],
		columnas: [],
	}
}

/**
 * A fresh builder over the fuentes: the first REM fuente holding figures,
 * grouped by month and compared with the country.
 */
export function initialState(fuentes) {
	const fuente = fuentes.find((f) => f.id.startsWith('rem-') && f.filas > 0) ?? null
	return {
		datos: { fuente: fuente?.id ?? null },
		filtrar: freshFilters(fuente),
		resumir: { agrupar: ['mes'], metrica: 'suma', nacional: true, pares: {} },
		visualizar: { tipo: 'barra' },
	}
}

/** The peer traits actually chosen, in QuerySpec::TRAITS order. */
function chosenPeers(pares) {
	const chosen = {}
	for (const { id } of TRAITS) {
		const value = pares?.[id]
		if (value === true || (typeof value === 'string' && value.trim() !== '')) {
			chosen[id] = value === true ? true : value.trim()
		}
	}
	return chosen
}

/**
 * How many series the consulta will answer: the local one when the install
 * has its establishment, the national one, the peers'.
 */
export function seriesCount(resumir, configured) {
	return (configured ? 1 : 0) + (resumir.nacional ? 1 : 0) + (Object.keys(chosenPeers(resumir.pares)).length > 0 ? 1 : 0)
}

/**
 * Two or more series share one axis, so they compare as promedio por
 * establecimiento: the local promedio IS its suma (one establishment), and
 * the country's suma would sit thousands of times above it (design, Slice 6).
 * One series may be summed. The API still accepts suma with comparar; the
 * builder only never asks for it.
 */
export function metricFor(resumir, configured) {
	return seriesCount(resumir, configured) >= 2 ? 'promedio_establecimiento' : resumir.metrica
}

/**
 * Why the builder cannot ask yet, in Spanish, or '' when it can. The server
 * refuses the same things; this keeps the button honest.
 */
export function missing(state, configured) {
	const { datos, filtrar, resumir } = state
	if (!datos.fuente) {
		return 'Todavía no hay cifras REM cargadas.'
	}
	if (!filtrar.desde || !filtrar.hasta) {
		return 'Elija el periodo.'
	}
	if (filtrar.desde > filtrar.hasta) {
		return 'El periodo termina antes de empezar.'
	}
	if (!filtrar.hoja) {
		return 'Elija una hoja del REM.'
	}
	if (filtrar.seccion === null) {
		return 'Elija una sección de la hoja.'
	}
	if (filtrar.columnas.length === 0) {
		return 'Elija al menos una columna.'
	}
	if (resumir.agrupar.length > MAX_DIMENSIONS) {
		return `Se agrupa por ${MAX_DIMENSIONS} dimensiones como máximo.`
	}
	if (seriesCount(resumir, configured) === 0) {
		return 'Compare con el país o con pares: esta instalación no tiene su establecimiento configurado.'
	}
	return ''
}

/**
 * The spec the four steps ask, in QuerySpec's key order. `catalogo` is the
 * fuente's catálogo the Filtrar step browsed (Catalog::tree): a sección with
 * no name cannot be named in the spec, so it is sent as its códigos. The
 * Visualizar step never enters the spec — a chart type is how an answer is
 * drawn, not what is asked, and QuerySpec refuses a key it does not know.
 */
export function specFrom(state, { configured, catalogo }) {
	const { datos, filtrar, resumir } = state
	const filtros = { hoja: filtrar.hoja }
	if (filtrar.seccion) {
		filtros.seccion = filtrar.seccion
	}
	if (filtrar.codigos.length > 0) {
		filtros.codigos = [...filtrar.codigos]
	} else if (!filtrar.seccion) {
		filtros.codigos = seccionOf(catalogo, filtrar.hoja, filtrar.seccion)?.prestaciones.map((p) => p.codigo) ?? []
	}
	filtros.columnas = [...filtrar.columnas].sort()

	const spec = {
		fuente: datos.fuente,
		periodo: { desde: filtrar.desde, hasta: filtrar.hasta },
		filtros,
		agrupar: [...resumir.agrupar],
		metrica: metricFor(resumir, configured),
	}
	const pares = chosenPeers(resumir.pares)
	if (resumir.nacional || Object.keys(pares).length > 0) {
		spec.comparar = { nacional: resumir.nacional }
		if (Object.keys(pares).length > 0) {
			spec.comparar.pares = pares
		}
	}
	return spec
}

/** A hoja of the catálogo, or null. */
export function hojaOf(catalogo, hoja) {
	return catalogo?.hojas.find((h) => h.hoja === hoja) ?? null
}

/** A sección of a hoja, or null. */
export function seccionOf(catalogo, hoja, seccion) {
	return hojaOf(catalogo, hoja)?.secciones.find((s) => s.seccion === seccion) ?? null
}

/**
 * One grupo value as a person reads it, from the answer's etiquetas: a month
 * by name, a código by its prestación, a column by its header, a trait the
 * register lacks as «sin dato».
 */
export function groupLabel(dimension, value, etiquetas) {
	if (value === null) {
		return 'sin dato'
	}
	switch (dimension) {
	case 'mes':
		return monthName(value)
	case 'codigo': {
		const label = etiquetas?.codigos.find((c) => c.codigo === value)
		return label ? `${label.prestacion} (${value})` : String(value)
	}
	case 'columna': {
		const label = etiquetas?.codigos.map((c) => c.columnas[value]).find(Boolean)
		return label ? `${label} (${value})` : String(value)
	}
	default:
		return String(value)
	}
}

/** The fuentes this install holds (ApiController::fuentes). */
export function loadSources() {
	return ocsGet('fuentes')
}

/** One fuente with its catálogo; for REM, `anio`'s (ApiController::fuente). */
export function loadSource(id, anio = null) {
	return ocsGet(`fuentes/${id}`, anio === null ? {} : { anio })
}

/**
 * Asks one consulta (ApiController::consultas). The body never names an
 * establishment: the local series is this install's, from app config
 * (gestion ADR-0013) — there is no «ours» to choose in the page.
 */
export function consult(spec) {
	return ocsPost('consultas', { consulta: spec })
}
