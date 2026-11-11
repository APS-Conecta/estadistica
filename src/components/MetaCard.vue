<!--
	One meta of the Resumen (#83): its meta and the metas year, its title,
	its figure (the % and «N de D») with a meter against its target, its
	status as an icon and a word, and the gap to the target, or why it has no
	percentage, with the numerador already counted. Its meta mínima is a
	reference from its base year's cierre (#51). The title opens its detail on
	Evolución, and the figure on Origen (#86).
-->
<template>
	<article class="estadistica-meta" :aria-labelledby="`resumen-${meta.codigo}-titulo`">
		<p class="estadistica-meta__codigo">
			Meta {{ meta.codigo }} {{ anio }}
		</p>
		<h3 :id="`resumen-${meta.codigo}-titulo`" class="estadistica-meta__titulo">
			<button type="button" class="estadistica-meta__abrir" @click="$emit('open', 'evolucion', $event)">
				{{ meta.nombre }}
			</button>
		</h3>

		<template v-if="judged">
			<p>
				<button type="button" class="estadistica-cifra estadistica-meta__cifra" @click="$emit('open', 'origen', $event)">
					<span class="estadistica-meta__porcentaje">{{ percent(meta.porcentaje) }}</span> <span v-if="!meta.manual" class="estadistica-meta__cuenta">{{ figure(meta.numerador) }} de {{ figure(meta.denominador) }}</span>
				</button>
			</p>
			<GoalMeter :value="meta.porcentaje"
				:target="target"
				:target-name="targetName"
				:label="`Meta ${meta.codigo} ${anio} frente a su ${targetName}`" />
		</template>
		<p v-else-if="counted" class="estadistica-meta__hint">
			<strong class="estadistica-meta__contado">{{ figure(counted.numerador) }}</strong> en el numerador, {{ countedAt }}
		</p>

		<StatusBadge :status="status" />
		<p class="estadistica-meta__hint">
			{{ why }}
		</p>

		<template v-if="meta.meta_minima">
			<p v-if="meta.meta_minima.porcentaje === null" class="estadistica-meta__hint">
				Sin meta mínima {{ anio }}: {{ meta.meta_minima.motivo }}.
			</p>
			<template v-else>
				<p class="estadistica-meta__minima">
					Meta mínima {{ anio }}: {{ tenth(meta.meta_minima.porcentaje) }} % — referencia: la meta la fija el Servicio de Salud
				</p>
				<p v-if="meta.meta_minima.base !== null" class="estadistica-meta__hint">
					Desde el cierre {{ meta.meta_minima.base }}<template v-if="meta.meta_minima.motivo">; {{ meta.meta_minima.motivo }}</template>.
				</p>
			</template>
		</template>
	</article>
</template>

<script>
import { formatValue } from '../charts.js'
import { monthName } from '../query.js'
import { statusOf, targetNameOf, targetOf } from '../status.js'
import GoalMeter from './GoalMeter.vue'
import StatusBadge from './StatusBadge.vue'

export default {
	name: 'MetaCard',

	components: { GoalMeter, StatusBadge },

	props: {
		/** One of GET resumen's metas. */
		meta: { type: Object, required: true },
		/** GET resumen's anio: the metas year. */
		anio: { type: Number, required: true },
	},

	emits: ['open'],

	computed: {
		status() {
			return statusOf(this.meta)
		},
		/** Whether the meta has a figure to judge: it cumple or it does not. */
		judged() {
			return this.status.kind === 'success' || this.status.kind === 'error'
		},
		target() {
			return targetOf(this.meta)
		},
		targetName() {
			return targetNameOf(this.meta)
		},
		/** The current year's last month with a numerador counted, for a meta with no percentage. */
		counted() {
			return this.meta.historico.at(-1)?.meses.filter((mes) => mes.numerador !== null).at(-1) ?? null
		},
		/** When the numerador was counted: «a febrero de 2026», a Serie P one at its corte: «al corte de junio de 2026». */
		countedAt() {
			return `${this.meta.historico.at(-1)?.ruta?.numerador?.fuente === 'rem-p' ? 'al corte de' : 'a'} ${monthName(this.counted.mes)}`
		},
		why() {
			if (this.judged) {
				const gap = this.meta.porcentaje - this.target
				return `${gap < 0 ? '−' : '+'}${formatValue(Math.abs(gap))} puntos frente a la ${this.targetName} (${this.percent(this.target)}).`
			}
			if (this.meta.estado) {
				return `Sin porcentaje: ${this.meta.estado}.`
			}
			return this.meta.manual ? 'Todavía no se ha informado su cumplimiento.' : 'Nadie informó esta meta en el periodo.'
		},
	},

	methods: {
		figure(value) {
			return formatValue(value)
		},

		percent(value) {
			return `${formatValue(value)} %`
		},

		/** A meta mínima, always with its one decimal: «62,0». */
		tenth(value) {
			return value.toLocaleString('es-CL', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
		},
	},
}
</script>

<style scoped>
.estadistica-meta {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	height: 100%;
	padding: calc(var(--default-grid-baseline) * 4) calc(var(--default-grid-baseline) * 4.5);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	background-color: var(--color-main-background);
}

.estadistica-meta p,
.estadistica-meta h3 {
	margin: 0;
}

.estadistica-meta__codigo {
	align-self: start;
	padding: 1px calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-small);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.estadistica-meta__titulo {
	font-size: 16px;
	line-height: 1.35;
}

.estadistica-meta__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-meta__cifra {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 2.5);
}

.estadistica-meta__porcentaje {
	font-size: 2em;
	font-weight: bold;
	line-height: 1.2;
}

.estadistica-meta__cuenta {
	color: var(--color-text-maxcontrast);
}

.estadistica-meta__cifra,
.estadistica-meta__contado {
	font-variant-numeric: tabular-nums;
}

.estadistica-meta__contado {
	color: var(--color-main-text);
	font-size: 1.4em;
}

.estadistica-meta__minima {
	font-weight: bold;
}
</style>
