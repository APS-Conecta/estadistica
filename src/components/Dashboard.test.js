// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import Dashboard from './Dashboard.vue'
import QuestionCard from './QuestionCard.vue'
import Visualization from './Visualization.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

const OCS = '/ocs/v2.php/apps/estadistica/api/v1'

const ok = (data) => Promise.resolve({ data: { ocs: { meta: { status: 'ok' }, data } } })

/** An OCS refusal as axios rejects it: the Spanish sentence at ocs.data.message. */
const refused = (message, status = 422) => Promise.reject({
	message: `Request failed with status code ${status}`,
	response: { status, data: { ocs: { meta: { status: 'failure' }, data: { message } } } },
})

const spec = (codigo) => ({
	fuente: 'rem-a',
	periodo: { desde: '2026-01', hasta: '2026-03' },
	filtros: { codigos: [codigo], columnas: ['col01'] },
	agrupar: ['mes'],
	metrica: 'promedio_establecimiento',
	comparar: { nacional: true },
})

const pregunta = (id, codigo, tipo = 'barra') => ({
	id, titulo: `Pregunta ${id}`, consulta: spec(codigo), tipo, autor: 'ana', autor_nombre: 'Ana', actualizada: 1, editable: true,
})

/** GET preguntas: the second one's consulta is refused by the server. */
const PREGUNTAS = [pregunta(1, '01010101'), pregunta(2, '02020202', 'linea'), pregunta(3, '03030303', 'tabla')]

/** GET tableros: one tablero, its three cards in order. */
const TABLERO = {
	id: 9,
	titulo: 'Metas del año',
	tarjetas: [{ pregunta: 1, ancho: 2 }, { pregunta: 2, ancho: 1 }, { pregunta: 3, ancho: 3 }],
	autor: 'ana',
	autor_nombre: 'Ana',
	actualizado: 1,
	editable: true,
}

const ANSWER = {
	series: { local: [{ grupo: ['2026-01'], valor: 4, informantes: 1 }], nacional: [{ grupo: ['2026-01'], valor: 3.5, informantes: 1204 }], pares: null },
	etiquetas: { local: '900001', pares: null, codigos: [] },
	fuente: { id: 'rem-a', nombre: 'REM Serie A', origen: 'datos públicos DEIS', fecha_corte: '2026-03', filas: 9, estado: 'ok', anios: [] },
}

const REFUSAL = 'Ningún diccionario del periodo tiene la hoja «A99».'

function serve({ tableros = [TABLERO], preguntas = PREGUNTAS } = {}) {
	axios.get.mockImplementation((url) => {
		if (url === `${OCS}/tableros`) {
			return ok(tableros)
		}
		if (url === `${OCS}/preguntas`) {
			return ok(preguntas)
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
	axios.post.mockImplementation((url, body) => {
		if (url !== `${OCS}/consultas`) {
			return Promise.reject(new Error(`unexpected POST ${url}`))
		}
		return body.consulta.filtros.codigos[0] === '02020202' ? refused(REFUSAL) : ok(ANSWER)
	})
}

/** The tableros view with TABLERO opened. Visualization is stubbed: jsdom has no canvas. */
async function opened(options) {
	serve(options)
	const wrapper = mount(Dashboard, { global: { stubs: { Visualization: true } } })
	await flushPromises()
	wrapper.vm.open(9)
	await flushPromises()
	return wrapper
}

const consultas = () => axios.post.mock.calls.filter(([url]) => url === `${OCS}/consultas`)

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
	axios.delete.mockReset()
})

describe('Dashboard — each card asks on its own (a8)', () => {
	it('runs each card\'s own consulta, and a refused one says why in its own card while the others draw', async () => {
		const wrapper = await opened()
		const cards = wrapper.findAllComponents(QuestionCard)

		expect(consultas().map(([, body]) => body)).toStrictEqual([
			{ consulta: spec('01010101') }, { consulta: spec('02020202') }, { consulta: spec('03030303') },
		])
		expect(cards).toHaveLength(3)
		expect(cards[1].text()).toContain(REFUSAL)
		expect(cards[1].findComponent(Visualization).exists()).toBe(false)
		expect(cards[0].findComponent(Visualization).props('tipo')).toBe('barra')
		expect(cards[2].findComponent(Visualization).props('tipo')).toBe('tabla')
		expect(cards[0].text()).not.toContain(REFUSAL)
		wrapper.unmount()
	})

	it('lays the cards out one to three columns wide, in the saved order', async () => {
		const wrapper = await opened()

		expect(wrapper.findAll('.estadistica-dashboards__cell').map((cell) => cell.attributes('style'))).toStrictEqual([
			'grid-column: span 2;', 'grid-column: span 1;', 'grid-column: span 3;',
		])
		wrapper.unmount()
	})

	it('says a card\'s pregunta was deleted, and asks nothing for it', async () => {
		const wrapper = await opened({ preguntas: [PREGUNTAS[0], PREGUNTAS[2]] })
		const cards = wrapper.findAllComponents(QuestionCard)

		expect(cards[1].text()).toContain('Esta pregunta fue eliminada')
		expect(consultas()).toHaveLength(2)
		wrapper.unmount()
	})

	it('draws a pregunta saved without agrupar or metrica as QuerySpec reads it', async () => {
		const bare = { ...PREGUNTAS[0], consulta: { fuente: 'rem-a', periodo: { desde: '2026-01', hasta: '2026-03' }, filtros: { codigos: ['01010101'], columnas: ['col01'] } } }
		const wrapper = await opened({ preguntas: [bare, PREGUNTAS[1], PREGUNTAS[2]] })

		expect(wrapper.findAllComponents(QuestionCard)[0].findComponent(Visualization).props('spec')).toMatchObject({ agrupar: [], metrica: 'suma' })
		expect(consultas()[0][1]).toStrictEqual({ consulta: bare.consulta })
		wrapper.unmount()
	})
})

describe('Dashboard — its creator or an administrator arranges it', () => {
	it('saves a card moved down, and asks no card again', async () => {
		const wrapper = await opened()
		axios.put.mockReturnValueOnce(ok({ ...TABLERO, actualizado: 2 }))

		await wrapper.findAllComponents(QuestionCard)[0].findAll('button').find((button) => button.text() === 'Bajar').trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith(`${OCS}/tableros/9`, {
			tarjetas: [{ pregunta: 2, ancho: 1 }, { pregunta: 1, ancho: 2 }, { pregunta: 3, ancho: 3 }],
		})
		expect(wrapper.findAllComponents(QuestionCard).map((card) => card.props('pregunta').id)).toStrictEqual([2, 1, 3])
		expect(consultas()).toHaveLength(3)
		wrapper.unmount()
	})

	it('puts the cards back and says why when the server refuses', async () => {
		const wrapper = await opened()
		axios.put.mockReturnValueOnce(refused('Solo quien creó este tablero, o quien administra Estadística, puede cambiarlo o eliminarlo.', 403))

		await wrapper.vm.drop(0)
		await flushPromises()

		expect(wrapper.findAllComponents(QuestionCard)).toHaveLength(3)
		expect(wrapper.text()).toContain('Solo quien creó este tablero')
		wrapper.unmount()
	})

	it('widens a card and adds one from the saved preguntas', async () => {
		const wrapper = await opened()
		axios.put.mockImplementation((url, body) => ok({ ...TABLERO, tarjetas: body.tarjetas }))

		await wrapper.findAll('select')[1].setValue('3')
		await flushPromises()
		expect(axios.put).toHaveBeenLastCalledWith(`${OCS}/tableros/9`, {
			tarjetas: [{ pregunta: 1, ancho: 2 }, { pregunta: 2, ancho: 3 }, { pregunta: 3, ancho: 3 }],
		})

		wrapper.vm.adding = 3
		await wrapper.find('form.estadistica-dashboards__add').trigger('submit')
		await flushPromises()
		expect(axios.put.mock.calls.at(-1)[1].tarjetas.at(-1)).toStrictEqual({ pregunta: 3, ancho: 1 })
		expect(wrapper.findAllComponents(QuestionCard)).toHaveLength(4)
		wrapper.unmount()
	})

	it('shows no controls on someone else\'s tablero', async () => {
		const wrapper = await opened({ tableros: [{ ...TABLERO, editable: false }] })

		expect(wrapper.text()).not.toContain('Subir')
		expect(wrapper.text()).not.toContain('Agregar tarjeta')
		expect(wrapper.text()).not.toContain('Eliminar el tablero')
		expect(wrapper.text()).toContain('Tablero de Ana. Cada tarjeta hace su propia consulta.')
		wrapper.unmount()
	})
})

describe('Dashboard — a new tablero (a22)', () => {
	it('warns it is visible to all the staff, and opens the tablero it creates', async () => {
		serve({ tableros: [] })
		const wrapper = mount(Dashboard, { global: { stubs: { Visualization: true } } })
		await flushPromises()
		expect(wrapper.text()).toContain('Lo que guarde queda visible para todo el personal.')
		expect(wrapper.text()).toContain('Todavía no hay tableros.')

		axios.post.mockImplementationOnce((url, body) => ok({ ...TABLERO, id: 10, titulo: body.titulo, tarjetas: [] }))
		await wrapper.find('form input[type="text"]').setValue('Controles del sector')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${OCS}/tableros`, { titulo: 'Controles del sector', tarjetas: [] })
		expect(wrapper.find('.estadistica-dashboards__board h2').text()).toBe('Controles del sector')
		wrapper.unmount()
	})

	it('deletes a tablero only once the person confirms', async () => {
		const wrapper = await opened()
		const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
		axios.delete.mockReturnValueOnce(ok([]))

		await wrapper.vm.remove()
		expect(axios.delete).not.toHaveBeenCalled()

		await wrapper.vm.remove()
		await flushPromises()
		expect(axios.delete).toHaveBeenCalledWith(`${OCS}/tableros/9`)
		expect(wrapper.find('.estadistica-dashboards__board').exists()).toBe(false)
		confirm.mockRestore()
		wrapper.unmount()
	})
})
