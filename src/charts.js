/**
 * How a consulta's answer is drawn: the panels, the table beside them, and
 * each panel's series as MetricChart draws them (design, Slice 6; #91).
 *
 * - **One axis.** Every series of a panel shares one y-scale, and the builder
 *   compares only as promedio por establecimiento (query.js metricFor), so
 *   the local series never sits as a flat line under the country's suma.
 * - **Our establishment is the point.** The local series wears the theme's
 *   primary; the country and the peers are context in its muted text colour,
 *   told apart by their dash and named in a legend that is always there.
 * - **A second dimension is small multiples.** One panel per value of the
 *   second grouping, all on the same scale, so a series reads the same in
 *   each. Past MAX_PANELS the answer is shown as the table only.
 *
 * Everything here is pure.
 */
import { dimensionName, groupLabel } from './query.js'

/** The series, in their order in every panel, table and legend. */
const SERIES = [
	{ id: 'local', nombre: 'Su establecimiento' },
	{ id: 'nacional', nombre: 'Nacional' },
	{ id: 'pares', nombre: 'Pares' },
]

export const MAX_PANELS = 12

/** A figure as es-CL writes it, with at most one decimal (a promedio). */
export function formatValue(value) {
	return Number(value).toLocaleString('es-CL', { maximumFractionDigits: 1 })
}

// One collator for every comparison: localeCompare with options builds a new one per call.
const esCollator = new Intl.Collator('es', { numeric: true })

/** null last, numbers by value, text in Spanish order with numbers inside. */
function compare(a, b) {
	if (a === b) {
		return 0
	}
	if (a === null) {
		return 1
	}
	if (b === null) {
		return -1
	}
	if (typeof a === 'number' && typeof b === 'number') {
		return a - b
	}
	return esCollator.compare(String(a), String(b))
}

function compareGroups(a, b) {
	const len = a.length
	for (let i = 0; i < len; i++) {
		const c = compare(a[i], b[i])
		if (c !== 0) {
			return c
		}
	}
	return 0
}

function keyOf(value) {
	return value === null ? '\0' : typeof value === 'number' ? `n${value}` : value
}

function toKey(grupo) {
	return grupo.map(keyOf).join('\x01')
}

function distinct(values) {
	const seen = new Map()
	for (const value of values) {
		seen.set(keyOf(value), value)
	}
	return [...seen.values()].sort(compare)
}

/** The answer's points by series and grupo, and every grupo any series has, in order. */
function indexOf(answer) {
	const present = SERIES.filter((serie) => answer.series[serie.id] !== null && answer.series[serie.id] !== undefined)
	const points = Object.fromEntries(present.map((serie) => [serie.id, new Map()]))
	const groups = new Map()
	for (const serie of present) {
		const map = points[serie.id]
		for (const point of answer.series[serie.id]) {
			const key = toKey(point.grupo)
			map.set(key, point)
			groups.set(key, point.grupo)
		}
	}
	const entries = [...groups.entries()]
		.map(([key, grupo]) => ({ key, grupo }))
		.sort((a, b) => compareGroups(a.grupo, b.grupo))

	return {
		present,
		points,
		entries,
		at: (serieId, grupo) => points[serieId]?.get(toKey(grupo)) ?? null,
	}
}

/**
 * The answer as panels to draw. Without agrupar there is one bar per series;
 * with one dimension, one panel over it; with two, one panel per value of the
 * second. `max` is the highest value in any panel, so every panel shares it.
 */
export function panelsOf(answer, spec) {
	const { present, entries, points, at } = indexOf(answer)
	const groups = entries.map((e) => e.grupo)
	const dims = spec.agrupar

	let max = 0
	for (const serie of present) {
		for (const point of points[serie.id].values()) {
			if (point.valor > max) {
				max = point.valor
			}
		}
	}

	const firsts = dims.length === 0 ? [null] : distinct(groups.map((grupo) => grupo[0]))
	const seconds = dims.length === 2 ? distinct(groups.map((grupo) => grupo[1])) : [undefined]
	if (seconds.length > MAX_PANELS) {
		return { panels: [], max, tooMany: true }
	}

	const panels = seconds.map((second) => {
		const grupoAt = (first) => (dims.length === 0 ? [] : dims.length === 1 ? [first] : [first, second])
		return {
			titulo: dims.length === 2 ? `${dimensionName(dims[1])}: ${groupLabel(dims[1], second, answer.etiquetas)}` : null,
			labels: dims.length === 0 ? ['Todo el periodo'] : firsts.map((first) => groupLabel(dims[0], first, answer.etiquetas)),
			datasets: present.map((serie) => ({
				serie: serie.id,
				nombre: serie.nombre,
				data: firsts.map((first) => at(serie.id, grupoAt(first))?.valor ?? null),
				informantes: firsts.map((first) => at(serie.id, grupoAt(first))?.informantes ?? null),
			})),
		}
	})
	return { panels, max, tooMany: false }
}

/**
 * The same answer as the table that always stands under the charts: one row
 * per grupo, one column per series, side by side (a22).
 */
export function tableOf(answer, spec) {
	const { present, entries, points } = indexOf(answer)
	return {
		columnas: spec.agrupar.map(dimensionName),
		series: present.map(({ id, nombre }) => ({ id, nombre })),
		filas: entries.map(({ key, grupo }) => ({
			grupo: grupo.length === 0 ? ['Todo el periodo'] : grupo.map((value, i) => groupLabel(spec.agrupar[i], value, answer.etiquetas)),
			celdas: present.map((serie) => points[serie.id].get(key) ?? null),
		})),
	}
}

/** Who reported a value, beyond the local series: «1.204 establecimientos informaron». */
function reported(serie, informantes) {
	if (serie === 'local' || !informantes) {
		return null
	}
	return `${formatValue(informantes)} ${informantes === 1 ? 'establecimiento informó' : 'establecimientos informaron'}`
}

/** A panel's series as MetricChart's: ours emphasised, each value with who reported it. */
export function linesOf(panel) {
	return panel.datasets.map((dataset) => ({
		id: dataset.serie,
		name: dataset.nombre,
		emphasis: dataset.serie === 'local',
		points: dataset.data.map((v, i) => ({ i, v, detail: reported(dataset.serie, dataset.informantes[i]) })),
	}))
}
