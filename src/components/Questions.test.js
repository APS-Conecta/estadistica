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

/** GET preguntas: one of the person's own, one of someone else's. */
const PREGUNTAS = [
	{ id: 1, titulo: 'Controles', consulta: CONSULTA, tipo: 'barra', autor: 'ana', autor_nombre: 'Ana', actualizada: 1, editable: true },
	{ id: 2, titulo: 'Visitas', consulta: CONSULTA, tipo: 'linea', autor: 'luis', autor_nombre: 'Luis', actualizada: 1, editable: false },
]

async function listed(preguntas = PREGUNTAS) {
	axios.get.mockImplementation((url) => (url === `${OCS}/preguntas` ? ok(preguntas) : Promise.reject(new Error(`unexpected GET ${url}`))))
	const wrapper = mount(Questions, { global: { stubs: { Visualization: true } } })
	await flushPromises()
	return wrapper
}

const item = (wrapper, index) => wrapper.findAll('.estadistica-questions__item')[index]

const button = (element, text) => element.findAll('button').find((b) => b.text() === text)

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
	axios.delete.mockReset()
})

describe('Questions — every pregunta, everyone\'s to read (a8)', () => {
	it('lists each with its author, and asks one only when it is opened', async () => {
		const wrapper = await listed()
		axios.post.mockReturnValueOnce(ok({ series: { local: [], nacional: [], pares: null }, etiquetas: { local: null, pares: null, codigos: [] }, fuente: {} }))

		expect(item(wrapper, 0).text()).toContain('Controles')
		expect(item(wrapper, 1).text()).toContain('de Luis')
		expect(axios.post).not.toHaveBeenCalled()

		await button(item(wrapper, 1), 'Ver').trigger('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(`${OCS}/consultas`, { consulta: CONSULTA })
		expect(item(wrapper, 1).findComponent(QuestionCard).exists()).toBe(true)
		wrapper.unmount()
	})

	it('offers Editar and Eliminar only where the person may change it', async () => {
		const wrapper = await listed()

		expect(button(item(wrapper, 0), 'Editar')).toBeDefined()
		expect(button(item(wrapper, 1), 'Editar')).toBeUndefined()
		expect(button(item(wrapper, 1), 'Eliminar')).toBeUndefined()
		wrapper.unmount()
	})

	it('renames it and changes its chart, and sends nothing else', async () => {
		const wrapper = await listed()
		axios.put.mockImplementationOnce((url, body) => ok({ ...PREGUNTAS[0], ...body }))

		await button(item(wrapper, 0), 'Editar').trigger('click')
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

	it('deletes one only once the person confirms, warning the tableros will say so', async () => {
		const wrapper = await listed()
		const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
		axios.delete.mockReturnValueOnce(ok([]))

		await wrapper.vm.remove(PREGUNTAS[0])
		expect(axios.delete).not.toHaveBeenCalled()
		expect(confirm.mock.calls[0][0]).toContain('Los tableros que la muestran dirán que fue eliminada.')

		await wrapper.vm.remove(PREGUNTAS[0])
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(`${OCS}/preguntas/1`)
		expect(wrapper.findAll('.estadistica-questions__item')).toHaveLength(1)
		confirm.mockRestore()
		wrapper.unmount()
	})

	it('says how to save the first one when there are none', async () => {
		const wrapper = await listed([])

		expect(wrapper.text()).toContain('Todavía no hay preguntas guardadas')
		wrapper.unmount()
	})
})
