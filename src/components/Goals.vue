<!--
	Metas Sanitarias (a12, a22): each meta of a year, month by month, this
	establishment's figures beside the country's % and the peers'. One card per
	meta with its table (owner, Slice 9: a table per meta, no chart), read from
	GET indicadores, so the page shows what the API answers.

	The peers are «like ours»: the establishments that share the chosen traits
	with this one, tipo to start with (owner, Slice 9). Without an establishment
	configured there is no «ours», so no local figures and no peers.
-->
<template>
	<section class="estadistica-goals">
		<div class="estadistica-goals__controls">
			<label v-if="anios.length > 1" class="estadistica-field">
				Año
				<select v-model="anio" :disabled="loading" @change="load">
					<option v-for="year in anios" :key="year" :value="year">
						{{ year }}
					</option>
				</select>
			</label>

			<fieldset v-if="establishment.configured" class="estadistica-goals__peers">
				<legend>Pares: los establecimientos que comparten con el suyo</legend>
				<label v-for="trait in traits" :key="trait.id">
					<input v-model="pares"
						type="checkbox"
						:value="trait.id"
						:disabled="loading"
						@change="load">
					{{ trait.nombre }}
				</label>
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

			<article v-for="meta in metas.indicadores" :key="meta.codigo" class="estadistica-goals__meta">
				<h3>Meta {{ meta.codigo }}: {{ meta.nombre }}</h3>
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
					<table class="estadistica-goals__table">
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
			</article>
		</template>
	</section>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { ocsGet, ocsMessageFrom, warnError } from '../api.js'
import { formatValue } from '../charts.js'
import { TRAITS, dimensionName, monthName } from '../query.js'

export default {
	name: 'SanitaryGoals',

	components: { NcNoteCard },

	props: {
		/** The initial state's establishment: whether this install has one. */
		establishment: { type: Object, required: true },
	},

	data() {
		return {
			metas: null,
			anios: [],
			anio: null,
			pares: ['tipo'],
			loading: false,
			missing: false,
			failed: '',
			traits: TRAITS.map((trait) => ({ id: trait.id, nombre: dimensionName(trait.id) })),
		}
	},

	computed: {
		/** The local columns stand only where there is an establishment to count. */
		local() {
			return this.metas?.establecimiento !== null
		},
		fuentesText() {
			return this.metas.fuentes.map((fuente) => {
				if (!fuente.fecha_corte) {
					return `${fuente.nombre}: sin cifras cargadas.`
				}
				const until = fuente.id === 'piv'
					? `corte al ${fuente.fecha_corte.split('-').reverse().join('-')}`
					: `cifras hasta ${monthName(fuente.fecha_corte)}`
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
		monthName,

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

		/** Where a meta counted over people takes its denominator from. */
		populationText(meta) {
			const poblacion = meta.poblacion
			if (!poblacion) {
				return ''
			}
			if (poblacion.comprometida) {
				return `Denominador: ${this.figure(meta.denominador_comprometido)} personas, comprometidas por el establecimiento.`
			}
			if (!poblacion.corte) {
				return 'No hay población inscrita de FONASA para este año: se carga en la administración de Estadística.'
			}
			const grown = poblacion.factor !== 1 ? `, más un ${formatValue((poblacion.factor - 1) * 100)} % porque el año no tiene su propio corte` : ''
			const estimated = poblacion.prorrateado ? ', estimada por prorrateo de los tramos de edad' : ''
			return `Denominador: población inscrita validada por FONASA al ${poblacion.corte.split('-').reverse().join('-')}${grown}${estimated}.`
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
	gap: 16px;
	align-items: flex-start;
}

.estadistica-goals__peers {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
}

.estadistica-goals__peers legend {
	font-weight: bold;
}

.estadistica-goals__peers label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.estadistica-goals__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-goals__meta {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

/* A table wider than a phone scrolls in its card, never the page. */
.estadistica-goals__scroll {
	overflow-x: auto;
}

.estadistica-goals__table {
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.estadistica-goals__table th,
.estadistica-goals__table td {
	padding: 4px 12px 4px 0;
	text-align: end;
	white-space: nowrap;
}

.estadistica-goals__table th:first-child {
	text-align: start;
}
</style>
