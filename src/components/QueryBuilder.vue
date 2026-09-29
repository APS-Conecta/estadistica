<!--
	The builder: Datos → Filtrar → Resumir → Visualizar, one consulta, its
	answer (a7, a22). It posts the spec query.js makes to the OCS route every
	other caller uses, so what the page shows is what the API answers (a15).

	No control names an establishment: the local series is this install's, from
	app config (gestion ADR-0013), and «pares» are chosen by register traits.
	A consulta runs on «Consultar», never on every change: a national series
	costs about two seconds, and the route spends the read limit.

	An answer can be saved as a pregunta, under a title, for all the staff
	(a8, a22): what is saved is the spec that was asked and the chart it is
	shown as, not the steps as they now stand.
-->
<template>
	<div class="estadistica-builder">
		<NcNoteCard v-if="loadFailed" type="error">
			{{ loadFailed }}
		</NcNoteCard>

		<p v-else-if="state === null" class="estadistica-builder__hint">
			Cargando…
		</p>

		<NcEmptyContent v-else-if="state.datos.fuente === null"
			name="Todavía no hay cifras REM"
			description="Aparecerán aquí cuando se carguen los datos públicos del DEIS." />

		<template v-else>
			<form class="estadistica-builder__steps" @submit.prevent="ask">
				<section class="estadistica-builder__step">
					<h2>1. Datos</h2>
					<SourcesPanel :fuentes="fuentes"
						:model-value="state.datos.fuente"
						@update:model-value="chooseFuente" />
				</section>

				<section class="estadistica-builder__step">
					<h2>2. Filtrar</h2>
					<div class="estadistica-builder__row">
						<NcSelect :model-value="state.filtrar.desde"
							input-label="Desde"
							:options="months"
							:reduce="id"
							:clearable="false"
							@update:model-value="choosePeriod('desde', $event)" />
						<NcSelect :model-value="state.filtrar.hasta"
							input-label="Hasta"
							:options="months"
							:reduce="id"
							:clearable="false"
							@update:model-value="choosePeriod('hasta', $event)" />
					</div>

					<NcNoteCard v-if="catalogFailed" type="error">
						{{ catalogFailed }}
					</NcNoteCard>
					<p v-else-if="catalogo === null" class="estadistica-builder__hint">
						Cargando las hojas del REM…
					</p>
					<p v-else-if="catalogo.hojas.length === 0" class="estadistica-builder__hint">
						No hay diccionario de {{ catalogo.anio }} ni de un año posterior: sus hojas no se pueden elegir.
					</p>
					<template v-else>
						<p v-if="catalogo.etiquetas_de !== catalogo.anio" class="estadistica-builder__hint">
							{{ catalogo.anio }} no trae un diccionario que se pueda leer: las hojas y columnas son las del
							diccionario de {{ catalogo.etiquetas_de }}.
						</p>
						<NcSelect :model-value="state.filtrar.hoja"
							input-label="Hoja del REM"
							:options="hojas"
							:reduce="id"
							:clearable="false"
							@update:model-value="chooseHoja" />
						<NcSelect v-if="state.filtrar.hoja"
							:model-value="state.filtrar.seccion"
							input-label="Sección"
							:options="secciones"
							:reduce="id"
							:clearable="false"
							@update:model-value="chooseSeccion" />
						<template v-if="seccion">
							<NcSelect v-model="state.filtrar.codigos"
								input-label="Prestaciones (vacío: todas las de la sección)"
								:options="prestaciones"
								:reduce="id"
								multiple />
							<NcSelect v-model="state.filtrar.columnas"
								input-label="Columnas que se suman"
								:options="columnas"
								:reduce="id"
								multiple />
						</template>
					</template>
				</section>

				<section class="estadistica-builder__step">
					<h2>3. Resumir</h2>
					<NcSelect v-model="state.resumir.agrupar"
						input-label="Agrupar por (hasta 2)"
						:options="dimensionOptions"
						:reduce="id"
						:selectable="() => state.resumir.agrupar.length < MAX_DIMENSIONS"
						multiple />

					<fieldset class="estadistica-builder__group">
						<legend>Métrica</legend>
						<NcCheckboxRadioSwitch :model-value="metrica"
							value="suma"
							name="estadistica-metrica"
							type="radio"
							:disabled="comparing"
							@update:model-value="state.resumir.metrica = $event">
							Suma
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch :model-value="metrica"
							value="promedio_establecimiento"
							name="estadistica-metrica"
							type="radio"
							@update:model-value="state.resumir.metrica = $event">
							Promedio por establecimiento
						</NcCheckboxRadioSwitch>
						<p v-if="comparing" class="estadistica-builder__hint">
							Al comparar se usa el promedio por establecimiento: la suma del país no se puede poner al
							lado de la de un establecimiento.
						</p>
					</fieldset>

					<fieldset class="estadistica-builder__group">
						<legend>Comparar con</legend>
						<NcCheckboxRadioSwitch :model-value="state.resumir.nacional"
							@update:model-value="state.resumir.nacional = $event">
							Todo el país
						</NcCheckboxRadioSwitch>
						<p class="estadistica-builder__hint">
							Pares: los establecimientos que comparten lo que elija aquí.
						</p>
						<div class="estadistica-builder__row">
							<NcSelect v-for="trait in traits"
								:key="trait.id"
								v-model="state.resumir.pares[trait.id]"
								:input-label="trait.nombre"
								:options="trait.options"
								:reduce="id"
								placeholder="Sin usar" />
						</div>
					</fieldset>
				</section>

				<section class="estadistica-builder__step">
					<h2>4. Visualizar</h2>
					<fieldset class="estadistica-builder__group">
						<legend>Mostrar como</legend>
						<NcCheckboxRadioSwitch v-for="chart in CHART_TYPES"
							:key="chart.id"
							:model-value="state.visualizar.tipo"
							:value="chart.id"
							name="estadistica-tipo"
							type="radio"
							@update:model-value="state.visualizar.tipo = $event">
							{{ chart.nombre }}
						</NcCheckboxRadioSwitch>
					</fieldset>
				</section>

				<div class="estadistica-builder__ask">
					<NcButton type="submit" variant="primary" :disabled="reason !== '' || asking">
						{{ asking ? 'Consultando…' : 'Consultar' }}
					</NcButton>
					<span v-if="reason" class="estadistica-builder__hint">{{ reason }}</span>
				</div>
			</form>

			<NcNoteCard v-if="askFailed" type="error">
				{{ askFailed }}
			</NcNoteCard>

			<template v-else-if="answer">
				<Visualization :answer="answer"
					:spec="asked"
					:tipo="state.visualizar.tipo" />

				<form class="estadistica-builder__save" @submit.prevent="save">
					<label class="estadistica-field">
						<span>Título de la pregunta</span>
						<input v-model="title" type="text" :maxlength="MAX_TITLE">
					</label>
					<NcButton type="submit" :disabled="title.trim() === '' || saving">
						Guardar como pregunta
					</NcButton>
					<p class="estadistica-builder__hint">
						Lo que guarde queda visible para todo el personal.
					</p>
				</form>
				<NcNoteCard v-if="saved" type="success">
					«{{ saved }}» quedó guardada en Preguntas.
				</NcNoteCard>
				<NcNoteCard v-if="saveFailed" type="error">
					{{ saveFailed }}
				</NcNoteCard>
			</template>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import SourcesPanel from './SourcesPanel.vue'
import Visualization from './Visualization.vue'
import { formatValue } from '../charts.js'
import { ocsMessageFrom, ocsPost, warnError } from '../api.js'
import {
	CHART_TYPES, consult, DIMENSIONS, dimensionName, freshFilters, hojaOf, initialState, loadSource, loadSources,
	MAX_DIMENSIONS, MAX_TITLE, metricFor, missing, monthsOf, seccionOf, seriesCount, specFrom, TRAITS,
} from '../query.js'

export default {
	name: 'QueryBuilder',

	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcNoteCard, NcSelect, SourcesPanel, Visualization },

	props: {
		/** This install's establishment (initial state; Establishment::jsonSerialize). */
		establishment: { type: Object, required: true },
	},

	data() {
		return {
			CHART_TYPES,
			MAX_DIMENSIONS,
			fuentes: [],
			loadFailed: '',
			state: null,
			catalogo: null,
			catalogFailed: '',
			/** Which loadCatalog() is the latest: an earlier one answering late is dropped. */
			catalogAsked: 0,
			register: {},
			asking: false,
			asked: null,
			answer: null,
			askFailed: '',
			MAX_TITLE,
			title: '',
			saving: false,
			saved: '',
			saveFailed: '',
		}
	},

	computed: {
		configured() {
			return this.establishment.configured === true
		},
		fuente() {
			return this.fuentes.find((fuente) => fuente.id === this.state?.datos.fuente) ?? null
		},
		months() {
			return monthsOf(this.fuente)
		},
		hojas() {
			return (this.catalogo?.hojas ?? []).map((h) => ({ id: h.hoja, label: h.titulo ? `${h.hoja} — ${h.titulo}` : h.hoja }))
		},
		secciones() {
			return (hojaOf(this.catalogo, this.state.filtrar.hoja)?.secciones ?? [])
				.map((s) => ({ id: s.seccion, label: s.seccion || 'Sin título de sección' }))
		},
		seccion() {
			return this.state.filtrar.seccion === null ? null : seccionOf(this.catalogo, this.state.filtrar.hoja, this.state.filtrar.seccion)
		},
		prestaciones() {
			return (this.seccion?.prestaciones ?? []).map((p) => ({ id: p.codigo, label: `${p.etiqueta} (${p.codigo})` }))
		},
		columnas() {
			return (this.seccion?.columnas ?? []).map((c) => ({ id: c.col, label: `${c.etiqueta} (${c.col})` }))
		},
		dimensionOptions() {
			return DIMENSIONS.map((d) => ({ id: d.id, label: d.nombre }))
		},
		traits() {
			return TRAITS.map((trait) => ({
				id: trait.id,
				nombre: dimensionName(trait.id),
				options: [
					...(this.configured ? [{ id: true, label: 'Igual que su establecimiento' }] : []),
					...(this.register[trait.valores] ?? []).map((v) => ({ id: v.valor, label: `${v.valor} (${formatValue(v.establecimientos)})` })),
				],
			})).filter((trait) => trait.options.length > 0)
		},
		comparing() {
			return seriesCount(this.state.resumir, this.configured) >= 2
		},
		metrica() {
			return metricFor(this.state.resumir, this.configured)
		},
		reason() {
			return missing(this.state, this.configured)
		},
	},

	async mounted() {
		try {
			this.fuentes = await loadSources()
			this.state = initialState(this.fuentes)
		} catch (error) {
			warnError('could not read the fuentes', error)
			this.loadFailed = await ocsMessageFrom(error, 'No se pudieron leer las fuentes. Recargue la página.')
			return
		}
		await Promise.all([this.loadCatalog(), this.loadRegister()])
	},

	methods: {
		id: (option) => option.id,

		/** The catálogo of the periodo's last year: the year the answer is labelled from (QueryExecutor). */
		async loadCatalog() {
			const hasta = this.state.filtrar.hasta
			if (this.state.datos.fuente === null || hasta === null) {
				return
			}
			const asked = ++this.catalogAsked
			this.catalogFailed = ''
			try {
				const catalogo = (await loadSource(this.state.datos.fuente, Number(hasta.slice(0, 4)))).catalogo
				if (asked !== this.catalogAsked) {
					return
				}
				this.catalogo = catalogo
				this.keepWhatStillExists()
			} catch (error) {
				if (asked !== this.catalogAsked) {
					return
				}
				warnError('could not read the catálogo', error)
				this.catalogFailed = await ocsMessageFrom(error, 'No se pudieron leer las hojas del REM. Inténtelo de nuevo.')
			}
		},

		/** The peer values, when the register is loaded. Without it, only «igual que su establecimiento». */
		async loadRegister() {
			if (!this.fuentes.some((fuente) => fuente.id === 'establecimientos' && fuente.filas > 0)) {
				return
			}
			try {
				this.register = (await loadSource('establecimientos')).catalogo
			} catch (error) {
				warnError('could not read the establishments', error)
			}
		},

		chooseFuente(fuente) {
			this.state.datos.fuente = fuente
			this.state.filtrar = freshFilters(this.fuente)
			this.catalogo = null
			this.loadCatalog()
		},

		choosePeriod(end, month) {
			const year = this.state.filtrar.hasta?.slice(0, 4)
			this.state.filtrar[end] = month
			if (end === 'hasta' && month.slice(0, 4) !== year) {
				this.loadCatalog()
			}
		},

		chooseHoja(hoja) {
			const secciones = hojaOf(this.catalogo, hoja)?.secciones ?? []
			this.state.filtrar.hoja = hoja
			this.state.filtrar.seccion = secciones.length === 1 ? secciones[0].seccion : null
			this.state.filtrar.codigos = []
			this.state.filtrar.columnas = []
		},

		chooseSeccion(seccion) {
			this.state.filtrar.seccion = seccion
			this.state.filtrar.codigos = []
			this.state.filtrar.columnas = []
		},

		/**
		 * Another year's dictionary may not have the chosen hoja, sección,
		 * prestaciones or columns: what it lacks is let go, and the rest kept.
		 */
		keepWhatStillExists() {
			const filtrar = this.state.filtrar
			if (filtrar.hoja !== null && hojaOf(this.catalogo, filtrar.hoja) === null) {
				filtrar.hoja = null
				filtrar.seccion = null
			}
			const seccion = filtrar.seccion === null ? null : seccionOf(this.catalogo, filtrar.hoja, filtrar.seccion)
			if (seccion === null) {
				filtrar.seccion = null
			}
			filtrar.codigos = filtrar.codigos.filter((codigo) => seccion?.prestaciones.some((p) => p.codigo === codigo))
			filtrar.columnas = filtrar.columnas.filter((col) => seccion?.columnas.some((c) => c.col === col))
		},

		async ask() {
			if (this.reason !== '') {
				return
			}
			const spec = specFrom(this.state, { configured: this.configured, catalogo: this.catalogo })
			this.asking = true
			this.askFailed = ''
			this.saved = ''
			this.saveFailed = ''
			try {
				this.answer = await consult(spec)
				this.asked = spec
			} catch (error) {
				warnError('could not answer the consulta', error)
				this.askFailed = await ocsMessageFrom(error, 'No se pudo hacer la consulta. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.asking = false
			}
		},

		/** The answer on screen as a pregunta: the spec that was asked, drawn as it is now shown. */
		async save() {
			this.saving = true
			this.saved = ''
			this.saveFailed = ''
			try {
				const pregunta = await ocsPost('preguntas', { titulo: this.title, consulta: this.asked, tipo: this.state.visualizar.tipo })
				this.saved = pregunta.titulo
				this.title = ''
			} catch (error) {
				warnError('could not save the pregunta', error)
				this.saveFailed = await ocsMessageFrom(error, 'No se pudo guardar la pregunta. Inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.estadistica-builder {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.estadistica-builder__steps {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 24px;
}

.estadistica-builder__step {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.estadistica-builder__step h2 {
	font-size: 1.1em;
	margin: 0;
}

.estadistica-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.estadistica-builder__group legend {
	font-weight: bold;
}

.estadistica-builder__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-builder__save {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.estadistica-builder__ask {
	grid-column: 1 / -1;
	display: flex;
	align-items: center;
	gap: 12px;
}
</style>
