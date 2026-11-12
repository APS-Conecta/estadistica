// @vitest-environment jsdom
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import { mdiAlertCircleOutline, mdiCheck, mdiClockOutline, mdiClose, mdiMinusCircleOutline } from '../icons.js'
import Resumen from './Resumen.vue'

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
	origen: null, fecha_corte: null, meta_minima: null, ...fields,
})

/** GET resumen for 900001: cumple against a commitment, misses the nacional, a Serie P figure older than its month, both estados (II has counted its numerador), sin informar and Meta VIII. */
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
		meta('II', { estado: 'falta el denominador oficial de 2026', historico: [year(2026, [[40, null, null], [140, null, null], [null, null, null]])] }),
		meta('IV.A', { estado: 'esperando ruta oficial' }),
		meta('VI'),
		meta('VIII', { manual: true, meta_nacional: 100, porcentaje: 100, cumple: true }),
	],
	nota: null,
})

async function shown(response = ok(answer()), options = {}) {
	axios.get.mockReturnValueOnce(response)
	const wrapper = mount(Resumen, options)
	await flushPromises()
	return wrapper
}

/** A card by its meta: «Meta I 2026» starts it. */
const card = (wrapper, codigo) => wrapper.findAll('article').find((article) => article.text().startsWith(`Meta ${codigo} `))

/** The cards shown, by their meta. */
const heads = (wrapper) => wrapper.findAll('article').map((article) => article.text().split(' 2026')[0])

/** The card's figure: its % and «N de D», or its cumplimiento informado. */
const figure = (wrapper, codigo) => card(wrapper, codigo).find('.estadistica-meta__cifra')

/** The filter tiles, and one by its label. */
const tiles = (wrapper) => wrapper.find('[role="group"]').findAll('button')
const tile = (wrapper, label) => tiles(wrapper).find((button) => button.text().endsWith(label))

/** The card's title, which opens its detail on Evolución. */
const title = (wrapper, codigo) => card(wrapper, codigo).find('h3 button')

beforeEach(() => {
	axios.get.mockReset()
})

describe('Resumen — the newest metas, one card each (docs/adr/0009, #85)', () => {
	it('reads GET resumen alone and names each card by its title, under its meta and the metas year', async () => {
		const wrapper = await shown()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(RESUMEN, { params: {} })
		expect(wrapper.find('h2').text()).toBe('Metas Sanitarias 2026')
		const articles = wrapper.findAll('article')
		expect(articles.map((article) => article.text().split(' 2026')[0])).toEqual(['Meta I', 'Meta V', 'Meta VII', 'Meta II', 'Meta IV.A', 'Meta VI', 'Meta VIII'])
		expect(articles.map((article) => wrapper.find(`[id="${article.attributes('aria-labelledby')}"]`).text())).toEqual([
			'Recuperación del desarrollo psicomotor', 'La meta V', 'La meta VII', 'La meta II', 'La meta IV.A', 'La meta VI', 'La meta VIII',
		])
		expect(articles.every((article) => article.attributes('tabindex') === undefined), 'a card is reached by its buttons').toBe(true)
		wrapper.unmount()
	})

	it('says once for the page up to which month each REM series has data, Serie P as its corte, and no card dates itself', async () => {
		const wrapper = await shown(ok(withSources()))

		expect(wrapper.text()).toContain('REM Serie A: datos al octubre de 2026 · REM Serie P: corte de junio de 2026')
		expect(wrapper.text().match(/datos al|corte de/gi)).toHaveLength(2)
		wrapper.unmount()
	})

	it('says the metas have no figures yet when no REM series has data, as the 2027 metas while they wait for their rutas', async () => {
		const wrapper = await shown(ok({ ...answer(), anio: 2027, nombre: 'Metas Sanitarias 2027', metas: [meta('I', { estado: 'esperando ruta oficial' }), meta('VIII', { manual: true, meta_nacional: 100 })] }))

		expect(wrapper.find('h2 + p').text()).toBe('Metas Sanitarias 2027: sin cifras todavía')
		wrapper.unmount()
	})

	it('counts the metas of each status exactly, with a tile only for a status some meta holds', async () => {
		const wrapper = await shown()

		const group = wrapper.find('[role="group"]')
		expect(group.attributes('aria-label')).toBe('Filtrar metas por estado')
		expect(tiles(wrapper).map((tile) => tile.text())).toEqual([
			'7 Todas las metas', '2 Cumplen', '2 No cumplen', '1 Falta el denominador', '1 Esperando ruta oficial', '1 Sin informar',
		])

		const data = answer()
		data.metas = data.metas.filter((one) => one.porcentaje !== null)
		const judged = await shown(ok(data))
		expect(tiles(judged).map((tile) => tile.text())).toEqual(['4 Todas las metas', '2 Cumplen', '2 No cumplen'])
		wrapper.unmount()
		judged.unmount()
	})

	it('filters the cards by a tile and says, politely, how many metas it shows', async () => {
		const wrapper = await shown()

		const live = wrapper.find('[role="status"]')
		expect(live.text()).toBe('7 metas.')
		expect(tiles(wrapper).map((tile) => tile.attributes('aria-pressed'))).toEqual(['true', 'false', 'false', 'false', 'false', 'false'])

		await tile(wrapper, 'No cumplen').trigger('click')
		expect(heads(wrapper)).toEqual(['Meta V', 'Meta VII'])
		expect(live.text()).toBe('2 metas: no cumplen.')
		expect(tile(wrapper, 'No cumplen').attributes('aria-pressed')).toBe('true')
		expect(tile(wrapper, 'Todas las metas').attributes('aria-pressed')).toBe('false')

		await tile(wrapper, 'Esperando ruta oficial').trigger('click')
		expect(heads(wrapper)).toEqual(['Meta IV.A'])
		expect(live.text()).toBe('1 meta: esperando ruta oficial.')

		await tile(wrapper, 'Todas las metas').trigger('click')
		expect(heads(wrapper)).toHaveLength(7)
		expect(live.text()).toBe('7 metas.')
		wrapper.unmount()
	})

	it('says each status as its icon and its word, and the gap to the target in points', async () => {
		const wrapper = await shown()

		const badge = (codigo) => card(wrapper, codigo).find('.estadistica-estado')
		for (const [codigo, icon, word] of [
			['I', mdiCheck, 'Cumple'],
			['V', mdiClose, 'No cumple'],
			['II', mdiAlertCircleOutline, 'Falta el denominador oficial'],
			['IV.A', mdiClockOutline, 'Esperando ruta oficial'],
			['VI', mdiMinusCircleOutline, 'Sin informar'],
			['VIII', mdiCheck, 'Cumple'],
		]) {
			expect(badge(codigo).text(), `Meta ${codigo}'s word`).toBe(word)
			expect(badge(codigo).find('path').attributes('d'), `Meta ${codigo}'s icon`).toBe(icon)
		}
		expect(card(wrapper, 'I').text()).toContain('+8,3 puntos frente a la meta comprometida (50 %).')
		expect(card(wrapper, 'V').text()).toContain('−8,2 puntos frente a la meta nacional (45 %).')
		expect(card(wrapper, 'VIII').text()).toContain('+0 puntos frente a la meta nacional (100 %).')
		wrapper.unmount()
	})

	it('shows the percentage and «N de D» as one figure, with a meter against the target', async () => {
		const wrapper = await shown()

		expect(figure(wrapper, 'I').text().replace(/\s+/g, ' ')).toBe('58,3 % 7 de 12')
		expect(figure(wrapper, 'V').text().replace(/\s+/g, ' ')).toBe('36,8 % 1.287 de 3.500,5')
		expect(figure(wrapper, 'VIII').text(), 'a cumplimiento informado has no «N de D»').toBe('100 %')
		const meter = card(wrapper, 'I').find('[role="meter"]')
		expect(meter.attributes('aria-label')).toBe('Meta I 2026 frente a su meta comprometida')
		expect(meter.attributes('aria-valuenow')).toBe('58.33')
		expect(meter.attributes('aria-valuetext')).toBe('58,3 %; meta comprometida 50 %')
		wrapper.unmount()
	})

	it('says why a meta has no percentage, keeps the numerador already counted, and never shows sin informar as a figure', async () => {
		const wrapper = await shown()

		expect(card(wrapper, 'II').text()).toContain('Sin porcentaje: falta el denominador oficial de 2026.')
		expect(card(wrapper, 'II').text()).toContain('140 en el numerador, a febrero de 2026')
		expect(card(wrapper, 'IV.A').text()).toContain('Sin porcentaje: esperando ruta oficial.')
		expect(card(wrapper, 'VI').text()).toContain('Nadie informó esta meta en el periodo.')
		for (const codigo of ['II', 'IV.A', 'VI']) {
			expect(card(wrapper, codigo).text(), `Meta ${codigo}`).not.toMatch(/\d %|Cumple/)
			expect(card(wrapper, codigo).find('[role="meter"]').exists(), `Meta ${codigo}`).toBe(false)
		}
		wrapper.unmount()

		const data = answer()
		Object.assign(data.metas[6], { porcentaje: null, cumple: null })
		const manual = await shown(ok(data))
		expect(card(manual, 'VIII').text()).toContain('Todavía no se ha informado su cumplimiento.')
		expect(card(manual, 'VIII').text()).not.toMatch(/\d %/)
		manual.unmount()
	})

	it('keeps a Serie P numerador already counted at its corte', async () => {
		const data = answer()
		data.metas[3].historico = [year(2026, [], {
			meses: [{ mes: '2026-06', numerador: 412, denominador: null, porcentaje: null }],
			ruta: { numerador: { fuente: 'rem-p', nombre: 'REM Serie P' } },
		})]
		const wrapper = await shown(ok(data))

		expect(card(wrapper, 'II').text()).toContain('412 en el numerador, al corte de junio de 2026')
		wrapper.unmount()
	})

	it('shows each meta mínima as a reference from its base year\'s cierre, and why a meta has none (#51)', async () => {
		const minima = {
			I: { porcentaje: 62, base: 2025, motivo: null },
			V: { porcentaje: 52.6, base: 2025, motivo: null },
			VII: { porcentaje: 68, base: 2025, motivo: 'sin la meta comprometida 2025, se cierra el 40 % de la brecha' },
			II: { porcentaje: null, base: 2025, motivo: 'falta el denominador oficial de 2025' },
			'IV.A': { porcentaje: null, base: null, motivo: 'esperando ruta oficial' },
			VIII: { porcentaje: 100, base: null, motivo: null },
		}
		const data = answer()
		data.metas = data.metas.map((card) => ({ ...card, meta_minima: minima[card.codigo] ?? null }))
		const wrapper = await shown(ok(data))

		expect(card(wrapper, 'I').text()).toContain('Meta mínima 2026: 62,0 % — referencia: la meta la fija el Servicio de Salud')
		expect(card(wrapper, 'I').text()).toContain('Desde el cierre 2025.')
		expect(card(wrapper, 'V').text()).toContain('Meta mínima 2026: 52,6 % — referencia: la meta la fija el Servicio de Salud')
		expect(card(wrapper, 'VII').text()).toContain('Desde el cierre 2025; sin la meta comprometida 2025, se cierra el 40 % de la brecha.')
		expect(card(wrapper, 'II').text()).toContain('Sin meta mínima 2026: falta el denominador oficial de 2025.')
		expect(card(wrapper, 'IV.A').text()).toContain('Sin meta mínima 2026: esperando ruta oficial.')
		expect(card(wrapper, 'VIII').text()).toContain('Meta mínima 2026: 100,0 % — referencia: la meta la fija el Servicio de Salud')
		expect(card(wrapper, 'VIII').text()).not.toContain('cierre')
		expect(card(wrapper, 'VI').text()).not.toContain('mínima')
		wrapper.unmount()
	})

	it('says when the newest metas wait for their rutas, and offers the previous year\'s Metas Sanitarias', async () => {
		const wrapper = await shown()

		const note = wrapper.findAllComponents({ name: 'NcNoteCard' }).find((card) => card.text().includes('rutas REM'))
		expect(note.text()).toContain('Las metas tendrán cifras cuando MINSAL confirme sus rutas REM')
		const offer = note.find('button')
		expect(offer.text()).toBe('Ver las Metas Sanitarias 2025')
		await offer.trigger('click')
		expect(wrapper.emitted('metas')).toEqual([[2025]])
		wrapper.unmount()

		const data = answer()
		data.metas = data.metas.filter((one) => one.estado !== 'esperando ruta oficial')
		const none = await shown(ok(data))
		expect(none.text()).not.toContain('rutas REM')
		none.unmount()
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

describe('Resumen — the nota a model wrote about what changed (CONTEXT.md)', () => {
	const LABEL = 'Escrita por un modelo de IA a partir de las cifras'

	it('shows the nota at the top, as plain text, labelled as a model\'s', async () => {
		const resumen = answer()
		resumen.nota = 'La Meta I 2026 llegó a <b>58,3 %</b> (7 de 12) en octubre de 2026.'
		const wrapper = await shown(ok(resumen))

		const nota = wrapper.find('.estadistica-resumen__nota')
		expect(nota.findAll('p').map((line) => line.text())).toEqual(['La Meta I 2026 llegó a <b>58,3 %</b> (7 de 12) en octubre de 2026.', LABEL])
		expect(nota.find('b').exists()).toBe(false)
		expect(nota.element.compareDocumentPosition(wrapper.find('.estadistica-resumen__metas').element)).toBe(window.Node.DOCUMENT_POSITION_FOLLOWING)
		wrapper.unmount()
	})

	it('shows no nota, and no label, when there is none', async () => {
		const wrapper = await shown()

		expect(wrapper.find('.estadistica-resumen__nota').exists()).toBe(false)
		expect(wrapper.text()).not.toContain(LABEL)
		wrapper.unmount()
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

describe('Resumen — a meta\'s detail beside the cards: Evolución and Origen (#86)', () => {
	// The panel is its own chunk. Compiling it on the first click can take longer than vi.waitFor's 1 s on a loaded
	// host, so it is loaded once here: the waits below then cover only the click and the render.
	beforeAll(() => import('./DetailSidebar.vue'))

	beforeEach(() => {
		// NcContent's root, where the app sidebar sits beside the content.
		document.body.innerHTML = '<div id="content-vue"><div id="resumen"></div></div>'
	})

	const mounted = (resumen = withSources()) => shown(ok(resumen), { attachTo: '#resumen' })

	const panel = (wrapper) => wrapper.findComponent(NcAppSidebar)

	/** The panel's selected tab, by its name, and the content it shows. */
	const selected = (wrapper) => panel(wrapper).find('[role="tab"][aria-selected="true"]').text()
	const showing = (wrapper) => panel(wrapper).findAll('[role="tabpanel"]').find((tab) => tab.attributes('aria-hidden') !== 'true')

	const opened = async (wrapper, button) => {
		button.element.focus()
		await button.trigger('click')
		// The panel is its own chunk, fetched on the first click.
		await vi.waitFor(() => expect(panel(wrapper).exists()).toBe(true))
		await flushPromises()
		return panel(wrapper)
	}

	it('opens on Evolución from a card\'s title, in the app sidebar beside the content, with the figure and the status', async () => {
		const wrapper = await mounted()

		const sidebar = await opened(wrapper, title(wrapper, 'I'))
		expect(sidebar.element.parentElement.id).toBe('content-vue')
		expect(sidebar.props('name')).toBe('Meta I 2026')
		expect(selected(wrapper)).toBe('Evolución')
		const text = showing(wrapper).text().replace(/\s+/g, ' ')
		expect(text).toContain('58,3 % 7 de 12')
		expect(text).toContain('Cumple')
		expect(showing(wrapper).find('svg[role="img"]').attributes('aria-label')).toBe('Meta I por mes y año; sus valores están en la tabla.')
		wrapper.unmount()
	})

	it('opens on Origen from a card\'s figure: the newest year\'s ruta as its diccionario labels it, the origen with its date and the referencia', async () => {
		const wrapper = await mounted()

		await opened(wrapper, figure(wrapper, 'I'))
		expect(selected(wrapper)).toBe('Origen')
		const text = showing(wrapper).text()
		expect(text).toContain('Origen: datos públicos DEIS, con cifras hasta noviembre de 2026.')
		expect(text).toContain('REM Serie A, de enero de 2026 a diciembre de 2026.')
		expect(text).toContain('REM Serie A, de octubre de 2025 a septiembre de 2026.')
		expect(text).toContain('Referencia: MINSAL, Orientaciones Técnicas Metas Sanitarias 2026, Anexo 3.')
		const codigos = showing(wrapper).findAll('.estadistica-route__codigo').map((codigo) => codigo.text())
		expect(codigos).toHaveLength(5)
		expect(codigos[0]).toContain('02010420 Recuperados')
		expect(codigos[0]).toContain('Hoja A03, SECCIÓN C: EVALUACIÓN DEL DESARROLLO PSICOMOTOR')
		expect(codigos[0]).toContain('col08: 7 a 11 meses')
		expect(codigos[0]).toContain('col09: 12 a 17 meses')
		expect(codigos[1]).toBe('09000001 sin descripción col08: sin descripción')
		expect(codigos[3]).toContain('col10: 18 a 23 meses')
		expect(codigos[3]).not.toContain('col08')
		expect(codigos[4]).toContain('Hoja A05, SECCIÓN B: EGRESOS (etiqueta de 2027)')
		expect(showing(wrapper).findAll('p').map((line) => line.text()).filter((line) => line.endsWith(':'))).toEqual(['Suma:', 'Suma:', 'Resta:'])

		await opened(wrapper, figure(wrapper, 'V'))
		expect(showing(wrapper).text()).toContain('REM Serie P, de enero de 2026 a diciembre de 2026.')
		expect(showing(wrapper).text(), 'a denominador not counted in the REM').toContain('Población inscrita validada (FONASA).')
		await opened(wrapper, figure(wrapper, 'VIII'))
		expect(showing(wrapper).text()).toContain('Lo informa quien administra Estadística: no se cuenta en el REM.')
		wrapper.unmount()
	})

	it('says a figure a planilla covers came from it, with the day its file was saved (docs/adr/0010)', async () => {
		const resumen = withSources()
		Object.assign(resumen.metas[0], { origen: 'planilla del establecimiento', fecha_corte: '2026-11-09' })
		const wrapper = await mounted(resumen)

		await opened(wrapper, figure(wrapper, 'I'))
		expect(showing(wrapper).text()).toContain('Origen: planilla del establecimiento, guardada el 9 de noviembre de 2026.')
		wrapper.unmount()
	})

	it('charts one line per comparable year and the target as a labelled threshold, and says why a year does not compare', async () => {
		const wrapper = await mounted()

		await opened(wrapper, title(wrapper, 'I'))
		const chart = showing(wrapper).find('figure')
		expect(chart.findAll('li').map((key) => key.text())).toEqual(['2025', '2026 (más reciente)', 'Meta comprometida (50 %)'])
		expect(chart.findAll('svg[role="img"] text').map((label) => label.text())).toContain('Meta comprometida 50 %')
		expect(showing(wrapper).text()).toContain('2024: sin datos comparables.')

		// What is drawn: 2024 draws no line, and 2026's February, sin informar, is a gap, never a 0 (docs/adr/0009).
		const lines = chart.find('svg[role="img"]').findAll('g').filter((group) => !group.classes('estadistica-chart__grid'))
		expect(lines.map((line) => line.classes()), '2025 and 2026, in legend order').toEqual([['estadistica-chart__line--context'], ['estadistica-chart__line--emphasis']])
		const [context, newest] = lines
		expect(context.findAll('path')).toHaveLength(1)
		// Each month's x, read off 2025's twelve dots, so the test holds at any width.
		const months = context.findAll('circle').map((dot) => Number(dot.attributes('cx')))
		expect(months).toHaveLength(12)
		const paths = newest.findAll('path')
		expect(paths, 'January alone is a dot, March to October one line').toHaveLength(1)
		const vertices = paths[0].attributes('d').match(/[ML][^ML]+/g).map((step) => [step[0], ...step.slice(1).trim().split(' ').map(Number)])
		expect(vertices.map(([command]) => command).join('')).toBe('MLLLLLLL')
		vertices.forEach(([, x], k) => expect(x, `vertex ${k} at month ${k + 3}`).toBeCloseTo(months[k + 2], 1))
		const dots = newest.findAll('circle').map((dot) => [Number(dot.attributes('cx')), Number(dot.attributes('cy'))])
		expect(dots.map(([x]) => x), 'no dot at February, November or December').toEqual(months.filter((_, i) => ![1, 10, 11].includes(i)))
		const [january] = dots
		expect(vertices.some(([, x, y]) => Math.abs(x - january[0]) < 0.1 && Math.abs(y - january[1]) < 0.1), 'January is on no path').toBe(false)
		wrapper.unmount()
	})

	it('is reached by keyboard: focus reads the last month above the plot, and the arrow keys move and announce one month at a time', async () => {
		const wrapper = await mounted()
		await opened(wrapper, title(wrapper, 'I'))

		const plot = showing(wrapper).find('svg[role="img"]')
		const readout = showing(wrapper).find('[aria-live="polite"]')
		const said = () => readout.text().replace(/\s+/g, ' ')
		expect(plot.attributes('tabindex')).toBe('0')
		await plot.trigger('focus')
		expect(said()).toBe('diciembre: 120 % 2025 · 12 de 10 — 2026 (más reciente) 50 % Meta comprometida')
		await plot.trigger('keydown', { key: 'ArrowRight' })
		expect(said(), 'the last month is the last stop').toMatch(/^diciembre:/)
		for (let i = 0; i < 10; i++) {
			await plot.trigger('keydown', { key: 'ArrowLeft' })
		}
		expect(said(), 'sin informar is a gap, never 0').toBe('febrero: 20 % 2025 · 2 de 10 — 2026 (más reciente) 50 % Meta comprometida')
		await plot.trigger('keydown', { key: 'ArrowLeft' })
		await plot.trigger('keydown', { key: 'ArrowLeft' })
		expect(said()).toBe('enero: 10 % 2025 · 1 de 10 25 % 2026 (más reciente) · 1 de 4 50 % Meta comprometida')
		await plot.trigger('blur')
		expect(said()).toBe('Pase el cursor sobre el gráfico, o enfóquelo y use las flechas, para leer un mes.')
		wrapper.unmount()
	})

	it('holds every charted value in its table twin, month by year with each cell\'s «N de D», and each year\'s cierre', async () => {
		const wrapper = await mounted()
		await opened(wrapper, title(wrapper, 'I'))

		const table = showing(wrapper).find('table')
		expect(table.findAll('thead th').map((cell) => cell.text())).toEqual(['Mes', '2025', '2026'])
		const rows = table.findAll('tbody tr')
		expect(rows.map((row) => row.find('th').text())).toEqual(['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'])
		expect(rows[1].findAll('td').map((cell) => cell.text())).toEqual(['20 % (2 de 10)', 'sin informar'])
		expect(rows[11].findAll('td').map((cell) => cell.text())).toEqual(['120 % (12 de 10)', '—'])
		expect(table.findAll('tfoot td').map((cell) => cell.text())).toEqual(['120 %', '—'])

		// Every month the chart reads, from the last to the first: each line's value is its year's cell.
		const plot = showing(wrapper).find('svg[role="img"]')
		await plot.trigger('focus')
		for (let month = 11; month >= 0; month--) {
			const charted = showing(wrapper).findAll('[aria-live="polite"] span strong').map((value) => value.text()).slice(0, 2)
			const cells = rows[month].findAll('td').map((cell) => cell.text())
			charted.forEach((value, line) => {
				expect(value === '—' ? ['sin informar', '—'] : [value], `${rows[month].find('th').text()}, line ${line}`).toContain(value === '—' ? cells[line] : cells[line].split(' (')[0])
			})
			await plot.trigger('keydown', { key: 'ArrowLeft' })
		}
		wrapper.unmount()
	})

	it('closes with Escape or its close button and gives the focus back to the title or the figure that opened it, each opening a panel of its own', async () => {
		const wrapper = await mounted()

		const first = await opened(wrapper, figure(wrapper, 'V'))
		for (const [button, close] of [[title(wrapper, 'I'), 'Escape'], [figure(wrapper, 'I'), 'button'], [title(wrapper, 'IV.A'), 'button']]) {
			expect(button.element.tagName).toBe('BUTTON')
			await opened(wrapper, button)
			expect(first.exists(), 'each opening opens a panel of its own').toBe(false)
			expect(panel(wrapper).element.contains(document.activeElement), 'the focus moves into the panel').toBe(true)
			if (close === 'Escape') {
				await panel(wrapper).find('aside').trigger('keydown', { key: 'Escape' })
			} else {
				await panel(wrapper).find('.app-sidebar__close').trigger('click')
			}
			await flushPromises()

			expect(panel(wrapper).exists(), `${close} closes the panel`).toBe(false)
			expect(document.activeElement, `the focus is back on «${button.text()}»`).toBe(button.element)
		}
		wrapper.unmount()
	})

	it('opens from the title of every card, a meta with no histórico on «Todavía no hay cifras», its Origen saying the ruta waits', async () => {
		const wrapper = await mounted()

		expect(wrapper.findAll('article h3 button')).toHaveLength(7)
		await opened(wrapper, title(wrapper, 'IV.A'))
		expect(showing(wrapper).text()).toContain('Todavía no hay cifras')
		expect(showing(wrapper).text()).toContain('Esperando ruta oficial')
		expect(showing(wrapper).find('svg[role="img"]').exists()).toBe(false)
		await panel(wrapper).find('[role="tab"]:not([aria-selected="true"])').trigger('click')
		expect(selected(wrapper)).toBe('Origen')
		expect(showing(wrapper).text()).toContain('La ruta REM de esta meta se mostrará aquí cuando MINSAL la confirme. Estadística no la deduce.')
		wrapper.unmount()
	})
})
