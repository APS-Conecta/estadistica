/**
 * A meta's status, from the fields GET resumen and GET indicadores serve
 * (#83): pure, so every view reads a meta the same way. A status is one word and one icon, never
 * colour alone; its kind picks Nextcloud's status pair.
 */
import { mdiAlertCircleOutline, mdiCheck, mdiClockOutline, mdiClose, mdiMinusCircleOutline } from './icons.js'

/** One icon per kind of status. */
export const ICONS = {
	success: mdiCheck,
	error: mdiClose,
	warning: mdiAlertCircleOutline,
	waiting: mdiClockOutline,
	pending: mdiMinusCircleOutline,
}

/** The Resumen's filters, in the order its tiles show them. */
export const FILTERS = [
	{ id: 'todas', label: 'Todas las metas' },
	{ id: 'cumple', label: 'Cumplen', kind: 'success' },
	{ id: 'no-cumple', label: 'No cumplen', kind: 'error' },
	{ id: 'denominador', label: 'Falta el denominador', kind: 'warning' },
	{ id: 'ruta', label: 'Esperando ruta oficial', kind: 'waiting' },
	{ id: 'sin', label: 'Sin informar', kind: 'pending' },
]

/** The target the meter and the gap show (CONTEXT.md, cumple): its meta comprometida when typed, else the meta nacional. */
export const targetOf = (meta) => meta.meta_comprometida ?? meta.meta_nacional

/** «meta comprometida» or «meta nacional». */
export const targetNameOf = (meta) => meta.meta_comprometida === null ? 'meta nacional' : 'meta comprometida'

const SIN_INFORMAR = { kind: 'pending', filter: 'sin', label: 'Sin informar' }

/**
 * A meta's status. The engine's estado wins, as it says why there is no
 * percentage; a meta with neither is sin informar, never a figure. Else
 * the engine's cumple decides, the verdict the Nota reads too. A manual
 * meta's porcentaje is its cumplimiento informado.
 *
 * @param {object} meta one of GET resumen's metas
 * @return {{kind: string, filter: string, label: string}}
 */
export function statusOf(meta) {
	if (meta.estado?.startsWith('esperando ruta oficial')) {
		return { kind: 'waiting', filter: 'ruta', label: 'Esperando ruta oficial' }
	}
	if (meta.estado) {
		return { kind: 'warning', filter: 'denominador', label: 'Falta el denominador oficial' }
	}
	if (meta.porcentaje === null) {
		return SIN_INFORMAR
	}
	return meta.cumple
		? { kind: 'success', filter: 'cumple', label: 'Cumple' }
		: { kind: 'error', filter: 'no-cumple', label: 'No cumple' }
}

/** A GET indicadores meta's last figure: its newest month with a percentage, or a manual meta's cumplimiento informado; null with none. */
export const lastPercentOf = (indicador) => indicador.manual
	? indicador.cumplimiento
	: indicador.meses.filter((mes) => mes.porcentaje !== null).at(-1)?.porcentaje ?? null

/**
 * A GET indicadores meta's status, in statusOf's words: its last figure
 * against its target (CONTEXT.md, cumple), after the engine's rules for a
 * meta whose ruta is not yet oficial, a seed "oficial": false with absent
 * meaning oficial (Resumen::waiting), and for one whose ages cut a FONASA
 * band (Resumen::missing).
 *
 * @param {object} indicador one of GET indicadores' indicadores
 * @return {{kind: string, filter: string, label: string}}
 */
export function indicadorStatus(indicador) {
	if (indicador.numerador?.oficial === false || indicador.denominador?.oficial === false) {
		return statusOf({ estado: 'esperando ruta oficial' })
	}
	const porcentaje = lastPercentOf(indicador)
	return statusOf({
		estado: indicador.poblacion?.falta_oficial ? 'falta el denominador oficial' : null,
		porcentaje,
		cumple: porcentaje !== null && porcentaje >= targetOf(indicador),
	})
}

/** How many metas each filter keeps, by filter id; a filter that keeps none is absent. */
export function countByFilter(metas) {
	const counts = { todas: metas.length }
	for (const meta of metas) {
		const { filter } = statusOf(meta)
		counts[filter] = (counts[filter] ?? 0) + 1
	}
	return counts
}
