<!--
	Resumen (docs/adr/0009): the view that opens first. One card per meta of
	the newest metas, for this install's establishment: the percentage and
	«N de D» of the meta's last month with a figure, dated by the
	establishment's own last month in the meta's serie, whether it cumple, in
	colour and in words, and its histórico: a line per comparable year with the
	table under it, and its meta mínima, a reference from its base year's
	cierre, or why it has none. At the top, the nota a model wrote about what changed, when
	there is one, labelled as a model's. Read from GET resumen alone, so the page shows what the API
	answers. Each figure, on the card and in its table, is a button that opens
	its source panel for the figure's month; closing it gives the focus back.
-->
<template>
	<section class="estadistica-resumen">
		<NcNoteCard v-if="failed" type="error">
			{{ failed }}
		</NcNoteCard>

		<NcNoteCard v-else-if="missing" type="info">
			Todavía no hay Metas Sanitarias cargadas. Quien administra Estadística las carga como un
			archivo en «Cargar un archivo a mano».
		</NcNoteCard>

		<p v-else-if="!resumen" class="estadistica-resumen__hint">
			Cargando…
		</p>

		<template v-else>
			<h2>{{ resumen.nombre }}</h2>

			<!-- A model's text (CONTEXT.md, nota): untrusted, so plain text only, never v-html. -->
			<aside v-if="resumen.nota" class="estadistica-resumen__nota">
				<p>{{ resumen.nota }}</p>
				<p class="estadistica-resumen__hint">
					Escrita por un modelo de IA a partir de las cifras
				</p>
			</aside>

			<ul class="estadistica-resumen__metas">
				<li v-for="meta in resumen.metas" :key="meta.codigo">
					<article :class="['estadistica-resumen__meta', cumpleClass(meta)]"
						tabindex="0"
						:aria-labelledby="`resumen-${meta.codigo}-titulo`"
						:aria-describedby="`resumen-${meta.codigo}-cifra`">
						<h3 :id="`resumen-${meta.codigo}-titulo`">
							Meta {{ meta.codigo }} {{ resumen.anio }}
						</h3>
						<p class="estadistica-resumen__hint">
							{{ meta.nombre }}
						</p>

						<div :id="`resumen-${meta.codigo}-cifra`">
							<p v-if="meta.estado" class="estadistica-resumen__estado">
								Sin porcentaje: {{ meta.estado }}.
							</p>
							<p v-else-if="meta.manual" class="estadistica-resumen__estado">
								<template v-if="meta.porcentaje === null">
									Todavía no se ha informado su cumplimiento.
								</template>
								<template v-else>
									Cumplimiento informado: <button type="button" class="estadistica-cifra" @click="open(meta, card(meta), $event)">{{ percent(meta.porcentaje) }}</button>
								</template>
							</p>
							<template v-else-if="meta.porcentaje !== null">
								<p class="estadistica-resumen__porcentaje">
									<button type="button" class="estadistica-cifra" @click="open(meta, card(meta), $event)">{{ percent(meta.porcentaje) }}</button>
								</p>
								<p class="estadistica-resumen__cuenta">
									<button type="button" class="estadistica-cifra" @click="open(meta, card(meta), $event)">{{ figure(meta.numerador) }}</button>
									de
									<button type="button" class="estadistica-cifra" @click="open(meta, card(meta), $event)">{{ figure(meta.denominador) }}</button>
								</p>
								<p class="estadistica-resumen__hint">
									Datos al {{ monthName(meta.datos_al) }}<template v-if="meta.origen"> · {{ originText(meta.origen, meta.fecha_corte) }}</template>
								</p>
							</template>
							<p v-else class="estadistica-resumen__estado">
								Sin informar
							</p>

							<p v-if="meta.cumple !== null" class="estadistica-resumen__cumple">
								{{ meta.cumple ? 'Cumple' : 'No cumple' }} la meta
								{{ meta.meta_comprometida === null ? 'nacional' : 'comprometida' }}
								({{ percent(meta.meta_comprometida ?? meta.meta_nacional) }})
							</p>
						</div>

						<template v-if="meta.meta_minima">
							<p v-if="meta.meta_minima.porcentaje === null" class="estadistica-resumen__hint">
								Sin meta mínima {{ resumen.anio }}: {{ meta.meta_minima.motivo }}.
							</p>
							<template v-else>
								<p class="estadistica-resumen__minima">
									Meta mínima {{ resumen.anio }}: {{ tenth(meta.meta_minima.porcentaje) }} % — referencia: la meta la fija el Servicio de Salud
								</p>
								<p v-if="meta.meta_minima.base !== null" class="estadistica-resumen__hint">
									Desde el cierre {{ meta.meta_minima.base }}<template v-if="meta.meta_minima.motivo">; {{ meta.meta_minima.motivo }}</template>.
								</p>
							</template>
						</template>

						<ResumenHistorico v-if="meta.historico.length" :meta="meta" @open="(cifra, event) => open(meta, cifra, event)" />
					</article>
				</li>
			</ul>

			<!-- Mounted afresh on each figure, so each opening moves the focus into the panel. -->
			<ResumenFuente v-if="panel"
				:key="panel.opening"
				:meta="panel.meta"
				:cifra="panel.cifra"
				:anio="resumen.anio"
				:referencia="resumen.referencia"
				@close="close" />
		</template>
	</section>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsMessageFrom, warnError } from '../api.js'
import { formatValue } from '../charts.js'
import { defineAsyncComponent } from 'vue'
import { monthName, originText } from '../query.js'
import ResumenHistorico from './ResumenHistorico.vue'

// The panel and the app sidebar are their own chunk, fetched on the first click (#43's way).
const ResumenFuente = defineAsyncComponent(() => import(/* webpackChunkName: "fuente" */ './ResumenFuente.vue'))

export default {
	name: 'EstadisticaResumen',

	components: { NcNoteCard, ResumenFuente, ResumenHistorico },

	data() {
		return {
			resumen: null,
			missing: false,
			failed: '',
			/** The open panel's card, figure and opening count ({meta, cifra, opening}); opener is the figure that opened it. */
			panel: null,
			opener: null,
		}
	},

	async mounted() {
		try {
			this.resumen = await ocsGet('resumen')
		} catch (error) {
			if (error?.response?.status === 404) {
				this.missing = true
				return
			}
			warnError('could not read the resumen', error)
			this.failed = await ocsMessageFrom(error, 'No se pudo leer el resumen de las metas. Revise la conexión e inténtelo de nuevo.')
		}
	},

	methods: {
		monthName,
		originText,

		/** The card's own month, with the current year's ruta: its histórico's last year. */
		card(meta) {
			return { mes: meta.mes, origen: meta.origen, fecha_corte: meta.fecha_corte, ruta: meta.historico.at(-1)?.ruta ?? null }
		},

		open(meta, cifra, event) {
			this.panel = { meta, cifra, opening: (this.panel?.opening ?? 0) + 1 }
			this.opener = event.currentTarget
		},

		async close() {
			this.panel = null
			await this.$nextTick()
			this.opener?.focus()
		},

		cumpleClass(meta) {
			if (meta.cumple === null) {
				return ''
			}
			return meta.cumple ? 'estadistica-resumen__meta--cumple' : 'estadistica-resumen__meta--no-cumple'
		},

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
.estadistica-resumen {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.estadistica-resumen__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-resumen__nota {
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius-container);
	background-color: var(--color-primary-element-light);
}

/* As many cards as fit, one per row on a phone. */
.estadistica-resumen__metas {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr));
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.estadistica-resumen__meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	height: 100%;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-border-dark);
	border-radius: var(--border-radius-container);
}

.estadistica-resumen__meta:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/* Colour backs the words «Cumple» and «No cumple», never stands alone. */
.estadistica-resumen__meta--cumple {
	border-inline-start-color: var(--color-border-success);
}

.estadistica-resumen__meta--no-cumple {
	border-inline-start-color: var(--color-border-error);
}

.estadistica-resumen__porcentaje {
	font-size: 2em;
	font-weight: bold;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
}

.estadistica-resumen__cumple,
.estadistica-resumen__minima {
	font-weight: bold;
}

.estadistica-resumen__meta--cumple .estadistica-resumen__cumple {
	color: var(--color-text-success);
}

.estadistica-resumen__meta--no-cumple .estadistica-resumen__cumple {
	color: var(--color-text-error);
}
</style>

<style>
/* A figure that opens its source panel, on a card or in its table: a button
   that reads as the figure, underlined as a link. */
.estadistica-resumen .estadistica-cifra,
.estadistica-resumen .estadistica-cifra:hover {
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	text-decoration: underline dotted;
	text-underline-offset: 0.2em;
	cursor: pointer;
}

.estadistica-resumen .estadistica-cifra:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}
</style>
