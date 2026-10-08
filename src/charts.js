/**
 * How a consulta's answer is drawn: the panels, the table beside them, and
 * the Chart.js configuration of each panel (design, Slice 6).
 *
 * - **One axis.** Every series of a panel shares one y-scale, and the builder
 *   compares only as promedio por establecimiento (query.js metricFor), so
 *   the local series never sits as a flat line under the country's suma.
 * - **Color is the series, never its rank.** Local, nacional and pares keep
 *   their slot in every panel and every answer: a consulta without the local
 *   series does not repaint the country in the local's blue. The three slots
 *   are the dataviz reference palette's first three, which pass every
 *   all-pairs check on Nextcloud's own surfaces (light #ffffff, dark
 *   #171717; design, Slice 6). Light aqua sits at 2.82:1, below 3:1, so the
 *   table always stands under the charts (the relief rule).
 * - **A second dimension is small multiples.** One panel per value of the
 *   second grouping, all on the same scale, so color keeps meaning the
 *   series. Past MAX_PANELS the answer is shown as the table only.
 *
 * Everything but draw() is pure. Only the pieces these charts use are
 * registered, so the rest of Chart.js is tree-shaken out of the bundle.
 */
import {
	BarController, BarElement, CategoryScale, Chart, Legend, LinearScale, LineController, LineElement,
	PointElement, Tooltip,
} from 'chart.js'
import { dimensionName, groupLabel } from './query.js'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement,
	Legend, Tooltip)

/** The series in their fixed slots, with each mode's selected step. */
export const SERIES = [
	{ id: 'local', nombre: 'Su establecimiento', light: '#2a78d6', dark: '#3987e5' },
	{ id: 'nacional', nombre: 'Nacional', light: '#eb6834', dark: '#d95926' },
	{ id: 'pares', nombre: 'Pares', light: '#1baf7a', dark: '#199e70' },
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

/** A tooltip line: the series, its value and, beyond the local one, who reported. */
export function tooltipLabel({ dataset, raw, dataIndex }) {
	const line = `${dataset.label}: ${formatValue(raw)}`
	const informantes = dataset.informantes?.[dataIndex]
	if (dataset.serie === 'local' || !informantes) {
		return line
	}
	return `${line} (${formatValue(informantes)} ${informantes === 1 ? 'establecimiento informó' : 'establecimientos informaron'})`
}

/** Relative luminance of a #rgb/#rrggbb color; null for anything else. */
function luminance(color) {
	const hex = color.trim().replace(/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i, '#$1$1$2$2$3$3')
	if (!/^#[0-9a-f]{6}$/i.test(hex)) {
		return null
	}
	const [r, g, b] = [1, 3, 5].map((i) => {
		const c = parseInt(hex.slice(i, i + 2), 16) / 255
		return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
	})
	return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

/**
 * The chart's colors from Nextcloud's theme variables, read where the chart
 * sits (`read` is a CSS custom-property getter). The mode is SELECTED from
 * the surface the theme paints, whatever setting chose it: each series takes
 * that mode's step, and text and grid wear the theme's own tokens.
 */
export function themeOf(read) {
	const token = (name, fallback) => read(name)?.trim() || fallback
	const surface = token('--color-main-background', '#ffffff')
	const dark = (luminance(surface) ?? 1) < 0.2
	return {
		dark,
		surface,
		text: token('--color-main-text', dark ? '#ebebeb' : '#222222'),
		muted: token('--color-text-maxcontrast', dark ? '#a3a3a3' : '#6b6b6b'),
		grid: token('--color-border', dark ? '#383838' : '#ededed'),
		colors: Object.fromEntries(SERIES.map((serie) => [serie.id, dark ? serie.dark : serie.light])),
	}
}

/**
 * One panel as Chart.js configuration: 24 px bars with a rounded end and a
 * square base, or 2 px lines with 8 px markers ringed in the surface color;
 * a hairline grid; a legend only when there is more than one series; values
 * in es-CL.
 */
export function chartConfig(panel, tipo, max, theme) {
	const line = tipo === 'linea'
	return {
		type: line ? 'line' : 'bar',
		data: {
			labels: panel.labels,
			datasets: panel.datasets.map((dataset) => {
				const color = theme.colors[dataset.serie]
				const common = {
					label: dataset.nombre,
					serie: dataset.serie,
					informantes: dataset.informantes,
					data: dataset.data,
					backgroundColor: color,
				}
				return line
					? {
						...common,
						borderColor: color,
						borderWidth: 2,
						borderCapStyle: 'round',
						borderJoinStyle: 'round',
						pointRadius: 4,
						pointHoverRadius: 6,
						pointBorderWidth: 2,
						pointBorderColor: theme.surface,
					}
					: {
						...common,
						maxBarThickness: 24,
						borderRadius: 4,
						borderSkipped: 'start',
						// The 2 px surface gap between touching bars, not a stroke.
						borderWidth: { left: 1, right: 1 },
						borderColor: theme.surface,
					}
			}),
		},
		options: {
			animation: false,
			maintainAspectRatio: false,
			interaction: { mode: 'index', intersect: false },
			scales: {
				x: {
					grid: { display: false },
					border: { color: theme.grid },
					ticks: { color: theme.muted },
				},
				y: {
					beginAtZero: true,
					suggestedMax: max,
					border: { display: false },
					grid: { color: theme.grid, lineWidth: 1 },
					ticks: { color: theme.muted, callback: (value) => formatValue(value) },
				},
			},
			plugins: {
				legend: {
					display: panel.datasets.length >= 2,
					labels: { color: theme.text, usePointStyle: true, pointStyle: 'circle', boxWidth: 8, boxHeight: 8 },
				},
				tooltip: { callbacks: { label: tooltipLabel } },
			},
		},
	}
}

/** Draws one panel on its canvas. The caller destroys the chart it gets back. */
export function draw(canvas, config) {
	return new Chart(canvas, config)
}
