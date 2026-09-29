// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
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

/** The section, mounted over one ingests() answer, once that answer has rendered. */
async function section(data) {
	axios.get.mockResolvedValueOnce({ data })
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
