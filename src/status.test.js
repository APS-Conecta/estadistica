import { describe, expect, it } from 'vitest'
import { countByFilter, statusOf, targetNameOf, targetOf } from './status.js'

/** One of GET resumen's metas, only the fields a status reads. */
const meta = (fields) => ({ manual: false, meta_nacional: 90, meta_comprometida: null, estado: null, porcentaje: null, ...fields })

describe('statusOf — a meta of GET resumen', () => {
	it('judges a figure against the meta nacional while no meta comprometida is typed', () => {
		const nacional = meta({ porcentaje: 66.67 })
		expect(statusOf(nacional)).toEqual({ kind: 'error', filter: 'no-cumple', label: 'No cumple' })
		expect([targetNameOf(nacional), targetOf(nacional)]).toEqual(['meta nacional', 90])
		expect(statusOf(meta({ porcentaje: 90 }))).toEqual({ kind: 'success', filter: 'cumple', label: 'Cumple' })
	})

	it('judges against the meta comprometida over the meta nacional once one is typed', () => {
		const comprometida = meta({ porcentaje: 66.67, meta_comprometida: 60 })
		expect(statusOf(comprometida).kind).toBe('success')
		expect([targetNameOf(comprometida), targetOf(comprometida)]).toEqual(['meta comprometida', 60])
		expect(statusOf(meta({ porcentaje: 95, meta_comprometida: 97.5 })).kind).toBe('error')
	})

	it('lets the engine\'s estado win: it says why there is no percentage', () => {
		expect(statusOf(meta({ estado: 'esperando ruta oficial' }))).toEqual({ kind: 'waiting', filter: 'ruta', label: 'Esperando ruta oficial' })
		expect(statusOf(meta({ estado: 'falta el denominador oficial de 2026' }))).toEqual({ kind: 'warning', filter: 'denominador', label: 'Falta el denominador oficial' })
		expect(statusOf(meta({ estado: 'esperando ruta oficial', porcentaje: 95 })).kind).toBe('waiting')
	})

	it('never turns sin informar into a figure', () => {
		const sinInformar = { kind: 'pending', filter: 'sin', label: 'Sin informar' }
		expect(statusOf(meta({}))).toEqual(sinInformar)
		expect(statusOf(meta({ porcentaje: 0 })).kind, 'a written 0 is a figure').toBe('error')
		expect(statusOf(meta({ manual: true }))).toEqual(sinInformar)
	})

	it('judges a manual meta by its cumplimiento informado, which GET resumen serves as its porcentaje', () => {
		expect(statusOf(meta({ manual: true, meta_nacional: 100, porcentaje: 100 })).kind).toBe('success')
		expect(statusOf(meta({ manual: true, meta_nacional: 100, porcentaje: 80 })).kind).toBe('error')
	})
})

describe('countByFilter', () => {
	it('counts every meta once, under its own status and under todas', () => {
		const metas = [meta({ porcentaje: 95 }), meta({ porcentaje: 10 }), meta({ porcentaje: 12 }), meta({}), meta({ estado: 'esperando ruta oficial' })]
		expect(countByFilter(metas)).toEqual({ todas: 5, cumple: 1, 'no-cumple': 2, sin: 1, ruta: 1 })
	})
})
