// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import Goals from './Goals.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

const INDICADORES = '/ocs/v2.php/apps/estadistica/api/v1/indicadores'

const ok = (data) => Promise.resolve({ data: { ocs: { meta: { status: 'ok' }, data } } })

const refused = (status, message) => Promise.reject(Object.assign(new Error(`HTTP ${status}`), {
	response: { status, data: { ocs: { meta: { status: 'failure' }, data: message === undefined ? [] : { message } } } },
}))

const mes = (m, fields) => ({
	mes: `2026-0${m}`, numerador: null, denominador: null, porcentaje: null, brecha_comprometida: null, brecha_nacional: null, nacional: null, pares: null, ...fields,
})

/** GET indicadores for 2026: a REM meta with a commitment, a people meta grown a year, and Meta VIII. */
function answer(changes = {}) {
	return {
		anios: [2027, 2026],
		anio: 2026,
		nombre: 'Metas Sanitarias 2026',
		referencia: 'Orientaciones Técnicas 2026.',
		nota: 'Los pesos son los de 2025.',
		diccionarios: { 'rem-a': 'SA_25_V1.5' },
		version: 1,
		establecimiento: '900001',
		pares: { tipo: 'Centro de Salud Familiar (CESFAM)' },
		aviso: null,
		fuentes: [
			{ id: 'rem-a', nombre: 'REM Serie A', origen: 'datos públicos DEIS', fecha_corte: '2026-02', filas: 9, estado: 'ok', anios: [] },
			{ id: 'piv', nombre: 'Población inscrita validada (FONASA)', origen: 'FONASA', fecha_corte: '2025-12-31', filas: 9, estado: 'ok', anios: [] },
		],
		indicadores: [
			{
				codigo: 'I', nombre: 'Recuperación del desarrollo psicomotor', indicador: 'Porcentaje de recuperados', meta_nacional: 90, meta_comprometida: 70, peso: 12.5, nota: null,
				numerador: {}, denominador: {}, manual: false, cumplimiento: null, denominador_comprometido: null, poblacion: null,
				meses: [
					mes(1, { numerador: 3, denominador: 8, porcentaje: 37.5, brecha_comprometida: -32.5, brecha_nacional: -52.5, nacional: 15, pares: 20 }),
					mes(2, { numerador: 6, denominador: 8, porcentaje: 75, brecha_comprometida: 5, brecha_nacional: -15, nacional: 45, pares: 50 }),
				],
			},
			{
				codigo: 'II', nombre: 'Cáncer de cuello uterino', indicador: 'Tamizaje vigente', meta_nacional: 80, meta_comprometida: null, peso: 12.5, nota: 'Solo mujeres.',
				numerador: {}, denominador: {}, manual: false, cumplimiento: null, denominador_comprometido: null,
				poblacion: { comprometida: false, corte: '2024-12-31', factor: 1.03, prorrateado: true },
				meses: [mes(1, {}), mes(2, { denominador: 1287.5 })],
			},
			{
				codigo: 'VIII', nombre: 'Participación social', indicador: 'Plan de salud comunitaria', meta_nacional: 100, meta_comprometida: null, peso: 6.25, nota: null,
				numerador: null, denominador: null, manual: true, cumplimiento: 100, denominador_comprometido: null, poblacion: null, meses: [],
			},
		],
		...changes,
	}
}

async function shown(response = ok(answer()), establishment = { configured: true }) {
	axios.get.mockReturnValueOnce(response)
	const wrapper = mount(Goals, { props: { establishment } })
	await flushPromises()
	return wrapper
}

const card = (wrapper, index) => wrapper.findAll('.estadistica-goals__meta')[index]

const cells = (row) => row.findAll('th, td').map((cell) => cell.text())

beforeEach(() => {
	axios.get.mockReset()
})

describe('Goals — the Metas Sanitarias month by month (a12, a22)', () => {
	it('asks for the newest year, compared with the peers of the same tipo', async () => {
		const wrapper = await shown()

		expect(axios.get).toHaveBeenCalledWith(INDICADORES, { params: { pares: 'tipo' } })
		expect(wrapper.find('h2').text()).toBe('Metas Sanitarias 2026')
		expect(wrapper.findAll('.estadistica-goals__meta')).toHaveLength(3)
		expect(wrapper.text()).toContain('REM Serie A (datos públicos DEIS): cifras hasta febrero de 2026.')
		expect(wrapper.text()).toContain('Población inscrita validada (FONASA): corte al 31-12-2025.')
		expect(wrapper.text()).not.toContain('(FONASA) (FONASA)')
		wrapper.unmount()
	})

	it('shows each month: the local figures and gaps in points, beside the country and the peers', async () => {
		const wrapper = await shown()
		const rows = card(wrapper, 0).findAll('tbody tr')

		expect(card(wrapper, 0).find('h3').text()).toBe('Meta I: Recuperación del desarrollo psicomotor')
		expect(card(wrapper, 0).text()).toContain('Meta nacional: 90 %. Meta comprometida: 70 %. Peso: 12,5 %.')
		expect(cells(card(wrapper, 0).find('thead tr'))).toEqual(['Mes', 'Numerador', 'Denominador', 'Su establecimiento', 'Frente a la meta comprometida', 'Frente a la meta nacional', 'Nacional', 'Pares'])
		expect(cells(rows[0])).toEqual(['enero de 2026', '3', '8', '37,5 %', '−32,5 puntos', '−52,5 puntos', '15 %', '20 %'])
		expect(cells(rows[1])).toEqual(['febrero de 2026', '6', '8', '75 %', '+5 puntos', '−15 puntos', '45 %', '50 %'])
		wrapper.unmount()
	})

	it('says where a people denominator comes from, and a month with no figure is a dash', async () => {
		const wrapper = await shown()

		expect(card(wrapper, 1).text()).toContain('Sin meta comprometida.')
		expect(card(wrapper, 1).text()).toContain('Denominador: población inscrita validada por FONASA al 31-12-2024, más un 3 % porque el año no tiene su propio corte, estimada por prorrateo de los tramos de edad.')
		expect(cells(card(wrapper, 1).findAll('tbody tr')[1])).toEqual(['febrero de 2026', '—', '1.287,5', '—', '—', '—', '—', '—'])
		wrapper.unmount()
	})

	it('says a committed denominator is the establishment\'s, and a manual meta is its cumplimiento', async () => {
		const committed = answer()
		committed.indicadores[1].poblacion = { comprometida: true, corte: null, factor: null, prorrateado: false }
		committed.indicadores[1].denominador_comprometido = 1200
		const wrapper = await shown(ok(committed))

		expect(card(wrapper, 1).text()).toContain('Denominador: 1.200 personas, comprometidas por el establecimiento.')
		expect(card(wrapper, 2).text()).toContain('Cumplimiento informado: 100 %.')
		expect(card(wrapper, 2).find('table').exists()).toBe(false)
		wrapper.unmount()
	})

	it('asks again for another year and other peers, and drops the peers column with none', async () => {
		const wrapper = await shown()
		axios.get.mockReturnValueOnce(ok(answer({ anio: 2027, pares: null })))

		await wrapper.find('select').setValue('2027')
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(INDICADORES, { params: { pares: 'tipo', anio: 2027 } })
		expect(cells(card(wrapper, 0).find('thead tr'))).not.toContain('Pares')

		axios.get.mockReturnValueOnce(ok(answer()))
		const servicio = wrapper.findAll('input[type="checkbox"]').find((input) => input.element.value === 'servicio')
		await servicio.setValue(true)
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith(INDICADORES, { params: { pares: 'tipo,servicio', anio: 2027 } })
		wrapper.unmount()
	})

	it('keeps the controls still until the answer arrives, so no answer overtakes another', async () => {
		const wrapper = await shown()
		let answered
		axios.get.mockReturnValueOnce(new Promise((resolve) => { answered = resolve }))

		await wrapper.findAll('input[type="checkbox"]')[1].setValue(true)
		expect(wrapper.find('select').element.disabled).toBe(true)
		expect(wrapper.findAll('input[type="checkbox"]').every((input) => input.element.disabled)).toBe(true)

		answered({ data: { ocs: { data: answer({ nombre: 'Con otros pares' }) } } })
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Con otros pares')
		expect(wrapper.find('select').element.disabled).toBe(false)
		wrapper.unmount()
	})

	it('with no establishment, shows the country alone and offers no peers', async () => {
		const wrapper = await shown(ok(answer({ establecimiento: null, pares: null })), { configured: false })

		expect(axios.get).toHaveBeenCalledWith(INDICADORES, { params: { pares: '' } })
		expect(wrapper.find('fieldset').exists()).toBe(false)
		expect(cells(card(wrapper, 0).find('thead tr'))).toEqual(['Mes', 'Nacional'])
		wrapper.unmount()
	})

	it('says so when no metas are loaded, and shows a refusal in Spanish', async () => {
		const empty = await shown(refused(404))
		expect(empty.text()).toContain('Todavía no hay Metas Sanitarias cargadas.')
		empty.unmount()

		const wrapper = await shown(refused(422, 'La consulta tardó más de 10 s y se canceló.'))
		expect(wrapper.text()).toContain('La consulta tardó más de 10 s y se canceló.')
		wrapper.unmount()
	})

	it('says an aviso about the peers above the metas', async () => {
		const wrapper = await shown(ok(answer({ pares: null, aviso: 'El establecimiento 900001 no está en la Base de Establecimientos cargada.' })))

		expect(wrapper.text()).toContain('no está en la Base de Establecimientos cargada')
		wrapper.unmount()
	})
})
