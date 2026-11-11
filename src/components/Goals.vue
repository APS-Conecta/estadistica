<!--
	Metas Sanitarias (a12, a22): each meta of a year, month by month, this
	establishment's figures beside the country's % and the peers'. One row per
	meta with its last figure and status (#83), which opens to its month table
	(owner, Slice 9: a table per meta), read from GET indicadores, so the page
	shows what the API answers.

	The peers are «like ours»: the establishments that share the chosen traits
	with this one, tipo to start with (owner, Slice 9). Without an establishment
	configured there is no «ours», so no local figures and no peers.
-->
<template>
	<section class="estadistica-goals">
		<div class="estadistica-goals__controls">
			<NcSelect v-if="anios.length > 1"
				class="estadistica-goals__year"
				input-label="Año"
				:model-value="anio"
				:options="yearOptions"
				:reduce="(option) => option.id"
				:clearable="false"
				:disabled="loading"
				@update:model-value="chooseYear" />

			<fieldset v-if="establishment.configured" class="estadistica-goals__peers">
				<legend>Pares: los establecimientos que comparten con el suyo</legend>
				<NcCheckboxRadioSwitch v-for="trait in traits"
					:key="trait.id"
					:model-value="pares"
					:value="trait.id"
					:disabled="loading"
					@update:model-value="choosePeers">
					{{ trait.nombre }}
				</NcCheckboxRadioSwitch>
			</fieldset>
		</div>

		<NcNoteCard v-if="failed" type="error">
			{{ failed }}
		</NcNoteCard>

		<p v-else-if="loading && !metas" class="estadistica-goals__hint">
			Cargando…
		</p>

		<NcNoteCard v-else-if="missing" type="info">
			Todavía no hay Metas Sanitarias cargadas. Quien administra Estadística las carga como un
			archivo en «Cargar un archivo a mano».
		</NcNoteCard>

		<template v-else-if="metas">
			<h2>{{ metas.nombre }}</h2>
			<p class="estadistica-goals__hint">
				{{ metas.referencia }} {{ metas.nota }}
			</p>
			<p class="estadistica-goals__hint">
				{{ fuentesText }} Las metas que se cuentan en la Serie P se miden en sus cortes de junio y
				diciembre: cada mes muestra el último corte hasta ese mes.
			</p>

			<NcNoteCard v-if="metas.aviso" type="warning">
				{{ metas.aviso }}
			</NcNoteCard>

			<ul class="estadistica-goals__list">
				<li v-for="meta in metas.indicadores" :key="meta.codigo" class="estadistica-goals__meta">
					<!-- One disclosure per meta: the row says its last figure and status in words. -->
					<h3 class="estadistica-goals__heading">
						<button type="button"
							class="estadistica-goals__row"
							:aria-expanded="String(open === meta.codigo)"
							:aria-controls="`metas-${meta.codigo}-meses`"
							@click="open = open === meta.codigo ? null : meta.codigo">
							<span class="estadistica-goals__codigo">{{ meta.codigo }}</span>
							<span class="estadistica-goals__nombre">
								<span class="estadistica-goals__titulo">{{ meta.nombre }}</span>
								<span class="estadistica-goals__hint">{{ rowTargetText(meta) }}</span>
							</span>
							<GoalSparkline class="estadistica-goals__spark" :points="points(meta)" :target="targetOf(meta)" />
							<span class="estadistica-goals__ultima">{{ percent(lastPercentOf(meta)) }}</span>
							<span class="estadistica-goals__estado"><StatusBadge :status="indicadorStatus(meta)" /></span>
							<NcIconSvgWrapper class="estadistica-goals__chevron" :path="mdiChevronRight" />
						</button>
					</h3>

					<div v-show="open === meta.codigo" :id="`metas-${meta.codigo}-meses`" class="estadistica-goals__panel">
						<p>{{ meta.indicador }}.</p>
						<p class="estadistica-goals__hint">
							{{ targetsText(meta) }}
						</p>
						<p v-if="meta.nota" class="estadistica-goals__hint">
							{{ meta.nota }}
						</p>
						<p v-if="populationText(meta)" class="estadistica-goals__hint">
							{{ populationText(meta) }}
						</p>

						<p v-if="meta.manual">
							{{ meta.cumplimiento === null ? 'Todavía no se ha informado su cumplimiento.' : `Cumplimiento informado: ${percent(meta.cumplimiento)}.` }}
						</p>

						<p v-else-if="meta.meses.length === 0" class="estadistica-goals__hint">
							Este año todavía no empieza.
						</p>

						<div v-else class="estadistica-goals__scroll">
							<table class="estadistica-table">
								<caption>Meta {{ meta.codigo }} por mes, {{ metas.anio }}. «—» donde no hay cifra.</caption>
								<thead>
									<tr>
										<th scope="col">
											Mes
										</th>
										<template v-if="local">
											<th scope="col">
												Numerador
											</th>
											<th scope="col">
												Denominador
											</th>
											<th scope="col">
												Su establecimiento
											</th>
											<th scope="col">
												Frente a la meta comprometida
											</th>
											<th scope="col">
												Frente a la meta nacional
											</th>
										</template>
										<th scope="col">
											Nacional
										</th>
										<th v-if="metas.pares" scope="col">
											Pares
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="mes in meta.meses" :key="mes.mes">
										<th scope="row">
											{{ monthName(mes.mes) }}
										</th>
										<template v-if="local">
											<td>{{ figure(mes.numerador) }}</td>
											<td>{{ figure(mes.denominador) }}</td>
											<td>{{ percent(mes.porcentaje) }}</td>
											<td>{{ gap(mes.brecha_comprometida) }}</td>
											<td>{{ gap(mes.brecha_nacional) }}</td>
										</template>
										<td>{{ percent(mes.nacional) }}</td>
										<td v-if="metas.pares">
											{{ percent(mes.pares) }}
										</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</li>
			</ul>
		</template>
	</section>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import GoalSparkline from './GoalSparkline.vue'
import StatusBadge from './StatusBadge.vue'
import { ocsGet, ocsMessageFrom, warnError } from '../api.js'
import { formatValue } from '../charts.js'
import { mdiChevronRight } from '../icons.js'
import { TRAITS, dimensionName, monthName } from '../query.js'
import { indicadorStatus, lastPercentOf, targetNameOf, targetOf } from '../status.js'

export default {
	name: 'SanitaryGoals',

	components: { GoalSparkline, NcCheckboxRadioSwitch, NcIconSvgWrapper, NcNoteCard, NcSelect, StatusBadge },

	props: {
		/** The initial state's establishment: whether this install has one. */
		establishment: { type: Object, required: true },
		/** The year to open on, as the Resumen offers it; null opens GET indicadores' own. */
		year: { type: Number, default: null },
	},

	data() {
		return {
			metas: null,
			anios: [],
			anio: this.year,
			pares: ['tipo'],
			loading: false,
			missing: false,
			failed: '',
			/** The código of the one meta whose row is open. */
			open: null,
			mdiChevronRight,
			traits: TRAITS.map((trait) => ({ id: trait.id, nombre: dimensionName(trait.id) })),
		}
	},

	computed: {
		yearOptions() {
			return this.anios.map((year) => ({ id: year, label: String(year) }))
		},
		/** The local columns stand only where there is an establishment to count. */
		local() {
			return this.metas?.establecimiento !== null
		},
		fuentesText() {
			return this.metas.fuentes.map((fuente) => {
				if (!fuente.fecha_corte) {
					return `${fuente.nombre}: sin cifras cargadas.`
				}
				// The establishment's own last month in this serie, which ends its local Serie A months.
				const own = this.metas.fecha_corte_local?.[fuente.id]
				const local = own ? `; su establecimiento informó hasta ${monthName(own)}` : ''
				const until = fuente.id === 'piv'
					? `corte al ${fuente.fecha_corte.split('-').reverse().join('-')}`
					: `cifras hasta ${monthName(fuente.fecha_corte)}${local}`
				// «Población inscrita validada (FONASA)» already names its publisher.
				const nombre = fuente.nombre.includes(fuente.origen) ? fuente.nombre : `${fuente.nombre} (${fuente.origen})`
				return `${nombre}: ${until}.`
			}).join(' ')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		indicadorStatus,
		lastPercentOf,
		monthName,
		targetOf,

		chooseYear(anio) {
			this.anio = anio
			this.load()
		},

		choosePeers(pares) {
			this.pares = pares
			this.load()
		},

		/**
		 * Asks GET indicadores for the chosen year and peers. The controls
		 * stay disabled until it answers, so no answer overtakes another.
		 */
		async load() {
			this.loading = true
			this.failed = ''
			try {
				const params = { pares: this.establishment.configured ? this.pares.join(',') : '' }
				if (this.anio !== null) {
					params.anio = this.anio
				}
				this.metas = await ocsGet('indicadores', params)
				this.anios = this.metas.anios
				this.anio = this.metas.anio
				this.missing = false
			} catch (error) {
				if (error?.response?.status === 404) {
					this.metas = null
					this.missing = true
					return
				}
				warnError('could not read the metas', error)
				this.failed = await ocsMessageFrom(error, 'No se pudieron leer las metas. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.loading = false
			}
		},

		targetsText(meta) {
			const committed = meta.meta_comprometida === null
				? 'Sin meta comprometida.'
				: `Meta comprometida: ${this.percent(meta.meta_comprometida)}.`
			return `Meta nacional: ${this.percent(meta.meta_nacional)}. ${committed} Peso: ${this.percent(meta.peso)}.`
		},

		/** The row's target, the one its status is judged against (CONTEXT.md, cumple), and its weight. */
		rowTargetText(meta) {
			const name = targetNameOf(meta)
			return `${name[0].toUpperCase()}${name.slice(1)} ${this.percent(targetOf(meta))} · peso ${this.percent(meta.peso)}`
		},

		/** The sparkline's points: each month with a percentage, by its index in the year. */
		points(meta) {
			return meta.meses.filter((mes) => mes.porcentaje !== null)
				.map((mes) => ({ month: Number(mes.mes.slice(5)) - 1, value: mes.porcentaje }))
		},

		/** Where a meta counted over people takes its denominator from. */
		populationText(meta) {
			const poblacion = meta.poblacion
			if (!poblacion) {
				return ''
			}
			if (poblacion.comprometida) {
				return `Denominador: ${this.figure(meta.denominador_comprometido)} personas, comprometidas por el establecimiento.`
			}
			// Every figure is official (ADR-0007, amendment): no FONASA figure gives a range that cuts a band.
			if (poblacion.falta_oficial) {
				return `Sin porcentaje: falta el denominador oficial de ${this.metas.anio}. Las edades de esta meta no coinciden con los tramos de FONASA: quien administra Estadística ingresa el denominador comprometido.`
			}
			if (!poblacion.corte) {
				return 'No hay población inscrita de FONASA para este año: se carga en la administración de Estadística.'
			}
			const grown = poblacion.factor !== 1 ? `, más un ${formatValue((poblacion.factor - 1) * 100)} % porque el año no tiene su propio corte` : ''
			return `Denominador: población inscrita validada por FONASA al ${poblacion.corte.split('-').reverse().join('-')}${grown}.`
		},

		figure(value) {
			return value === null ? '—' : formatValue(value)
		},

		percent(value) {
			return value === null ? '—' : `${formatValue(value)} %`
		},

		/** A gap in points, signed: +5 above the meta, −15 below it. */
		gap(value) {
			if (value === null) {
				return '—'
			}
			const sign = value > 0 ? '+' : value < 0 ? '−' : ''
			return `${sign}${formatValue(Math.abs(value))} puntos`
		},
	},
}
</script>

<style scoped>
.estadistica-goals {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.estadistica-goals__controls {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 6);
	align-items: end;
}

.estadistica-goals__year {
	min-inline-size: 160px;
}

.estadistica-goals__peers {
	display: flex;
	flex-wrap: wrap;
	gap: 0 calc(var(--default-grid-baseline) * 3);
}

.estadistica-goals__peers legend {
	font-weight: bold;
}

.estadistica-goals__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-goals__list {
	container-type: inline-size;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.estadistica-goals__meta {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
	overflow: hidden;
}

.estadistica-goals__heading {
	margin: 0;
	font-size: inherit;
	font-weight: inherit;
}

.estadistica-goals__row,
.estadistica-goals__row:hover {
	display: grid;
	grid-template-areas: "codigo nombre spark ultima estado chevron";
	grid-template-columns: 56px minmax(0, 1fr) auto 72px 18.5rem 20px;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 3);
	inline-size: 100%;
	min-height: 0;
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border: none;
	border-radius: 0;
	background-color: var(--color-main-background);
	color: inherit;
	font: inherit;
	text-align: start;
	cursor: pointer;
}

.estadistica-goals__row:hover {
	background-color: var(--color-background-hover);
}

.estadistica-goals__row:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -4px;
}

.estadistica-goals__codigo {
	grid-area: codigo;
	justify-self: start;
	padding: 1px calc(var(--default-grid-baseline) * 2);
	border-radius: var(--border-radius-small);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: bold;
}

.estadistica-goals__nombre {
	display: flex;
	flex-direction: column;
	grid-area: nombre;
}

.estadistica-goals__titulo,
.estadistica-goals__ultima {
	font-weight: bold;
}

.estadistica-goals__spark {
	grid-area: spark;
}

.estadistica-goals__ultima {
	grid-area: ultima;
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.estadistica-goals__estado {
	grid-area: estado;
}

.estadistica-goals__chevron {
	grid-area: chevron;
	color: var(--color-text-maxcontrast);
}

.estadistica-goals__row[aria-expanded="true"] .estadistica-goals__chevron {
	transform: rotate(90deg);
}

.estadistica-goals__panel {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 4);
	border-top: 1px solid var(--color-border);
}

/* A table wider than a phone scrolls in its row, never the page. */
.estadistica-goals__scroll {
	overflow-x: auto;
}

/* In a narrow list the status goes under the name, and the decorative sparkline goes. */
@container (max-width: 920px) {
	.estadistica-goals__row,
	.estadistica-goals__row:hover {
		grid-template-areas: "codigo nombre ultima chevron" ". estado estado .";
		grid-template-columns: 56px minmax(0, 1fr) auto 20px;
	}

	.estadistica-goals__spark {
		display: none;
	}
}
</style>
