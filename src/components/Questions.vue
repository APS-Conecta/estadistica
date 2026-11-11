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
						<div class="estadistica-questions__title">
							<strong>{{ pregunta.titulo }}</strong>
							<span class="estadistica-questions__hint">de {{ pregunta.autor_nombre }} · {{ dateOf(pregunta.actualizada) }}</span>
						</div>
						<NcButton variant="tertiary"
							:aria-label="`${shown.includes(pregunta.id) ? 'Ocultar' : 'Ver'} la pregunta «${pregunta.titulo}»`"
							@click="toggle(pregunta.id)">
							{{ shown.includes(pregunta.id) ? 'Ocultar' : 'Ver' }}
						</NcButton>
						<NcActions v-if="pregunta.editable" :aria-label="`Acciones de «${pregunta.titulo}»`" :disabled="saving">
							<NcActionButton close-after-click @click="edit(pregunta)">
								<template #icon>
									<NcIconSvgWrapper :path="mdiPencilOutline" />
								</template>
								Editar
							</NcActionButton>
							<NcActionButton close-after-click @click="deleting = pregunta">
								<template #icon>
									<NcIconSvgWrapper :path="mdiTrashCanOutline" />
								</template>
								Eliminar
							</NcActionButton>
						</NcActions>
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

			<NcDialog v-if="deleting"
				open
				name="Eliminar la pregunta"
				:message="`¿Eliminar la pregunta «${deleting.titulo}»? Los tableros que la muestran dirán que fue eliminada.`"
				:buttons="deleteButtons"
				@update:open="deleting = null" />
		</template>
	</div>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import QuestionCard from './QuestionCard.vue'
import { ocsDelete, ocsGet, ocsMessageFrom, ocsPut, warnError } from '../api.js'
import { mdiPencilOutline, mdiTrashCanOutline } from '../icons.js'
import { CHART_TYPES, MAX_TITLE } from '../query.js'

export default {
	name: 'SavedQuestions',

	components: { NcActionButton, NcActions, NcButton, NcCheckboxRadioSwitch, NcDialog, NcEmptyContent, NcIconSvgWrapper, NcNoteCard, QuestionCard },

	data() {
		return {
			CHART_TYPES,
			MAX_TITLE,
			mdiPencilOutline,
			mdiTrashCanOutline,
			preguntas: null,
			loadFailed: '',
			shown: [],
			editing: null,
			draft: { titulo: '', tipo: 'barra' },
			saving: false,
			saveFailed: '',
			/** The pregunta the delete dialog asks about, or null when it is closed. */
			deleting: null,
		}
	},

	computed: {
		deleteButtons() {
			return [
				{ label: 'Cancelar' },
				{ label: 'Eliminar', variant: 'error', callback: () => this.remove(this.deleting) },
			]
		},
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
		/** When it was last saved, as es-CL writes a date: «10 de noviembre de 2026». */
		dateOf(seconds) {
			return new Date(seconds * 1000).toLocaleDateString('es-CL', { day: 'numeric', month: 'long', year: 'numeric' })
		},

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

		async remove(pregunta) {
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
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.estadistica-questions__item,
.estadistica-questions__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.estadistica-questions__item {
	padding: 12px 16px;
}

.estadistica-questions__item + .estadistica-questions__item {
	border-top: 1px solid var(--color-border);
}

.estadistica-questions__row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.estadistica-questions__title {
	display: flex;
	flex: 1;
	flex-direction: column;
	min-width: 0;
}

.estadistica-questions__form legend {
	font-weight: bold;
}

.estadistica-questions__hint {
	color: var(--color-text-maxcontrast);
}
</style>
