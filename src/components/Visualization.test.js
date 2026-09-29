// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { draw, MAX_PANELS } from '../charts.js'
import Visualization from './Visualization.vue'

// jsdom has no canvas: only Chart.js's own drawing is replaced, every other
// helper (panels, table, theme) is the real one.
vi.mock('../charts.js', async (importOriginal) => ({
	...(await importOriginal()),
	draw: vi.fn(() => ({ destroy: vi.fn() })),
}))
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

beforeEach(() => {
	draw.mockClear()
})

describe('Visualization — one answer, drawn and told', () => {
	it('draws a panel, tabulates it, and says where the figures come from', () => {
		const wrapper = shown({ answer: answer({ series: MONTHS }) })

		expect(draw).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('canvas')).toHaveLength(1)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		expect(wrapper.text()).toContain('Fuente: REM Serie A, datos públicos DEIS, con cifras hasta agosto de 2026.')
		expect(wrapper.text()).toContain('Suma de lo informado; entre paréntesis, los establecimientos que informaron.')
		expect(wrapper.text()).toContain('Su establecimiento es el de código DEIS 900001')
		expect(wrapper.text()).toContain('Qué se contó: 1 prestación')
		wrapper.unmount()
	})

	it('shows only the table for a tabla, and draws nothing', () => {
		const wrapper = shown({ answer: answer({ series: MONTHS }), tipo: 'tabla' })

		expect(draw).not.toHaveBeenCalled()
		expect(wrapper.findAll('canvas')).toHaveLength(0)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
		wrapper.unmount()
	})

	it('says there are no figures instead of drawing an empty chart', () => {
		const wrapper = shown({ answer: answer({ series: { local: [], nacional: [] } }) })

		expect(draw).not.toHaveBeenCalled()
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
