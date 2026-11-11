<!--
	Resumen (docs/adr/0009, #83): the view that opens first. One card per meta
	of the newest metas, for this install's establishment (MetaCard). At the
	top, once for the page, up to which month each REM series has data; the
	nota a model wrote about what changed, when there is one, labelled as a
	model's; a note while the metas wait for their rutas oficiales; and the
	counts of each status, which filter the cards, with a polite line saying
	how many are shown. Read from GET resumen alone, so the page shows what
	the API answers. A card's figure, and each figure of its histórico, opens
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
			<p v-if="freshness" class="estadistica-resumen__hint">
				{{ freshness }}
			</p>

			<!-- A model's text (CONTEXT.md, nota): untrusted, so plain text only, never v-html. -->
			<aside v-if="resumen.nota" class="estadistica-resumen__nota">
				<p>{{ resumen.nota }}</p>
				<p class="estadistica-resumen__hint">
					Escrita por un modelo de IA a partir de las cifras
				</p>
			</aside>

			<NcNoteCard v-if="counts.ruta" type="info" heading="Las metas tendrán cifras cuando MINSAL confirme sus rutas REM">
				<p>
					La resolución ya fija cada meta, pero las Orientaciones Técnicas {{ resumen.anio }} (Anexo 3) todavía no dicen qué
					casillas del REM cuenta cada una. Estadística no usa estimaciones: mientras tanto, cada meta espera su ruta oficial.
				</p>
				<NcButton variant="secondary" @click="$emit('metas', resumen.anio - 1)">
					Ver las Metas Sanitarias {{ resumen.anio - 1 }}
				</NcButton>
			</NcNoteCard>

			<FilterTiles v-model="filtro" :counts="counts" />
			<p class="estadistica-resumen__hint" role="status">
				{{ shownText }}
			</p>

			<ul class="estadistica-resumen__metas">
				<li v-for="meta in shown" :key="meta.codigo">
					<MetaCard :meta="meta" :anio="resumen.anio" @open="(cifra, event) => open(meta, cifra, event)" />
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsMessageFrom, warnError } from '../api.js'
import { defineAsyncComponent } from 'vue'
import { monthName } from '../query.js'
import { FILTERS, countByFilter, statusOf } from '../status.js'
import FilterTiles from './FilterTiles.vue'
import MetaCard from './MetaCard.vue'

// The panel and the app sidebar are their own chunk, fetched on the first click (#43's way).
const ResumenFuente = defineAsyncComponent(() => import(/* webpackChunkName: "fuente" */ './ResumenFuente.vue'))

export default {
	name: 'EstadisticaResumen',

	components: { FilterTiles, MetaCard, NcButton, NcNoteCard, ResumenFuente },

	emits: ['metas'],

	data() {
		return {
			resumen: null,
			missing: false,
			failed: '',
			/** The pressed filter tile's id (FILTERS). */
			filtro: 'todas',
			/** The open panel's card, figure and opening count ({meta, cifra, opening}); opener is the figure that opened it. */
			panel: null,
			opener: null,
		}
	},

	computed: {
		counts() {
			return countByFilter(this.resumen.metas)
		},
		shown() {
			return this.filtro === 'todas' ? this.resumen.metas : this.resumen.metas.filter((meta) => statusOf(meta).filter === this.filtro)
		},
		shownText() {
			const count = this.shown.length
			const metas = `${count} ${count === 1 ? 'meta' : 'metas'}`
			return this.filtro === 'todas' ? `${metas}.` : `${metas}: ${FILTERS.find((filter) => filter.id === this.filtro).label.toLowerCase()}.`
		},
		/** Up to which month each REM series the metas count has data, said once: «REM Serie A: datos al octubre de 2026». */
		freshness() {
			const series = new Map()
			for (const meta of this.resumen.metas) {
				const numerador = meta.historico.at(-1)?.ruta?.numerador
				if (numerador && meta.datos_al && !series.has(numerador.fuente)) {
					series.set(numerador.fuente, `${numerador.nombre}: datos al ${monthName(meta.datos_al)}`)
				}
			}
			return [...series.values()].join(' · ')
		},
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
		open(meta, cifra, event) {
			this.panel = { meta, cifra, opening: (this.panel?.opening ?? 0) + 1 }
			this.opener = event.currentTarget
		},

		async close() {
			this.panel = null
			await this.$nextTick()
			this.opener?.focus()
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

.estadistica-resumen > p,
.estadistica-resumen > h2 {
	margin: 0;
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
</style>

<style>
/* A button that reads as its text: a card's title, which opens its
   histórico, and a figure, which opens its source panel, underlined as a
   link. */
.estadistica-resumen .estadistica-cifra,
.estadistica-resumen .estadistica-cifra:hover,
.estadistica-resumen .estadistica-meta__abrir,
.estadistica-resumen .estadistica-meta__abrir:hover {
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	background: none;
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.estadistica-resumen .estadistica-cifra {
	text-decoration: underline dotted;
	text-underline-offset: 0.2em;
}

.estadistica-resumen .estadistica-cifra:focus-visible,
.estadistica-resumen .estadistica-meta__abrir:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}
</style>
