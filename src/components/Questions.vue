<!--
	Preguntas (a8): every saved pregunta, everyone's to read and to open (D6).
	Its creator, or whoever administers Estadística, may rename it, change how
	it is drawn, or delete it.

	ponytail: what a pregunta asks is changed by saving a new one from the
	builder (owner, Slice 7). Opening a pregunta back into the four steps needs
	a spec → builder-state inverse; PUT preguntas/{id} already takes a new
	consulta for when that is built.
-->
<template>
	<div class="estadistica-questions">
		<NcNoteCard v-if="loadFailed" type="error">
			{{ loadFailed }}
		</NcNoteCard>

		<p v-else-if="preguntas === null" class="estadistica-questions__hint">
			Cargando…
		</p>

		<NcEmptyContent v-else-if="preguntas.length === 0"
			name="Todavía no hay preguntas guardadas"
			description="Haga una consulta y guárdela con «Guardar como pregunta»: quedará aquí para todo el personal." />

		<template v-else>
			<NcNoteCard v-if="saveFailed" type="error">
				{{ saveFailed }}
			</NcNoteCard>

			<ul class="estadistica-questions__list">
				<li v-for="pregunta in preguntas" :key="pregunta.id" class="estadistica-questions__item">
					<div class="estadistica-questions__row">
						<strong>{{ pregunta.titulo }}</strong>
						<span class="estadistica-questions__hint">de {{ pregunta.autor_nombre }}</span>
						<NcButton variant="tertiary"
							:aria-label="`${shown.includes(pregunta.id) ? 'Ocultar' : 'Ver'} la pregunta «${pregunta.titulo}»`"
							@click="toggle(pregunta.id)">
							{{ shown.includes(pregunta.id) ? 'Ocultar' : 'Ver' }}
						</NcButton>
						<template v-if="pregunta.editable">
							<NcButton variant="tertiary"
								:aria-label="`Editar la pregunta «${pregunta.titulo}»`"
								:disabled="saving"
								@click="edit(pregunta)">
								Editar
							</NcButton>
							<NcButton variant="tertiary"
								:aria-label="`Eliminar la pregunta «${pregunta.titulo}»`"
								:disabled="saving"
								@click="remove(pregunta)">
								Eliminar
							</NcButton>
						</template>
					</div>

					<form v-if="editing === pregunta.id" class="estadistica-questions__form" @submit.prevent="save">
						<label class="estadistica-field">
							<span>Título</span>
							<input v-model="draft.titulo" type="text" :maxlength="MAX_TITLE">
						</label>
						<fieldset>
							<legend>Mostrar como</legend>
							<NcCheckboxRadioSwitch v-for="chart in CHART_TYPES"
								:key="chart.id"
								:model-value="draft.tipo"
								:value="chart.id"
								:name="`estadistica-tipo-${pregunta.id}`"
								type="radio"
								@update:model-value="draft.tipo = $event">
								{{ chart.nombre }}
							</NcCheckboxRadioSwitch>
						</fieldset>
						<NcButton type="submit" variant="primary" :disabled="draft.titulo.trim() === '' || saving">
							Guardar
						</NcButton>
						<NcButton @click="editing = null">
							Cancelar
						</NcButton>
					</form>

					<QuestionCard v-if="shown.includes(pregunta.id)" :pregunta="pregunta" />
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import QuestionCard from './QuestionCard.vue'
import { ocsDelete, ocsGet, ocsMessageFrom, ocsPut, warnError } from '../api.js'
import { CHART_TYPES, MAX_TITLE } from '../query.js'

export default {
	name: 'SavedQuestions',

	components: { NcButton, NcCheckboxRadioSwitch, NcEmptyContent, NcNoteCard, QuestionCard },

	data() {
		return {
			CHART_TYPES,
			MAX_TITLE,
			preguntas: null,
			loadFailed: '',
			shown: [],
			editing: null,
			draft: { titulo: '', tipo: 'barra' },
			saving: false,
			saveFailed: '',
		}
	},

	async mounted() {
		try {
			this.preguntas = await ocsGet('preguntas')
		} catch (error) {
			warnError('could not read the preguntas', error)
			this.loadFailed = await ocsMessageFrom(error, 'No se pudieron leer las preguntas. Recargue la página.')
		}
	},

	methods: {
		toggle(id) {
			this.shown = this.shown.includes(id) ? this.shown.filter((shown) => shown !== id) : [...this.shown, id]
		},

		edit(pregunta) {
			this.editing = pregunta.id
			this.draft = { titulo: pregunta.titulo, tipo: pregunta.tipo }
		},

		async save() {
			this.saving = true
			this.saveFailed = ''
			try {
				const saved = await ocsPut(`preguntas/${this.editing}`, { titulo: this.draft.titulo, tipo: this.draft.tipo })
				this.preguntas = this.preguntas.map((pregunta) => (pregunta.id === saved.id ? saved : pregunta))
				this.editing = null
			} catch (error) {
				warnError('could not save the pregunta', error)
				this.saveFailed = await ocsMessageFrom(error, 'No se pudo guardar la pregunta. Inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},

		/** ponytail: the browser's own confirm; NcDialog when the page needs more than one yes/no. */
		async remove(pregunta) {
			if (!window.confirm(`¿Eliminar la pregunta «${pregunta.titulo}»? Los tableros que la muestran dirán que fue eliminada.`)) {
				return
			}
			this.saving = true
			this.saveFailed = ''
			try {
				await ocsDelete(`preguntas/${pregunta.id}`)
				this.preguntas = this.preguntas.filter((item) => item.id !== pregunta.id)
			} catch (error) {
				warnError('could not delete the pregunta', error)
				this.saveFailed = await ocsMessageFrom(error, 'No se pudo eliminar la pregunta. Inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.estadistica-questions__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.estadistica-questions__item,
.estadistica-questions__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.estadistica-questions__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.estadistica-questions__form legend {
	font-weight: bold;
}

.estadistica-questions__hint {
	color: var(--color-text-maxcontrast);
}
</style>
