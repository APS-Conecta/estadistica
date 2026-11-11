// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { MAX_PANELS } from '../charts.js'
import Visualization from './Visualization.vue'

vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => url }))

const point = (grupo, valor, informantes = 1) => ({ grupo, valor, informantes })

const CODIGO = { codigo: '02010420', hoja: 'A03', seccion: 'SECCIÓN A', prestacion: 'Evaluación', columnas: { col08: '20-24' }, nota: null }

/** QueryExecutor::response, with the series and labels given. */
function answer({ series = {}, etiquetas = {}, fuente = {} } = {}) {
	return {
		series: { local: null, nacional: null, pares: null, ...series },
		etiquetas: { local: '900001', pares: null, codigos: [CODIGO], ...etiquetas },
		fuente: { nombre: 'REM Serie A', origen: 'datos públicos DEIS', fecha_corte: '2026-08', estado: 'ok', ...fuente },
	}
}

const MONTHS = { local: [point(['2026-01'], 2), point(['2026-02'], 4)], nacional: [point(['2026-01'], 3.5, 1204)] }

function shown(props) {
	return mount(Visualization, { props: { spec: { agrupar: ['mes'], metrica: 'suma' }, tipo: 'barra', ...props } })
}

describe('Visualization — one answer, drawn and told', () => {
	it('draws a panel, tabulates it, and says where the figures come from', () => {
		const wrapper = shown({ answer: answer({ series: MONTHS }) })

		expect(wrapper.findAll('.estadistica-chart')).toHaveLength(1)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(wrapper.text()).toContain('Fuente: REM Serie A, datos públicos DEIS, con cifras hasta agosto de 2026.')
		expect(wrapper.text()).toContain('Suma de lo informado; entre paréntesis, los establecimientos que informaron.')
		expect(wrapper.text()).toContain('Su establecimiento es el de código DEIS 900001')
		expect(wrapper.text()).toContain('Qué se contó: 1 prestación')
		wrapper.unmount()
	})

	it('shows only the table for a tabla, and draws nothing', () => {
		const wrapper = shown({ answer: answer({ series: MONTHS }), tipo: 'tabla' })

		expect(wrapper.findAll('.estadistica-chart')).toHaveLength(0)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		wrapper.unmount()
	})

	it('says there are no figures instead of drawing an empty chart', () => {
		const wrapper = shown({ answer: answer({ series: { local: [], nacional: [] } }) })

		expect(wrapper.findAll('.estadistica-chart')).toHaveLength(0)
		expect(wrapper.text()).toContain('No hay cifras para esta consulta')
		expect(wrapper.find('table').exists()).toBe(false)
		wrapper.unmount()
	})

	it(`sends more than ${MAX_PANELS} values of the second dimension to the table`, () => {
		const local = Array.from({ length: MAX_PANELS + 1 }, (_, i) => point(['2026-01', `0201042${i}`], i + 1))
		const wrapper = shown({ answer: answer({ series: { local } }), spec: { agrupar: ['mes', 'codigo'], metrica: 'suma' } })

		expect(wrapper.text()).toContain(`Hay más de ${MAX_PANELS} valores de «Prestación» para dibujar uno por uno: se muestran en la tabla.`)
		expect(wrapper.findAll('tbody tr')).toHaveLength(MAX_PANELS + 1)
		wrapper.unmount()
	})

	it('warns when the fuente is incompleto, names the peers, and says what an average is', () => {
		const wrapper = shown({
			answer: answer({ series: MONTHS, etiquetas: { pares: { tipo: 'CESFAM' }, codigos: [CODIGO, { ...CODIGO, codigo: '03500366' }] }, fuente: { estado: 'incompleto' } }),
			spec: { agrupar: ['mes'], metrica: 'promedio_establecimiento' },
		})

		expect(wrapper.text()).toContain('Algún año de esta fuente está incompleto')
		expect(wrapper.text()).toContain('Pares: los establecimientos con tipo de establecimiento «CESFAM».')
		expect(wrapper.text()).toContain('Promedio por establecimiento: la suma dividida por los establecimientos que informaron')
		expect(wrapper.text()).toContain('Qué se contó: 2 prestaciones')
		wrapper.unmount()
	})
})

// Every series of the answer, with two establishments behind the country's and twelve behind the peers'.
const ALL = {
	local: [point(['2026-01'], 2), point(['2026-02'], 4)],
	nacional: [point(['2026-01'], 3.5, 1204), point(['2026-02'], 5, 1190)],
	pares: [point(['2026-01'], 3, 12)],
}

describe('Visualization — every series drawn by the app, named, and in its table twin', () => {
	it('draws a column per value as a keyboard stop that says it, our establishment emphasised, and a legend naming each series', () => {
		const wrapper = shown({ answer: answer({ series: ALL }) })

		const chart = wrapper.find('.estadistica-chart')
		expect(chart.findAll('.estadistica-chart__legend li').map((key) => key.text())).toEqual(['Su establecimiento', 'Nacional', 'Pares'])
		const columns = chart.findAll('[role="img"][tabindex="0"]')
		expect(columns.map((column) => column.attributes('aria-label'))).toEqual([
			'enero de 2026: 2 Su establecimiento',
			'enero de 2026: 3,5 Nacional · 1.204 establecimientos informaron',
			'enero de 2026: 3 Pares · 12 establecimientos informaron',
			'febrero de 2026: 4 Su establecimiento',
			'febrero de 2026: 5 Nacional · 1.190 establecimientos informaron',
		])
		expect(chart.findAll('.estadistica-chart__line--emphasis[role="img"]').map((column) => column.attributes('aria-label')), 'only ours in the primary')
			.toEqual(['enero de 2026: 2 Su establecimiento', 'febrero de 2026: 4 Su establecimiento'])
		wrapper.unmount()
	})

	it('draws a line per series with a dot per value, read by keyboard above the plot', async () => {
		const wrapper = shown({ answer: answer({ series: ALL }), tipo: 'linea' })

		const chart = wrapper.find('.estadistica-chart')
		expect(chart.findAll('.estadistica-chart__legend li').map((key) => key.text())).toEqual(['Su establecimiento', 'Nacional', 'Pares'])
		expect(chart.findAll('svg[role="img"] circle')).toHaveLength(5)
		const plot = chart.find('svg[role="img"]')
		const said = () => chart.find('[aria-live="polite"]').text().replace(/\s+/g, ' ')
		await plot.trigger('focus')
		expect(said()).toBe('febrero de 2026: 4 Su establecimiento 5 Nacional · 1.190 establecimientos informaron — Pares')
		await plot.trigger('keydown', { key: 'ArrowLeft' })
		expect(said()).toBe('enero de 2026: 2 Su establecimiento 3,5 Nacional · 1.204 establecimientos informaron 3 Pares · 12 establecimientos informaron')
		wrapper.unmount()
	})

	it('names a lone series in its legend too, and holds every value in the table twin with who reported it', () => {
		const lone = shown({ answer: answer({ series: { nacional: ALL.nacional } }) })
		expect(lone.findAll('.estadistica-chart__legend li').map((key) => key.text())).toEqual(['Nacional'])
		lone.unmount()

		const wrapper = shown({ answer: answer({ series: ALL }) })
		expect(wrapper.findAll('thead th').map((cell) => cell.text())).toEqual(['Mes', 'Su establecimiento', 'Nacional', 'Pares'])
		expect(wrapper.findAll('tbody tr').map((row) => row.findAll('th, td').map((cell) => cell.text().replace(/\s+/g, ' ')))).toEqual([
			['enero de 2026', '2', '3,5 (1.204)', '3 (12)'],
			['febrero de 2026', '4', '5 (1.190)', '—'],
		])
		wrapper.unmount()
	})
})
