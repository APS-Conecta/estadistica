import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { ocsMessageFrom } from './api.js'
import {
	consult, groupLabel, initialState, metricFor, missing, monthName, monthsOf, specFrom,
} from './query.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateOcsUrl: (url) => `/ocs/v2.php${url}` }))

/** GET fuentes as Sources::all serves it: A loaded 2025 (whole) and 2026 (to August). */
const FUENTES = [
	{
		id: 'rem-a', nombre: 'REM Serie A', origen: 'datos públicos DEIS', fecha_corte: '2026-08', filas: 9, estado: 'ok',
		anios: [
			{ anio: 2026, fecha_corte: '2026-08', filas: 5, estado: 'ok', sin_descripcion: 0, etiquetas_de: 2026 },
			{ anio: 2025, fecha_corte: '2025-12', filas: 4, estado: 'ok', sin_descripcion: 0, etiquetas_de: 2025 },
			{ anio: 2024, fecha_corte: null, filas: 0, estado: 'sin datos', sin_descripcion: 0, etiquetas_de: null },
		],
	},
	{ id: 'rem-p', nombre: 'REM Serie P', origen: 'datos públicos DEIS', fecha_corte: null, filas: 0, estado: 'sin datos', anios: [] },
	{ id: 'establecimientos', nombre: 'Establecimientos', origen: 'datos públicos DEIS', fecha_corte: '2025', filas: 3, estado: 'ok', anios: [] },
]

/** Catalog::tree for A03: one named sección and one DEIS left without a title. */
const CATALOGO = {
	anio: 2026,
	etiquetas_de: 2026,
	diccionario: 'DICCIONARIO SA_26.xlsm',
	hojas: [{
		hoja: 'A03',
		titulo: 'REM-A03. Aplicación de instrumentos',
		secciones: [
			{ seccion: '', columnas: [{ col: 'col01', etiqueta: 'Total' }], prestaciones: [{ codigo: '03500100', etiqueta: 'Sin sección' }, { codigo: '03500101', etiqueta: 'Otra' }] },
			{ seccion: 'SECCIÓN A: EVALUACIONES', columnas: [{ col: 'col08', etiqueta: '20-24' }, { col: 'col09', etiqueta: '25-29' }], prestaciones: [{ codigo: '02010420', etiqueta: 'Test' }] },
		],
	}],
}

/** The builder's four steps, as a person walks them. */
function walked(overrides = {}) {
	const state = initialState(FUENTES)
	Object.assign(state.filtrar, { hoja: 'A03', seccion: 'SECCIÓN A: EVALUACIONES', codigos: [], columnas: ['col09', 'col08'] }, overrides.filtrar)
	Object.assign(state.resumir, overrides.resumir)
	Object.assign(state.visualizar, overrides.visualizar)
	return state
}

describe('specFrom — the spec the builder emits (a7)', () => {
	it('turns Datos → Filtrar → Resumir → Visualizar into QuerySpec\'s keys, and nothing else', () => {
		const state = walked({
			filtrar: { desde: '2025-10', hasta: '2026-08', codigos: ['02010420'] },
			resumir: { agrupar: ['mes', 'columna'], nacional: true, pares: { servicio: ' Servicio X ', tipo: true, nivel: '', region: null } },
			visualizar: { tipo: 'linea' },
		})

		expect(specFrom(state, { configured: true, catalogo: CATALOGO })).toStrictEqual({
			fuente: 'rem-a',
			periodo: { desde: '2025-10', hasta: '2026-08' },
			filtros: { hoja: 'A03', seccion: 'SECCIÓN A: EVALUACIONES', codigos: ['02010420'], columnas: ['col08', 'col09'] },
			agrupar: ['mes', 'columna'],
			metrica: 'promedio_establecimiento',
			comparar: { nacional: true, pares: { tipo: true, servicio: 'Servicio X' } },
		})
	})

	it('names a sección and lets the server resolve its códigos when none are picked', () => {
		const spec = specFrom(walked(), { configured: true, catalogo: CATALOGO })

		expect(spec.filtros).toStrictEqual({ hoja: 'A03', seccion: 'SECCIÓN A: EVALUACIONES', columnas: ['col08', 'col09'] })
	})

	it('sends a sección DEIS left without a title as its códigos, which QuerySpec can name', () => {
		const spec = specFrom(walked({ filtrar: { seccion: '', columnas: ['col01'] } }), { configured: true, catalogo: CATALOGO })

		expect(spec.filtros).toStrictEqual({ hoja: 'A03', codigos: ['03500100', '03500101'], columnas: ['col01'] })
	})

	it('keeps the suma for one series, and asks nothing to compare when nothing is compared', () => {
		const alone = walked({ resumir: { nacional: false, metrica: 'suma' } })
		expect(specFrom(alone, { configured: true, catalogo: CATALOGO })).toStrictEqual({
			fuente: 'rem-a',
			periodo: { desde: '2026-01', hasta: '2026-08' },
			filtros: { hoja: 'A03', seccion: 'SECCIÓN A: EVALUACIONES', columnas: ['col08', 'col09'] },
			agrupar: ['mes'],
			metrica: 'suma',
		})

		// Unconfigured: the country is the only series, so it may be summed.
		const country = walked({ resumir: { nacional: true, metrica: 'suma' } })
		expect(specFrom(country, { configured: false, catalogo: CATALOGO }).metrica).toBe('suma')
		expect(specFrom(country, { configured: false, catalogo: CATALOGO }).comparar).toStrictEqual({ nacional: true })
	})

	it('compares as promedio por establecimiento whenever two series share the axis', () => {
		expect(metricFor({ metrica: 'suma', nacional: true, pares: {} }, true)).toBe('promedio_establecimiento')
		expect(metricFor({ metrica: 'suma', nacional: false, pares: { tipo: true } }, true)).toBe('promedio_establecimiento')
		expect(metricFor({ metrica: 'suma', nacional: true, pares: { tipo: 'CESFAM' } }, false)).toBe('promedio_establecimiento')
		expect(metricFor({ metrica: 'suma', nacional: false, pares: {} }, true)).toBe('suma')
	})

	it('never carries the chart type: QuerySpec refuses a key it does not know', () => {
		const spec = specFrom(walked({ visualizar: { tipo: 'tabla' } }), { configured: true, catalogo: CATALOGO })

		expect(Object.keys(spec)).toStrictEqual(['fuente', 'periodo', 'filtros', 'agrupar', 'metrica', 'comparar'])
		expect(JSON.stringify(spec)).not.toContain('tabla')
	})
})

describe('missing — why «Consultar» is not ready yet', () => {
	it('says what is missing, in the order the steps ask it', () => {
		const state = initialState(FUENTES)
		expect(missing(state, true)).toBe('Elija una hoja del REM.')

		state.filtrar.hoja = 'A03'
		expect(missing(state, true)).toBe('Elija una sección de la hoja.')

		state.filtrar.seccion = ''
		expect(missing(state, true)).toBe('Elija al menos una columna.')

		state.filtrar.columnas = ['col01']
		expect(missing(state, true)).toBe('')

		state.resumir.nacional = false
		expect(missing(state, false)).toBe('Compare con el país o con pares: esta instalación no tiene su establecimiento configurado.')

		state.filtrar.desde = '2026-09'
		expect(missing(state, true)).toBe('El periodo termina antes de empezar.')
	})

	it('says so when no REM figures are loaded', () => {
		const state = initialState(FUENTES.map((fuente) => ({ ...fuente, filas: 0 })))

		expect(state.datos.fuente).toBeNull()
		expect(missing(state, true)).toBe('Todavía no hay cifras REM cargadas.')
	})
})

describe('the periodo', () => {
	it('offers each loaded year\'s months up to its fecha de corte, oldest first', () => {
		const months = monthsOf(FUENTES[0])

		expect(months).toHaveLength(12 + 8)
		expect(months[0]).toStrictEqual({ id: '2025-01', label: 'enero de 2025' })
		expect(months.at(-1)).toStrictEqual({ id: '2026-08', label: 'agosto de 2026' })
	})

	it('starts on the newest year, January to its corte, grouped by month and beside the country', () => {
		const state = initialState(FUENTES)

		expect(state.datos.fuente).toBe('rem-a')
		expect(state.filtrar).toMatchObject({ desde: '2026-01', hasta: '2026-08' })
		expect(state.resumir).toStrictEqual({ agrupar: ['mes'], metrica: 'suma', nacional: true, pares: {} })
	})
})

describe('groupLabel — a grupo value as a person reads it', () => {
	const etiquetas = {
		local: '900001',
		pares: null,
		codigos: [{ codigo: '02010420', hoja: 'A03', seccion: 'S', prestacion: 'Test', columnas: { col08: '20-24' }, nota: null }],
	}

	it('names months, prestaciones, columns and a trait the register lacks', () => {
		expect(groupLabel('mes', '2026-03', etiquetas)).toBe('marzo de 2026')
		expect(groupLabel('codigo', '02010420', etiquetas)).toBe('Test (02010420)')
		expect(groupLabel('columna', 'col08', etiquetas)).toBe('20-24 (col08)')
		expect(groupLabel('columna', 'col50', etiquetas)).toBe('col50')
		expect(groupLabel('tipo', null, etiquetas)).toBe('sin dato')
		expect(groupLabel('anio', 2026, etiquetas)).toBe('2026')
		expect(monthName('2026-12')).toBe('diciembre de 2026')
	})
})

describe('consult — the one route every caller asks', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('posts {consulta} to the OCS consultas route and hands back the payload', async () => {
		const answer = { series: { local: [], nacional: null, pares: null }, etiquetas: {}, fuente: {} }
		axios.post.mockResolvedValueOnce({ data: { ocs: { meta: { status: 'ok' }, data: answer } } })
		const spec = specFrom(walked(), { configured: true, catalogo: CATALOGO })

		await expect(consult(spec)).resolves.toBe(answer)
		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/estadistica/api/v1/consultas', { consulta: spec })
	})

	it('reads a refusal\'s Spanish sentence inside the OCS envelope', async () => {
		const refused = { response: { status: 422, data: { ocs: { meta: { status: 'failure' }, data: { message: 'La métrica «max» no existe.' } } } } }

		await expect(ocsMessageFrom(refused, 'otra cosa')).resolves.toBe('La métrica «max» no existe.')
		await expect(ocsMessageFrom(new Error('Network Error'), 'Sin conexión.')).resolves.toBe('Sin conexión.')
	})
})
