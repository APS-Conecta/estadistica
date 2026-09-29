// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import axios from '@nextcloud/axios'
import AdminSettings from './AdminSettings.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({ firstYear: 2023, chosen: null, earliest: 2009, currentYear: 2026 }),
}))

/** One (año, serie) as AdminController::ingests() serves it (IngestState::toClient). */
function ingest(anio, rowCount) {
	return {
		anio, serie: 'A', origin: 'deis', rowCount, fechaCorte: `${anio}-08`, incomplete: false, detail: null,
		pending: false, lastAttempt: 1790000000, lastSuccess: 1790000000, lastError: null,
	}
}

/** Its dictionary row (Catalog::status). */
function dictionary(anio, fields) {
	return { anio, serie: 'A', file: null, codes: 0, unmapped: 0, labelsFrom: null, error: null, checkedAt: 1790000000, ...fields }
}

/** No PIV loaded and no establishment configured (PivImporter::status). */
const NO_PIV = { cortes: [], local: null }

/** The section, mounted over one ingests() answer, once that answer has rendered. ingests() always answers piv. */
async function section(data) {
	axios.get.mockResolvedValueOnce({ data: { piv: NO_PIV, ...data } })
	const wrapper = mount(AdminSettings)
	await flushPromises()
	return wrapper
}

const deis = { online: true, checkedAt: 1790000000, errors: null }

describe('AdminSettings — what the ingest state says (a6)', () => {
	it('shows each row\'s códigos «sin descripción» and whose labels a year borrows', async () => {
		const wrapper = await section({
			ingests: [ingest(2026, 4898561), ingest(2016, 1200000)],
			dictionaries: [
				dictionary(2026, { file: 'DICCIONARIO CODIGOS SA_26_V1.2.xlsm', codes: 3471, unmapped: 3, labelsFrom: 2026 }),
				dictionary(2016, { codes: 0, unmapped: 12, labelsFrom: 2017 }),
			],
			register: { origin: 'deis', year: 2025, loadedAt: 1790000000, rows: 5563 },
			deis,
		})

		const rows = wrapper.findAll('tbody tr').map((row) => row.text())
		expect(rows[0]).toContain('3')
		expect(rows[0]).toContain('Cargado')
		expect(wrapper.text()).toContain('sus códigos usan las etiquetas del diccionario de 2017')
		expect(wrapper.text()).toContain('En uso la 2025 del DEIS: 5.563 establecimientos')
		wrapper.unmount()
	})

	it('says why a dictionary could not be read', async () => {
		const wrapper = await section({
			ingests: [ingest(2026, 10)],
			dictionaries: [dictionary(2026, { error: 'El diccionario X no se pudo leer: no es una planilla.' })],
			register: { origin: null, year: null, loadedAt: null, rows: 0 },
			deis,
		})

		expect(wrapper.text()).toContain('Diccionario: El diccionario X no se pudo leer')
		expect(wrapper.text()).toContain('Todavía no se ha cargado')
		wrapper.unmount()
	})
})

/** The 2025 corte as PivImporter::cortes() serves it. */
const CORTE = { corte: '2025-12-31', rows: 35862, establishments: 2091, inscritos: 15788220, loadedAt: 1790000000 }

/** One cell of the establishment's figures (PivImporter::status, local.cells). */
function cell(edad, sexo, fonasa, manual = null, corte = '2025-12-31') {
	return { corte, edad, sexo, fonasa, manual }
}

/** The grid's input for a cell, by the name a screen reader gives it. */
function input(wrapper, name) {
	return wrapper.find(`input[aria-label="${name}"]`)
}

/** The PIV's «Guardar»: the page's last. */
function savePiv(wrapper) {
	return wrapper.findAll('button').filter((button) => button.text() === 'Guardar').at(-1)
}

describe('AdminSettings — the PIV (a11)', () => {
	afterEach(() => {
		vi.clearAllMocks()
		delete window.OC
	})

	it('lists FONASA\'s cortes, and without an establishment offers nothing to type', async () => {
		const wrapper = await section({ ingests: [], dictionaries: [], register: null, deis, piv: { cortes: [CORTE], local: null } })

		expect(wrapper.text()).toContain('Corte al 31-12-2025: 2.091 establecimientos, 15.788.220 inscritos con tramo de edad')
		expect(wrapper.text()).toContain('Sin un establecimiento configurado no hay población propia que escribir.')
		expect(wrapper.findAll('input.estadistica-admin__cell')).toHaveLength(0)
		wrapper.unmount()
	})

	it('says no corte is loaded yet, and where to load one', async () => {
		const wrapper = await section({ ingests: [], dictionaries: [], register: null, deis })

		expect(wrapper.text()).toContain('Todavía no hay ningún corte de FONASA cargado. Elija el CSV en «Cargar un archivo a mano»')
		wrapper.unmount()
	})

	it('shows FONASA\'s figure in gray beside what was typed, for the corte a meta of this year reads', async () => {
		const wrapper = await section({
			ingests: [], dictionaries: [], register: null, deis,
			piv: {
				cortes: [CORTE],
				local: { deisCode: '900001', cells: [cell(20, 'M', 812), cell(20, 'H', 790, 800), cell(20, 'M', 5, 6, '2024-12-31')] },
			},
		})

		expect(wrapper.text()).toContain('código DEIS 900001')
		expect(input(wrapper, 'Mujeres de 20 a 29 años').attributes('placeholder')).toBe('812')
		expect(input(wrapper, 'Mujeres de 20 a 29 años').element.value).toBe('')
		expect(input(wrapper, 'Hombres de 20 a 29 años').element.value).toBe('800')
		expect(input(wrapper, 'Mujeres de 0 a 9 años').attributes('placeholder')).toBe('—')

		await wrapper.find('.estadistica-admin__year input').setValue('2024')
		expect(input(wrapper, 'Mujeres de 20 a 29 años').element.value).toBe('6')
		expect(input(wrapper, 'Hombres de 20 a 29 años').element.value).toBe('')
		wrapper.unmount()
	})

	it('saves only the typed cells, and shows what the server kept', async () => {
		const local = { deisCode: '900001', cells: [cell(20, 'H', 790, 800)] }
		const wrapper = await section({ ingests: [], dictionaries: [], register: null, deis, piv: { cortes: [CORTE], local } })
		axios.put.mockResolvedValueOnce({
			data: { cortes: [CORTE], local: { deisCode: '900001', cells: [cell(0, 'M', null, 4200), cell(20, 'H', 790, 800)] } },
		})

		await input(wrapper, 'Mujeres de 0 a 9 años').setValue('4200')
		await savePiv(wrapper).trigger('click')
		await flushPromises()

		expect(axios.put).toHaveBeenCalledWith('/apps/estadistica/admin/piv', {
			year: 2025,
			cells: [{ edad: 0, sexo: 'M', inscritos: 4200 }, { edad: 20, sexo: 'H', inscritos: 800 }],
		})
		expect(wrapper.text()).toContain('Guardado.')
		expect(input(wrapper, 'Mujeres de 0 a 9 años').element.value).toBe('4200')
		wrapper.unmount()
	})

	it('refuses a figure that is not a whole number before asking the server', async () => {
		const wrapper = await section({
			ingests: [], dictionaries: [], register: null, deis,
			piv: { cortes: [], local: { deisCode: '900001', cells: [] } },
		})

		await input(wrapper, 'Hombres de 80 a más años').setValue('-3')
		await savePiv(wrapper).trigger('click')
		await flushPromises()

		expect(axios.put).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Hombres de 80 a más años: escriba un número entero, o deje la casilla en blanco.')
		wrapper.unmount()
	})

	it('says what a dropped FONASA file brought, and reads the cortes again', async () => {
		const wrapper = await section({ ingests: [], dictionaries: [], register: null, deis })
		window.OC = { dialogs: { filepicker: (title, pick) => pick('/Inscritos APS 2025 12.csv') } }
		axios.post.mockResolvedValueOnce({
			data: { piv: { cortes: ['2025-12-31'], establishments: 2091, inscritos: 15788220, unknownAge: 3642 } },
		})
		axios.get.mockResolvedValueOnce({ data: { ingests: [], dictionaries: [], register: null, deis, piv: { cortes: [CORTE], local: null } } })

		await wrapper.findAll('button').find((button) => button.text() === 'Elegir un archivo en Archivos…').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/estadistica/admin/drop', { path: '/Inscritos APS 2025 12.csv' })
		expect(wrapper.text()).toContain('Recibida la población inscrita de FONASA, corte al 31-12-2025: 2.091 establecimientos, 15.788.220 inscritos, ya en uso.')
		expect(wrapper.text()).toContain('3.642 inscritos sin tramo de edad no entran en ningún denominador.')
		expect(wrapper.text()).toContain('Corte al 31-12-2025: 2.091 establecimientos')
		wrapper.unmount()
	})
})
