// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
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
	estado: null, mes: null, numerador: null, denominador: null, porcentaje: null, cumple: null, ...fields,
})

/** GET resumen for 900001: cumple against a commitment, misses the nacional, both statuses, sin informar and Meta VIII. */
const answer = () => ({
	anio: 2026,
	nombre: 'Metas Sanitarias 2026',
	establecimiento: '900001',
	metas: [
		meta('I', { nombre: 'Recuperación del desarrollo psicomotor', meta_comprometida: 50, mes: '2026-10', numerador: 7, denominador: 12, porcentaje: 58.33, cumple: true }),
		meta('V', { meta_nacional: 45, mes: '2026-06', numerador: 1287, denominador: 3500.5, porcentaje: 36.77, cumple: false }),
		meta('II', { estado: 'falta el denominador oficial de 2026' }),
		meta('IV.A', { estado: 'esperando ruta oficial' }),
		meta('VI'),
		meta('VIII', { manual: true, meta_nacional: 100, porcentaje: 100, cumple: true }),
	],
})

async function shown(response = ok(answer())) {
	axios.get.mockReturnValueOnce(response)
	const wrapper = mount(Resumen)
	await flushPromises()
	return wrapper
}

const card = (wrapper, codigo) => wrapper.findAll('article').find((article) => article.find('h3').text().startsWith(`Meta ${codigo} `))

beforeEach(() => {
	axios.get.mockReset()
})

describe('Resumen — the newest metas, one card each (docs/adr/0009)', () => {
	it('reads GET resumen alone and names each card by its meta and the metas year', async () => {
		const wrapper = await shown()

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith(RESUMEN, { params: {} })
		expect(wrapper.find('h2').text()).toBe('Metas Sanitarias 2026')
		expect(wrapper.findAll('article h3').map((title) => title.text())).toEqual(['Meta I 2026', 'Meta V 2026', 'Meta II 2026', 'Meta IV.A 2026', 'Meta VI 2026', 'Meta VIII 2026'])
		wrapper.unmount()
	})

	it('shows the percentage, «N de D», «datos al» and whether it cumple, in words', async () => {
		const wrapper = await shown()

		const first = card(wrapper, 'I').text()
		expect(first).toContain('Recuperación del desarrollo psicomotor')
		expect(first).toContain('58,3 %')
		expect(first).toContain('7 de 12')
		expect(first).toContain('Datos al octubre de 2026')
		expect(first).toContain('Cumple la meta comprometida (50 %)')
		expect(card(wrapper, 'V').text()).toContain('1.287 de 3.500,5')
		expect(card(wrapper, 'V').text()).toContain('No cumple la meta nacional (45 %)')
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
