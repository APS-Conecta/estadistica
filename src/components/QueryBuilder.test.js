// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import QueryBuilder from './QueryBuilder.vue'
import Visualization from './Visualization.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

const OCS = '/ocs/v2.php/apps/estadistica/api/v1'

const ok = (data) => Promise.resolve({ data: { ocs: { meta: { status: 'ok' }, data } } })

/** GET fuentes (Sources::all): Serie A with 2025 whole and 2026 to August, and the register. */
const FUENTES = [
	{
		id: 'rem-a', nombre: 'REM Serie A', origen: 'datos públicos DEIS', fecha_corte: '2026-08', filas: 9, estado: 'ok',
		anios: [
			{ anio: 2026, fecha_corte: '2026-08', filas: 5, estado: 'ok', sin_descripcion: 0, etiquetas_de: 2026 },
			{ anio: 2025, fecha_corte: '2025-12', filas: 4, estado: 'ok', sin_descripcion: 0, etiquetas_de: 2025 },
		],
	},
	{ id: 'rem-p', nombre: 'REM Serie P', origen: 'datos públicos DEIS', fecha_corte: null, filas: 0, estado: 'sin datos', anios: [] },
	{ id: 'establecimientos', nombre: 'Establecimientos de salud (Base de Establecimientos)', origen: 'datos públicos DEIS', fecha_corte: '2025', filas: 3, estado: 'ok', anios: [] },
]

/** Catalog::tree of `anio`: A03 with one sección, whose title the 2025 dictionary words differently. */
function catalogo(anio) {
	const seccion = anio === 2026 ? 'SECCIÓN A: EVALUACIONES' : 'SECCIÓN A. EVALUACIONES'
	return {
		anio,
		etiquetas_de: anio,
		diccionario: `DICCIONARIO SA_${anio}`,
		hojas: [{
			hoja: 'A03',
			titulo: 'REM-A03',
			secciones: [{
				seccion,
				columnas: [{ col: 'col08', etiqueta: '20-24' }, { col: 'col09', etiqueta: '25-29' }],
				prestaciones: [{ codigo: '02010420', etiqueta: 'Test' }],
			}],
		}],
	}
}

const REGISTER = { tipos: [{ valor: 'CESFAM', establecimientos: 2 }], servicios: [{ valor: 'Servicio X', establecimientos: 3 }] }

function serve(fuentes = FUENTES) {
	axios.get.mockImplementation((url, { params } = {}) => {
		if (url === `${OCS}/fuentes`) {
			return ok(fuentes)
		}
		if (url === `${OCS}/fuentes/rem-a`) {
			return ok({ ...FUENTES[0], catalogo: catalogo(params.anio) })
		}
		if (url === `${OCS}/fuentes/establecimientos`) {
			return ok({ ...FUENTES[2], catalogo: REGISTER })
		}
		return Promise.reject(new Error(`unexpected GET ${url}`))
	})
}

/** The builder, once the fuentes and the catálogo have arrived. Visualization is stubbed: jsdom has no canvas. */
async function builder(establishment = { deisCode: '900001', type: '', comunaCut: '', configured: true }) {
	const wrapper = mount(QueryBuilder, { props: { establishment }, global: { stubs: { Visualization: true } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	serve()
})

describe('QueryBuilder — Datos → Filtrar → Resumir → Visualizar (a7, a22)', () => {
	it('posts the spec the four steps chose, with no chart type and no establishment in it', async () => {
		const wrapper = await builder()
		const vm = wrapper.vm

		// Filtrar: the only sección is taken with its hoja.
		vm.chooseHoja('A03')
		expect(vm.state.filtrar.seccion).toBe('SECCIÓN A: EVALUACIONES')
		vm.state.filtrar.columnas = ['col09', 'col08']
		// Resumir: by month and column, beside the country and the peers of the same tipo.
		vm.state.resumir.agrupar = ['mes', 'columna']
		vm.state.resumir.pares.tipo = true
		// Visualizar: lines.
		vm.state.visualizar.tipo = 'linea'

		axios.post.mockReturnValueOnce(ok({ series: { local: [], nacional: [], pares: [] }, etiquetas: { local: '900001', pares: { tipo: 'CESFAM' }, codigos: [] }, fuente: FUENTES[0] }))
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${OCS}/consultas`, {
			consulta: {
				fuente: 'rem-a',
				periodo: { desde: '2026-01', hasta: '2026-08' },
				filtros: { hoja: 'A03', seccion: 'SECCIÓN A: EVALUACIONES', columnas: ['col08', 'col09'] },
				agrupar: ['mes', 'columna'],
				metrica: 'promedio_establecimiento',
				comparar: { nacional: true, pares: { tipo: true } },
			},
		})
		expect(wrapper.findComponent(Visualization).props('tipo')).toBe('linea')
		wrapper.unmount()
	})

	it('shows each fuente with its origen and fecha de corte, and the register it compares with', async () => {
		const wrapper = await builder()

		expect(wrapper.text()).toContain('REM Serie A')
		expect(wrapper.text()).toContain('datos públicos DEIS, 2025 a 2026, con cifras hasta agosto de 2026.')
		expect(wrapper.text()).toContain('datos públicos DEIS: todavía no hay cifras cargadas.')
		expect(wrapper.text()).toContain('Base de Establecimientos (datos públicos DEIS, 2025): 3 establecimientos')
		wrapper.unmount()
	})

	it('compares as promedio: the suma is off while two series share the axis, and says why', async () => {
		const wrapper = await builder()

		expect(wrapper.vm.metrica).toBe('promedio_establecimiento')
		expect(wrapper.text()).toContain('Al comparar se usa el promedio por establecimiento')

		wrapper.vm.state.resumir.nacional = false
		await flushPromises()
		expect(wrapper.vm.metrica).toBe('suma')
		expect(wrapper.text()).not.toContain('Al comparar se usa el promedio por establecimiento')
		wrapper.unmount()
	})

	it('offers «igual que su establecimiento» only to a configured install', async () => {
		const configured = await builder()
		expect(configured.vm.traits.find((t) => t.id === 'tipo').options[0]).toStrictEqual({ id: true, label: 'Igual que su establecimiento' })
		expect(configured.vm.traits.map((t) => t.id)).toContain('comuna')
		configured.unmount()

		const unconfigured = await builder({ deisCode: '', type: '', comunaCut: '', configured: false })
		expect(unconfigured.vm.traits.find((t) => t.id === 'tipo').options.map((o) => o.id)).toStrictEqual(['CESFAM'])
		expect(unconfigured.vm.traits.map((t) => t.id)).not.toContain('comuna')
		unconfigured.unmount()
	})

	it('reads the catálogo of the periodo\'s last year, and lets go of a sección that year words differently', async () => {
		const wrapper = await builder()
		expect(axios.get).toHaveBeenCalledWith(`${OCS}/fuentes/rem-a`, { params: { anio: 2026 } })

		wrapper.vm.chooseHoja('A03')
		wrapper.vm.state.filtrar.columnas = ['col08']
		wrapper.vm.choosePeriod('hasta', '2025-12')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(`${OCS}/fuentes/rem-a`, { params: { anio: 2025 } })
		expect(wrapper.vm.state.filtrar).toMatchObject({ hoja: 'A03', seccion: null, columnas: [] })
		expect(wrapper.vm.reason).toBe('El periodo termina antes de empezar.')
		wrapper.unmount()
	})

	it('replaces the last answer with the server\'s Spanish refusal', async () => {
		const wrapper = await builder()
		wrapper.vm.chooseHoja('A03')
		wrapper.vm.state.filtrar.columnas = ['col08']
		axios.post.mockReturnValueOnce(ok({ series: { local: [], nacional: [], pares: null }, etiquetas: { local: '900001', pares: null, codigos: [] }, fuente: FUENTES[0] }))
		await wrapper.vm.ask()
		await flushPromises()
		expect(wrapper.findComponent(Visualization).exists()).toBe(true)

		axios.post.mockRejectedValueOnce({
			message: 'Request failed with status code 422',
			response: { status: 422, data: { ocs: { meta: { status: 'failure' }, data: { message: 'La consulta tardó más de 10 s y se canceló.' } } } },
		})
		await wrapper.vm.ask()
		await flushPromises()

		expect(wrapper.text()).toContain('La consulta tardó más de 10 s y se canceló.')
		expect(wrapper.findComponent(Visualization).exists()).toBe(false)
		wrapper.unmount()
	})

	it('says there are no REM figures yet when none is loaded', async () => {
		serve(FUENTES.map((fuente) => ({ ...fuente, filas: 0, anios: [] })))
		const wrapper = await builder()

		expect(wrapper.text()).toContain('Todavía no hay cifras REM')
		expect(axios.post).not.toHaveBeenCalled()
		wrapper.unmount()
	})
})
