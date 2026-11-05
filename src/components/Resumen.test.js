// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import { draw, formatValue } from '../charts.js'
import Resumen from './Resumen.vue'

// jsdom has no canvas: only Chart.js's own drawing is replaced (Visualization.test.js).
vi.mock('../charts.js', async (importOriginal) => ({
	...(await importOriginal()),
	draw: vi.fn(() => ({ destroy: vi.fn() })),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

const RESUMEN = '/ocs/v2.php/apps/estadistica/api/v1/resumen'

const ok = (data) => Promise.resolve({ data: { ocs: { meta: { status: 'ok' }, data } } })

const refused = (status, message) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), {
	response: { status, data: { ocs: { meta: { status: 'failure' }, data: message === undefined ? [] : { message } } } },
}))

const meta = (codigo, fields) => ({
	codigo, nombre: `La meta ${codigo}`, indicador: 'Un indicador', meta_nacional: 90, meta_comprometida: null, manual: false,
	estado: null, mes: null, datos_al: null, numerador: null, denominador: null, porcentaje: null, cumple: null, historico: [],
	origen: null, fecha_corte: null, ...fields,
})

/** GET resumen for 900001: cumple against a commitment, misses the nacional, a Serie P figure older than its month, both statuses, sin informar and Meta VIII. */
const answer = () => ({
	anio: 2026,
	nombre: 'Metas Sanitarias 2026',
	referencia: 'MINSAL, Orientaciones Técnicas Metas Sanitarias 2026, Anexo 3.',
	establecimiento: '900001',
	metas: [
		meta('I', {
			nombre: 'Recuperación del desarrollo psicomotor', meta_comprometida: 50, mes: '2026-10', datos_al: '2026-10', numerador: 7, denominador: 12, porcentaje: 58.33, cumple: true,
			origen: 'datos públicos DEIS', fecha_corte: '2026-11',
		}),
		meta('V', { meta_nacional: 45, mes: '2026-06', datos_al: '2026-06', numerador: 1287, denominador: 3500.5, porcentaje: 36.77, cumple: false, origen: 'datos públicos DEIS', fecha_corte: '2026-11' }),
		meta('VII', { meta_nacional: 55, mes: '2026-11', datos_al: '2026-06', numerador: 30, denominador: 60, porcentaje: 50, cumple: false }),
		meta('II', { estado: 'falta el denominador oficial de 2026' }),
		meta('IV.A', { estado: 'esperando ruta oficial' }),
		meta('VI'),
		meta('VIII', { manual: true, meta_nacional: 100, porcentaje: 100, cumple: true }),
	],
})

async function shown(response = ok(answer()), options = {}) {
	axios.get.mockReturnValueOnce(response)
	const wrapper = mount(Resumen, options)
	await flushPromises()
	return wrapper
}

const card = (wrapper, codigo) => wrapper.findAll('article').find((article) => article.find('h3').text().startsWith(`Meta ${codigo} `))

beforeEach(() => {
	axios.get.mockReset()
	draw.mockClear()
})

describe('Resumen — the newest metas, one card each (docs/adr/0009)', () => {
	it('reads GET resumen alone and names each card by its meta and the metas year', async () => {
		const wrapper = await shown()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(RESUMEN, { params: {} })
		expect(wrapper.find('h2').text()).toBe('Metas Sanitarias 2026')
		expect(wrapper.findAll('article h3').map((title) => title.text())).toEqual(['Meta I 2026', 'Meta V 2026', 'Meta VII 2026', 'Meta II 2026', 'Meta IV.A 2026', 'Meta VI 2026', 'Meta VIII 2026'])
		wrapper.unmount()
	})

	it('shows the percentage, «N de D», «datos al» and whether it cumple, in words', async () => {
		const wrapper = await shown()

		const first = card(wrapper, 'I').text()
		expect(first).toContain('Recuperación del desarrollo psicomotor')
		expect(first).toContain('58,3 %')
		expect(first).toContain('7 de 12')
		expect(first).toContain('Datos al octubre de 2026 · datos públicos DEIS, con cifras hasta noviembre de 2026')
		expect(first).toContain('Cumple la meta comprometida (50 %)')
		expect(card(wrapper, 'V').text()).toContain('1.287 de 3.500,5')
		expect(card(wrapper, 'V').text()).toContain('No cumple la meta nacional (45 %)')
		wrapper.unmount()
	})

	it('dates a card by the establishment\'s last month in the meta\'s serie, not by the month of its figure', async () => {
		const wrapper = await shown()

		const serieP = card(wrapper, 'VII').text()
		expect(serieP).toContain('30 de 60')
		expect(serieP).toContain('Datos al junio de 2026')
		expect(serieP).not.toContain('noviembre')
		wrapper.unmount()
	})

	it('says why a meta shows no figure, and a meta sin informar is never 0', async () => {
		const wrapper = await shown()

		expect(card(wrapper, 'II').text()).toContain('Sin porcentaje: falta el denominador oficial de 2026.')
		expect(card(wrapper, 'IV.A').text()).toContain('Sin porcentaje: esperando ruta oficial.')
		expect(card(wrapper, 'VI').text()).toContain('Sin informar')
		for (const codigo of ['II', 'IV.A', 'VI']) {
			expect(card(wrapper, codigo).text()).not.toMatch(/\d %|Cumple/)
		}
		expect(card(wrapper, 'VIII').text()).toContain('Cumplimiento informado: 100 %')
		expect(card(wrapper, 'VIII').text()).toContain('Cumple la meta nacional (100 %)')
		wrapper.unmount()
	})

	it('shows cumple with colour and text, never colour alone', async () => {
		const wrapper = await shown()

		expect(card(wrapper, 'I').classes()).toContain('estadistica-resumen__meta--cumple')
		expect(card(wrapper, 'V').classes()).toContain('estadistica-resumen__meta--no-cumple')
		expect(card(wrapper, 'VI').classes()).not.toContain('estadistica-resumen__meta--cumple')
		expect(card(wrapper, 'VI').classes()).not.toContain('estadistica-resumen__meta--no-cumple')
		wrapper.unmount()
	})

	it('lets a keyboard reach every card, named by its title and described by its figure or status', async () => {
		const wrapper = await shown()

		for (const article of wrapper.findAll('article')) {
			expect(article.attributes('tabindex')).toBe('0')
			expect(wrapper.find(`[id="${article.attributes('aria-labelledby')}"]`).element.tagName).toBe('H3')
			expect(wrapper.find(`[id="${article.attributes('aria-describedby')}"]`).text()).not.toBe('')
		}
		expect(wrapper.find(`[id="${card(wrapper, 'IV.A').attributes('aria-describedby')}"]`).text()).toContain('esperando ruta oficial')
		wrapper.unmount()
	})

	it('says when no metas are loaded, and a failure in the API\'s own words', async () => {
		const missing = await shown(refused(404))
		expect(missing.text()).toContain('Todavía no hay Metas Sanitarias cargadas.')
		missing.unmount()

		const failed = await shown(refused(422, 'La consulta tardó más de 10 s y se canceló.'))
		expect(failed.findComponent({ name: 'NcNoteCard' }).props('type')).toBe('error')
		expect(failed.text()).toContain('La consulta tardó más de 10 s y se canceló.')
		failed.unmount()
	})
})

/** One year of a histórico: each month's [numerador, denominador, porcentaje], null where sin informar. */
const year = (anio, figures, fields = {}) => ({
	anio,
	estado: null,
	cierre: null,
	meses: figures.map(([numerador, denominador, porcentaje], i) => ({ mes: `${anio}-${String(i + 1).padStart(2, '0')}`, numerador, denominador, porcentaje })),
	...fields,
})

/** Meta I's histórico: 2024 does not compare, 2025 is whole with its cierre, 2026 runs to October with February sin informar. */
const withHistorico = () => {
	const resumen = answer()
	resumen.metas[0].historico = [
		year(2024, [], { estado: 'sin datos comparables' }),
		year(2025, Array.from({ length: 12 }, (_, i) => [i + 1, 10, (i + 1) * 10]), { cierre: 120 }),
		year(2026, [[1, 4, 25], [null, null, null], [3, 6, 50], ...Array.from({ length: 7 }, () => [7, 12, 58.33])]),
	]
	return resumen
}

describe('Resumen — each card\'s histórico (docs/adr/0009)', () => {
	it('draws one line per year with figures, the current year emphasised, and the meta as a line', async () => {
		const wrapper = await shown(ok(withHistorico()))

		expect(draw).toHaveBeenCalledTimes(1)
		const { data } = draw.mock.calls[0][1]
		expect(data.labels).toEqual(['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'])
		expect(data.datasets.map((dataset) => dataset.label)).toEqual(['2025', '2026 (más reciente)', 'Meta comprometida (50 %)'])
		const [past, current, metaLine] = data.datasets
		expect(current.borderWidth).toBeGreaterThan(past.borderWidth)
		expect(metaLine.data).toEqual(Array(12).fill(50))
		wrapper.unmount()
	})

	it('leaves sin informar as a gap in the chart and says so in the table, never 0', async () => {
		const wrapper = await shown(ok(withHistorico()))

		const current = draw.mock.calls[0][1].data.datasets[1]
		expect(current.data.slice(0, 4)).toEqual([25, null, 50, 58.33])
		expect(current.data.slice(10)).toEqual([null, null])
		const february = card(wrapper, 'I').findAll('tbody tr')[1].findAll('td').map((cell) => cell.text())
		expect(february).toEqual(['20 % (2 de 10)', 'sin informar'])
		wrapper.unmount()
	})

	it('puts every figure the chart draws in the table under it, with each year\'s cierre', async () => {
		const wrapper = await shown(ok(withHistorico()))

		const table = card(wrapper, 'I').find('table')
		expect(table.findAll('thead th').map((cell) => cell.text())).toEqual(['Mes', '2025', '2026 (más reciente)'])
		const { datasets } = draw.mock.calls[0][1].data
		const drawn = withHistorico().metas[0].historico.slice(1)
		const rows = table.findAll('tbody tr')
		expect(rows).toHaveLength(12)
		rows.forEach((row, month) => {
			row.findAll('td').forEach((cell, line) => {
				const value = datasets[line].data[month]
				const empty = month < drawn[line].meses.length ? 'sin informar' : '—'
				expect(cell.text().startsWith(value === null ? empty : `${formatValue(value)} %`)).toBe(true)
			})
		})
		expect(table.findAll('tfoot td').map((cell) => cell.text())).toEqual(['120 %', '—'])
		expect(card(wrapper, 'I').text()).toContain('2024: sin datos comparables.')
		wrapper.unmount()
	})

	it('draws nothing for a meta with no histórico', async () => {
		const wrapper = await shown()

		expect(draw).not.toHaveBeenCalled()
		expect(wrapper.find('canvas').exists()).toBe(false)
		wrapper.unmount()
	})
})

/** A código as that year's diccionario labels it (QueryExecutor's Etiqueta). */
const etiqueta = (codigo, prestacion, fields) => ({
	codigo, hoja: 'A03', seccion: 'SECCIÓN C: EVALUACIÓN DEL DESARROLLO PSICOMOTOR', prestacion,
	columnas: { col08: '7 a 11 meses', col09: '12 a 17 meses' }, nota: null, ...fields,
})

/** Meta I's ruta moved onto `anio`, its first código labelled `prestacion` by that year's diccionario. */
const rutaI = (anio, prestacion) => ({
	numerador: {
		fuente: 'rem-a', nombre: 'REM Serie A', desde: `${anio}-01`, hasta: `${anio}-12`, restar: [],
		// A código no diccionario names: «sin descripción», and no hoja.
		sumar: [[etiqueta('02010420', prestacion), { codigo: '09000001', hoja: '', seccion: '', prestacion: 'sin descripción', columnas: { col08: 'sin descripción' }, nota: null }]],
	},
	denominador: {
		fuente: 'rem-a', nombre: 'REM Serie A', desde: `${anio - 1}-10`, hasta: `${anio}-09`,
		sumar: [[etiqueta('02010321', 'Con rezago'), etiqueta('02010322', 'Con riesgo', { columnas: { col10: '18 a 23 meses' } })]],
		restar: [[etiqueta('03500331', 'Trasladados', { hoja: 'A05', seccion: 'SECCIÓN B: EGRESOS', nota: 'etiqueta de 2027' })]],
	},
})

/** withHistorico() with its sources: each month's origen, each year's ruta (2025's diccionario writes capitals), and Meta V over FONASA's inscritos. */
const withSources = () => {
	const resumen = withHistorico()
	const [i, v] = resumen.metas
	for (const one of i.historico) {
		one.ruta = rutaI(one.anio, one.anio === 2025 ? 'RECUPERADOS' : 'Recuperados')
		one.meses.forEach((mes) => Object.assign(mes, { origen: 'datos públicos DEIS', fecha_corte: one.anio === 2025 ? '2025-12' : '2026-11' }))
	}
	v.historico = [year(2026, [], {
		meses: [{ mes: '2026-06', numerador: 1287, denominador: 3500.5, porcentaje: 36.77, origen: 'datos públicos DEIS', fecha_corte: '2026-11' }],
		ruta: {
			numerador: { fuente: 'rem-p', nombre: 'REM Serie P', desde: '2026-01', hasta: '2026-12', sumar: [[etiqueta('P4150602', 'Con HbA1c bajo 7 %', { hoja: 'P4', seccion: 'SECCIÓN B: METAS', columnas: { col01: 'Total' } })]], restar: [] },
			denominador: { fuente: 'piv', nombre: 'Población inscrita validada (FONASA)', desde: null, hasta: null, sumar: [], restar: [] },
		},
	})]
	return resumen
}

describe('Resumen — the source panel of every figure (docs/adr/0009)', () => {
	beforeEach(() => {
		// NcContent's root, where the app sidebar sits beside the content.
		document.body.innerHTML = '<div id="content-vue"><div id="resumen"></div></div>'
	})

	const mounted = () => shown(ok(withSources()), { attachTo: '#resumen' })

	const panel = (wrapper) => wrapper.findComponent(NcAppSidebar)

	/** The card's own figures: its %, N and D, or its cumplimiento informado. */
	const figures = (wrapper, codigo) => card(wrapper, codigo).find(`[id="resumen-${codigo}-cifra"]`).findAll('button')

	const opened = async (wrapper, figure) => {
		figure.element.focus()
		await figure.trigger('click')
		// The panel is its own chunk, fetched on the first click.
		await vi.waitFor(() => expect(panel(wrapper).exists()).toBe(true))
		return panel(wrapper)
	}

	it('opens from each figure of a card, in the app sidebar, for that figure\'s month', async () => {
		const wrapper = await mounted()

		expect(figures(wrapper, 'I').map((figure) => figure.text())).toEqual(['58,3 %', '7', '12'])
		for (const figure of figures(wrapper, 'I')) {
			const sidebar = await opened(wrapper, figure)
			expect(sidebar.element.parentElement.id).toBe('content-vue')
			expect(sidebar.props('name')).toBe('Meta I 2026')
			expect(sidebar.text()).toContain('Cifra de octubre de 2026')
		}
		expect((await opened(wrapper, figures(wrapper, 'V')[0])).text()).toContain('Cifra de junio de 2026')
		expect((await opened(wrapper, figures(wrapper, 'VIII')[0])).text()).toContain('Lo informa quien administra Estadística: no se cuenta en el REM.')
		wrapper.unmount()
	})

	it('shows the stored ruta: hoja, sección, códigos and columnas as that year\'s diccionario labels them, the periodo, what is added and taken away, the origen with its date and the referencia', async () => {
		const wrapper = await mounted()

		const text = (await opened(wrapper, figures(wrapper, 'I')[0])).text()
		expect(text).toContain('Origen: datos públicos DEIS, con cifras hasta noviembre de 2026.')
		expect(text).toContain('REM Serie A, de enero de 2026 a diciembre de 2026.')
		expect(text).toContain('REM Serie A, de octubre de 2025 a septiembre de 2026.')
		expect(text).toContain('MINSAL, Orientaciones Técnicas Metas Sanitarias 2026, Anexo 3.')
		const codigos = panel(wrapper).findAll('.estadistica-fuente__codigo').map((codigo) => codigo.text())
		expect(codigos).toHaveLength(5)
		expect(codigos[0]).toContain('02010420 Recuperados')
		expect(codigos[0]).toContain('Hoja A03, SECCIÓN C: EVALUACIÓN DEL DESARROLLO PSICOMOTOR')
		expect(codigos[0]).toContain('col08: 7 a 11 meses')
		expect(codigos[0]).toContain('col09: 12 a 17 meses')
		expect(codigos[1]).toBe('09000001 sin descripción col08: sin descripción')
		expect(codigos[3]).toContain('col10: 18 a 23 meses')
		expect(codigos[3]).not.toContain('col08')
		expect(codigos[4]).toContain('Hoja A05, SECCIÓN B: EGRESOS (etiqueta de 2027)')
		const signs = panel(wrapper).findAll('.estadistica-fuente__signo').map((sign) => sign.text())
		expect(signs).toEqual(['Suma:', 'Suma:', 'Resta:'])
		wrapper.unmount()
	})

	it('opens from each figure of the table, for its own month, with that year\'s ruta and origen', async () => {
		const wrapper = await mounted()

		const cells = card(wrapper, 'I').findAll('tbody td')
		expect(cells.filter((cell) => cell.find('button').exists())).toHaveLength(12 + 9)
		expect(cells.find((cell) => cell.text() === 'sin informar').find('button').exists()).toBe(false)
		const march2025 = card(wrapper, 'I').findAll('tbody tr')[2].findAll('td')[0]
		const text = (await opened(wrapper, march2025.find('button'))).text()
		expect(text).toContain('Cifra de marzo de 2025')
		expect(text).toContain('Origen: datos públicos DEIS, con cifras hasta diciembre de 2025.')
		expect(text).toContain('REM Serie A, de octubre de 2024 a septiembre de 2025.')
		expect(text).toContain('02010420 RECUPERADOS')
		wrapper.unmount()
	})

	it('opens from a year\'s cierre, for its December with that year\'s ruta', async () => {
		const wrapper = await mounted()

		const [cierre2025, cierre2026] = card(wrapper, 'I').findAll('tfoot td')
		expect(cierre2026.find('button').exists(), 'a year not over has no cierre').toBe(false)
		const text = (await opened(wrapper, cierre2025.find('button'))).text()
		expect(text).toContain('Cifra de diciembre de 2025')
		expect(text).toContain('Origen: datos públicos DEIS, con cifras hasta diciembre de 2025.')
		expect(text).toContain('02010420 RECUPERADOS')
		wrapper.unmount()
	})

	it('says when a denominador is not counted in the REM', async () => {
		const wrapper = await mounted()

		const text = (await opened(wrapper, figures(wrapper, 'V')[2])).text()
		expect(text).toContain('REM Serie P, de enero de 2026 a diciembre de 2026.')
		expect(text).toContain('Población inscrita validada (FONASA).')
		wrapper.unmount()
	})

	it('is reached by keyboard, closes with Escape and gives the focus back to the figure, on the card, in the table and at a cierre', async () => {
		const wrapper = await mounted()

		const table = card(wrapper, 'I').findAll('tbody tr')[2].findAll('td')[0].find('button')
		const cierre = card(wrapper, 'I').findAll('tfoot td')[0].find('button')
		const first = await opened(wrapper, figures(wrapper, 'V')[0])
		for (const [figure, month] of [[figures(wrapper, 'I')[1], 'octubre de 2026'], [table, 'marzo de 2025'], [cierre, 'diciembre de 2025']]) {
			expect(figure.element.tagName).toBe('BUTTON')
			expect((await opened(wrapper, figure)).text()).toContain(`Cifra de ${month}`)
			expect(first.exists(), 'each figure opens a panel of its own').toBe(false)
			expect(panel(wrapper).element.contains(document.activeElement), 'the focus moves into the panel').toBe(true)
			await panel(wrapper).find('aside').trigger('keydown', { key: 'Escape' })
			await flushPromises()

			expect(panel(wrapper).exists(), `Escape closes the ${month} panel`).toBe(false)
			expect(document.activeElement, `the focus is back on the ${month} figure`).toBe(figure.element)
		}
		wrapper.unmount()
	})
})

