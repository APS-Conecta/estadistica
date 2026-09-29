import { describe, expect, it, vi } from 'vitest'
import { chartConfig, MAX_PANELS, panelsOf, SERIES, tableOf, themeOf, tooltipLabel } from './charts.js'

vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => url }))

const etiquetas = {
	local: '900001',
	pares: null,
	codigos: [{ codigo: '02010420', hoja: 'A03', seccion: 'S', prestacion: 'Test', columnas: { col08: '20-24', col09: '25-29' }, nota: null }],
}

const point = (grupo, valor, informantes = 1) => ({ grupo, valor, informantes })

/** QueryExecutor::response, with the series given. */
function answer(series) {
	return { series: { local: null, nacional: null, pares: null, ...series }, etiquetas, fuente: {} }
}

const LIGHT = themeOf((name) => ({ '--color-main-background': '#ffffff' })[name] ?? '')

describe('panelsOf — what is drawn', () => {
	it('puts local, nacional and pares on one panel over the first dimension, months in order', () => {
		const view = panelsOf(answer({
			local: [point(['2026-02'], 4), point(['2026-01'], 2)],
			nacional: [point(['2026-01'], 3.5, 1204), point(['2026-02'], 5, 1190)],
		}), { agrupar: ['mes'] })

		expect(view.tooMany).toBe(false)
		expect(view.max).toBe(5)
		expect(view.panels).toHaveLength(1)
		expect(view.panels[0].labels).toStrictEqual(['enero de 2026', 'febrero de 2026'])
		expect(view.panels[0].datasets.map((d) => [d.serie, d.data])).toStrictEqual([
			['local', [2, 4]],
			['nacional', [3.5, 5]],
		])
		expect(view.panels[0].datasets[1].informantes).toStrictEqual([1204, 1190])
	})

	it('leaves a month a series did not report empty, never 0', () => {
		const view = panelsOf(answer({
			local: [point(['2026-02'], 4)],
			nacional: [point(['2026-01'], 3), point(['2026-02'], 5)],
		}), { agrupar: ['mes'] })

		expect(view.panels[0].datasets[0].data).toStrictEqual([null, 4])
	})

	it('draws one bar per series without agrupar', () => {
		const view = panelsOf(answer({ local: [point([], 7)], nacional: [point([], 6.5, 900)] }), { agrupar: [] })

		expect(view.panels[0].labels).toStrictEqual(['Todo el periodo'])
		expect(view.panels[0].datasets.map((d) => d.data)).toStrictEqual([[7], [6.5]])
	})

	it('makes one panel per value of the second dimension, every panel with the same series and scale', () => {
		const view = panelsOf(answer({
			local: [point(['2026-01', 'col08'], 1), point(['2026-01', 'col09'], 9)],
			pares: [point(['2026-01', 'col08'], 2, 12), point(['2026-01', 'col09'], 3, 12)],
		}), { agrupar: ['mes', 'columna'] })

		expect(view.max).toBe(9)
		expect(view.panels.map((panel) => panel.titulo)).toStrictEqual(['Columna: 20-24 (col08)', 'Columna: 25-29 (col09)'])
		expect(view.panels.map((panel) => panel.datasets.map((d) => d.serie))).toStrictEqual([['local', 'pares'], ['local', 'pares']])
		expect(view.panels[1].datasets.map((d) => d.data)).toStrictEqual([[9], [3]])
	})

	it('draws nothing past MAX_PANELS values of the second dimension: the table carries them', () => {
		const many = Array.from({ length: MAX_PANELS + 1 }, (_, i) => point(['2026-01', `col${String(i + 1).padStart(2, '0')}`], i))
		const view = panelsOf(answer({ nacional: many }), { agrupar: ['mes', 'columna'] })

		expect(view.tooMany).toBe(true)
		expect(view.panels).toStrictEqual([])
		expect(tableOf(answer({ nacional: many }), { agrupar: ['mes', 'columna'] }).filas).toHaveLength(MAX_PANELS + 1)
	})
})

describe('tableOf — the side-by-side table (a22)', () => {
	it('has one row per grupo and one column per series, with an empty cell where a series has no point', () => {
		const table = tableOf(answer({
			local: [point(['2026-01'], 2)],
			nacional: [point(['2026-01'], 3, 1204), point(['2026-02'], 5, 1190)],
		}), { agrupar: ['mes'] })

		expect(table.columnas).toStrictEqual(['Mes'])
		expect(table.series).toStrictEqual([{ id: 'local', nombre: 'Su establecimiento' }, { id: 'nacional', nombre: 'Nacional' }])
		expect(table.filas).toStrictEqual([
			{ grupo: ['enero de 2026'], celdas: [point(['2026-01'], 2), point(['2026-01'], 3, 1204)] },
			{ grupo: ['febrero de 2026'], celdas: [null, point(['2026-02'], 5, 1190)] },
		])
	})

	it('puts a trait the register lacks last, as «sin dato»', () => {
		const table = tableOf(answer({ nacional: [point([null], 1, 3), point(['CESFAM'], 2, 40)] }), { agrupar: ['tipo'] })

		expect(table.filas.map((fila) => fila.grupo)).toStrictEqual([['CESFAM'], ['sin dato']])
	})
})

describe('chartConfig — each series keeps its color', () => {
	it('paints a series by what it is, not by its place in the answer', () => {
		const view = panelsOf(answer({ nacional: [point(['2026-01'], 3, 10)], pares: [point(['2026-01'], 2, 5)] }), { agrupar: ['mes'] })
		const config = chartConfig(view.panels[0], 'barra', view.max, LIGHT)

		// No local series: the country still wears slot 2, not the local's slot 1.
		expect(config.data.datasets.map((d) => d.backgroundColor)).toStrictEqual([SERIES[1].light, SERIES[2].light])
		expect(config.type).toBe('bar')
		expect(config.options.plugins.legend.display).toBe(true)
		expect(config.options.scales.y.suggestedMax).toBe(3)
	})

	it('takes the dark steps on a dark surface, and shows no legend for one series', () => {
		const dark = themeOf((name) => ({ '--color-main-background': '#171717', '--color-main-text': '#ebebeb' })[name] ?? '')
		const view = panelsOf(answer({ local: [point(['2026-01'], 3)] }), { agrupar: ['mes'] })
		const config = chartConfig(view.panels[0], 'linea', view.max, dark)

		expect(dark.dark).toBe(true)
		expect(config.type).toBe('line')
		expect(config.data.datasets[0].borderColor).toBe(SERIES[0].dark)
		expect(config.data.datasets[0].pointBorderColor).toBe('#171717')
		expect(config.options.plugins.legend.display).toBe(false)
		expect(config.options.plugins.legend.labels.color).toBe('#ebebeb')
	})

	it('says in the tooltip how many establishments reported, beyond the local one', () => {
		const dataset = { label: 'Nacional', serie: 'nacional', informantes: [1204] }

		expect(tooltipLabel({ dataset, raw: 3.25, dataIndex: 0 })).toBe('Nacional: 3,3 (1.204 establecimientos informaron)')
		expect(tooltipLabel({ dataset: { label: 'Su establecimiento', serie: 'local', informantes: [1] }, raw: 4, dataIndex: 0 })).toBe('Su establecimiento: 4')
	})
})
