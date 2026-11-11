// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import QuestionCard from './QuestionCard.vue'
import Questions from './Questions.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

const OCS = '/ocs/v2.php/apps/estadistica/api/v1'

const ok = (data) => Promise.resolve({ data: { ocs: { meta: { status: 'ok' }, data } } })

const CONSULTA = { fuente: 'rem-a', periodo: { desde: '2026-01', hasta: '2026-03' }, filtros: { codigos: ['02010420'], columnas: ['col01'] } }

/** GET preguntas: one of the person's own, one of someone else's saved on 10 November 2026 at noon in Chile. */
const PREGUNTAS = [
	{ id: 1, titulo: 'Controles', consulta: CONSULTA, tipo: 'barra', autor: 'ana', autor_nombre: 'Ana', actualizada: 1, editable: true },
	{ id: 2, titulo: 'Visitas', consulta: CONSULTA, tipo: 'linea', autor: 'luis', autor_nombre: 'Luis', actualizada: 1794322800, editable: false },
]

async function listed(preguntas = PREGUNTAS) {
	axios.get.mockImplementation((url) => (url === `${OCS}/preguntas` ? ok(preguntas) : Promise.reject(new Error(`unexpected GET ${url}`))))
	const wrapper = mount(Questions, { global: { stubs: { Visualization: true } } })
	await flushPromises()
	return wrapper
}

const item = (wrapper, index) => wrapper.findAll('.estadistica-questions__item')[index]

const button = (element, text) => element.findAll('button').find((b) => b.text() === text)

/** The actions menu and the dialog open in the page's body, outside the list. */
const inPage = (selector, text) => [...document.body.querySelectorAll(selector)].find((element) => element.textContent.trim() === text)

/** «Editar» or «Eliminar» from a pregunta's actions menu, as a person picks it. */
async function choose(wrapper, titulo, action) {
	await wrapper.find(`button[aria-label="Acciones de «${titulo}»"]`).trigger('click')
	await flushPromises()
	inPage('[role="menuitem"]', action).click()
	await flushPromises()
}

const dialog = () => document.body.querySelector('[role="dialog"]')

// jsdom has no ResizeObserver; the actions menu's popover observes its own size.
vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} })

beforeEach(() => {
	document.body.innerHTML = ''
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
	axios.delete.mockReset()
})

describe('Questions — every pregunta, everyone\'s to read (a8)', () => {
	it('lists each with its author and date, and asks one only when it is opened', async () => {
		const wrapper = await listed()
		axios.post.mockReturnValueOnce(ok({ series: { local: [], nacional: [], pares: null }, etiquetas: { local: null, pares: null, codigos: [] }, fuente: {} }))

		expect(item(wrapper, 0).text()).toContain('Controles')
		expect(item(wrapper, 1).text()).toContain('de Luis · 10 de noviembre de 2026')
		expect(axios.post).not.toHaveBeenCalled()

		await button(item(wrapper, 1), 'Ver').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${OCS}/consultas`, { consulta: CONSULTA })
		expect(item(wrapper, 1).findComponent(QuestionCard).exists()).toBe(true)
		wrapper.unmount()
	})

	it('offers Editar and Eliminar in an actions menu only where the person may change it', async () => {
		const wrapper = await listed()

		expect(button(item(wrapper, 0), 'Ver').attributes('aria-label')).toBe('Ver la pregunta «Controles»')
		await item(wrapper, 0).find('button[aria-label="Acciones de «Controles»"]').trigger('click')
		await flushPromises()
		expect([...document.body.querySelectorAll('[role="menuitem"]')].map((action) => action.textContent.trim())).toEqual(['Editar', 'Eliminar'])

		expect(button(item(wrapper, 1), 'Ver').attributes('aria-label')).toBe('Ver la pregunta «Visitas»')
		expect(item(wrapper, 1).find('button[aria-haspopup="menu"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('renames it and changes its chart, and sends nothing else', async () => {
		const wrapper = await listed()
		axios.put.mockImplementationOnce((url, body) => ok({ ...PREGUNTAS[0], ...body }))

		await choose(wrapper, 'Controles', 'Editar')
		expect(item(wrapper, 0).find('form input[type="text"]').element.value).toBe('Controles')
		await item(wrapper, 0).find('form input[type="text"]').setValue('Controles de salud')
		wrapper.vm.draft.tipo = 'tabla'
		await item(wrapper, 0).find('form').trigger('submit')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(`${OCS}/preguntas/1`, { titulo: 'Controles de salud', tipo: 'tabla' })
		expect(item(wrapper, 0).text()).toContain('Controles de salud')
		expect(item(wrapper, 0).find('form').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows the server\'s Spanish refusal when a change is not the person\'s', async () => {
		const wrapper = await listed()
		axios.put.mockRejectedValueOnce({
			message: 'Request failed with status code 403',
			response: { status: 403, data: { ocs: { meta: { status: 'failure' }, data: { message: 'Solo quien creó esta pregunta, o quien administra Estadística, puede cambiarla o eliminarla.' } } } },
		})

		wrapper.vm.edit(PREGUNTAS[0])
		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.text()).toContain('Solo quien creó esta pregunta')
		wrapper.unmount()
	})

	it('deletes one only once the person confirms in a dialog, warning the tableros will say so', async () => {
		const wrapper = await listed()
		axios.delete.mockReturnValueOnce(ok([]))

		await choose(wrapper, 'Controles', 'Eliminar')
		expect(dialog().textContent).toContain('¿Eliminar la pregunta «Controles»? Los tableros que la muestran dirán que fue eliminada.')
		inPage('[role="dialog"] button', 'Cancelar').click()
		await vi.waitFor(() => expect(dialog()).toBeNull())
		expect(axios.delete).not.toHaveBeenCalled()
		expect(wrapper.findAll('.estadistica-questions__item')).toHaveLength(2)

		await choose(wrapper, 'Controles', 'Eliminar')
		inPage('[role="dialog"] button', 'Eliminar').click()
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(`${OCS}/preguntas/1`)
		expect(wrapper.findAll('.estadistica-questions__item')).toHaveLength(1)
		wrapper.unmount()
	})

	it('says how to save the first one when there are none', async () => {
		const wrapper = await listed([])

		expect(wrapper.text()).toContain('Todavía no hay preguntas guardadas')
		wrapper.unmount()
	})
})
