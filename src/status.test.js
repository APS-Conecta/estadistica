import { describe, expect, it } from 'vitest'
import { countByFilter, statusOf, targetNameOf, targetOf } from './status.js'

/** One of GET resumen's metas, only the fields a status and its target read; cumple as the engine serves it. */
const meta = (fields) => ({ manual: false, meta_nacional: 90, meta_comprometida: null, estado: null, porcentaje: null, cumple: null, ...fields })

describe('statusOf — a meta of GET resumen', () => {
	it('judges a figure against the meta nacional while no meta comprometida is typed', () => {
		const nacional = meta({ porcentaje: 66.67, cumple: false })
		expect(statusOf(nacional)).toEqual({ kind: 'error', filter: 'no-cumple', label: 'No cumple' })
		expect([targetNameOf(nacional), targetOf(nacional)]).toEqual(['meta nacional', 90])
		expect(statusOf(meta({ porcentaje: 90, cumple: true }))).toEqual({ kind: 'success', filter: 'cumple', label: 'Cumple' })
	})

	it('judges against the meta comprometida over the meta nacional once one is typed', () => {
		const comprometida = meta({ porcentaje: 66.67, meta_comprometida: 60, cumple: true })
		expect(statusOf(comprometida).kind).toBe('success')
		expect([targetNameOf(comprometida), targetOf(comprometida)]).toEqual(['meta comprometida', 60])
		expect(statusOf(meta({ porcentaje: 95, meta_comprometida: 97.5, cumple: false })).kind).toBe('error')
	})

	it('takes the verdict from the engine\'s cumple, as the Nota does, never judging the figure again', () => {
		expect(statusOf({ estado: null, porcentaje: 58.33, cumple: true })).toEqual({ kind: 'success', filter: 'cumple', label: 'Cumple' })
		expect(statusOf({ estado: null, porcentaje: 58.33, cumple: false })).toEqual({ kind: 'error', filter: 'no-cumple', label: 'No cumple' })
	})

	it('lets the engine\'s estado win: it says why there is no percentage', () => {
		expect(statusOf(meta({ estado: 'esperando ruta oficial' }))).toEqual({ kind: 'waiting', filter: 'ruta', label: 'Esperando ruta oficial' })
		expect(statusOf(meta({ estado: 'falta el denominador oficial de 2026' }))).toEqual({ kind: 'warning', filter: 'denominador', label: 'Falta el denominador oficial' })
		expect(statusOf(meta({ estado: 'esperando ruta oficial', porcentaje: 95, cumple: true })).kind).toBe('waiting')
	})

	it('never turns sin informar into a figure', () => {
		const sinInformar = { kind: 'pending', filter: 'sin', label: 'Sin informar' }
		expect(statusOf(meta({}))).toEqual(sinInformar)
		expect(statusOf(meta({ porcentaje: 0, cumple: false })).kind, 'a written 0 is a figure').toBe('error')
		expect(statusOf(meta({ manual: true }))).toEqual(sinInformar)
	})

	it('judges a manual meta by its cumplimiento informado, which GET resumen serves as its porcentaje', () => {
		expect(statusOf(meta({ manual: true, meta_nacional: 100, porcentaje: 100, cumple: true })).kind).toBe('success')
		expect(statusOf(meta({ manual: true, meta_nacional: 100, porcentaje: 80, cumple: false })).kind).toBe('error')
	})
})

describe('countByFilter', () => {
	it('counts every meta once, under its own status and under todas', () => {
		const metas = [meta({ porcentaje: 95, cumple: true }), meta({ porcentaje: 10, cumple: false }), meta({ porcentaje: 12, cumple: false }), meta({}), meta({ estado: 'esperando ruta oficial' })]
		expect(countByFilter(metas)).toEqual({ todas: 5, cumple: 1, 'no-cumple': 2, sin: 1, ruta: 1 })
	})
})
