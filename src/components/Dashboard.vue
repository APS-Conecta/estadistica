<!--
	Tableros (a8): every tablero is everyone's to read (D6). Each card runs its
	own consulta and shows its own refusal (QuestionCard), so one broken card
	never blanks the others. Its creator, or whoever administers Estadística,
	arranges it from each card's actions menu: up and down, one to three
	columns wide, taken out; cards are added from the saved preguntas (run plan
	baseline: a CSS grid, no layout library). Every change is saved when it is
	made.
-->
<template>
	<div class="estadistica-dashboards">
		<NcNoteCard v-if="loadFailed" type="error">
			{{ loadFailed }}
		</NcNoteCard>

		<p v-else-if="tableros === null" class="estadistica-dashboards__hint">
			Cargando…
		</p>

		<template v-else>
			<section class="estadistica-dashboards__list">
				<p v-if="tableros.length === 0" class="estadistica-dashboards__hint">
					Todavía no hay tableros. Cree el primero y agréguele preguntas guardadas.
				</p>
				<div v-else class="estadistica-dashboards__bar">
					<NcSelect class="estadistica-dashboards__picker"
						:model-value="openId"
						input-label="Tablero"
						:options="tableroOptions"
						:reduce="(option) => option.id"
						:clearable="false"
						placeholder="Elija un tablero"
						@update:model-value="open" />
					<span v-if="tablero" class="estadistica-dashboards__hint">de {{ tablero.autor_nombre }}</span>
				</div>

				<form class="estadistica-dashboards__form" @submit.prevent="create">
					<label class="estadistica-field">
						<span>Título del nuevo tablero</span>
						<input v-model="newTitle" type="text" :maxlength="MAX_TITLE">
					</label>
					<NcButton type="submit" :disabled="newTitle.trim() === '' || saving">
						Crear tablero
					</NcButton>
					<p class="estadistica-dashboards__hint">
						Lo que guarde queda visible para todo el personal.
					</p>
				</form>
			</section>

			<NcNoteCard v-if="saveFailed" type="error">
				{{ saveFailed }}
			</NcNoteCard>

			<section v-if="tablero" class="estadistica-dashboards__board">
				<header class="estadistica-dashboards__head">
					<h2>{{ tablero.titulo }}</h2>
					<p class="estadistica-dashboards__hint">
						Cada tarjeta hace su propia consulta.
					</p>
					<form v-if="tablero.editable" class="estadistica-dashboards__form" @submit.prevent="rename">
						<label class="estadistica-field">
							<span>Título del tablero</span>
							<input v-model="title" type="text" :maxlength="MAX_TITLE">
						</label>
						<NcButton type="submit" :disabled="title.trim() === '' || title.trim() === tablero.titulo || saving">
							Cambiar el título
						</NcButton>
						<NcButton variant="error" :disabled="saving" @click="confirmingRemove = true">
							Eliminar el tablero
						</NcButton>
					</form>
					<NcDialog v-if="confirmingRemove"
						name="Eliminar el tablero"
						:message="`¿Eliminar el tablero «${tablero.titulo}»? Sus preguntas no se eliminan.`"
						:buttons="[{ label: 'Cancelar' }, { label: 'Eliminar el tablero', variant: 'error', callback: remove }]"
						@closing="confirmingRemove = false" />
				</header>

				<p v-if="cards.length === 0" class="estadistica-dashboards__hint">
					Este tablero todavía no tiene tarjetas.
				</p>
				<div class="estadistica-dashboards__grid">
					<div v-for="(card, index) in cards"
						:key="card.key"
						:class="['estadistica-dashboards__cell', `estadistica-dashboards__cell--span-${card.ancho}`]">
						<QuestionCard :pregunta="preguntaOf(card.pregunta)">
							<template v-if="tablero.editable" #actions>
								<NcActions :aria-label="`Acciones de la tarjeta «${cardName(card)}»`">
									<NcActionButton :disabled="index === 0 || saving" close-after-click @click="move(index, -1)">
										Subir
									</NcActionButton>
									<NcActionButton :disabled="index === cards.length - 1 || saving" close-after-click @click="move(index, 1)">
										Bajar
									</NcActionButton>
									<NcActionButton v-for="ancho in WIDTHS"
										:key="ancho"
										type="radio"
										:model-value="String(card.ancho)"
										:value="String(ancho)"
										:disabled="saving"
										@update:model-value="resize(index, ancho)">
										{{ ancho === 1 ? '1 columna' : `${ancho} columnas` }}
									</NcActionButton>
									<NcActionButton :disabled="saving" close-after-click @click="drop(index)">
										Quitar
									</NcActionButton>
								</NcActions>
							</template>
						</QuestionCard>
					</div>
				</div>

				<form v-if="tablero.editable" class="estadistica-dashboards__form estadistica-dashboards__add" @submit.prevent="add">
					<NcSelect v-model="adding"
						input-label="Pregunta guardada"
						:options="questionOptions"
						:reduce="(option) => option.id"
						placeholder="Elija una pregunta" />
					<NcButton type="submit" :disabled="adding === null || saving">
						Agregar una pregunta
					</NcButton>
				</form>
			</section>
		</template>
	</div>
</template>

<script>
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import QuestionCard from './QuestionCard.vue'
import { ocsDelete, ocsGet, ocsMessageFrom, ocsPost, ocsPut, warnError } from '../api.js'
import { MAX_TITLE } from '../query.js'

/** A card spans one to three of the tablero's columns (SavedContent::WIDTHS). */
const WIDTHS = [1, 2, 3]

/** A card's key outlives its place, so a card moved is not asked again. */
let nextKey = 0

export default {
	name: 'EstadisticaDashboard',

	components: { NcActionButton, NcActions, NcButton, NcDialog, NcNoteCard, NcSelect, QuestionCard },

	data() {
		return {
			WIDTHS,
			MAX_TITLE,
			tableros: null,
			preguntas: [],
			loadFailed: '',
			openId: null,
			cards: [],
			title: '',
			newTitle: '',
			adding: null,
			saving: false,
			saveFailed: '',
			confirmingRemove: false,
		}
	},

	computed: {
		tablero() {
			return this.tableros?.find((item) => item.id === this.openId) ?? null
		},
		tableroOptions() {
			return this.tableros.map((item) => ({ id: item.id, label: item.titulo }))
		},
		questionOptions() {
			return this.preguntas.map((pregunta) => ({ id: pregunta.id, label: pregunta.titulo }))
		},
	},

	async mounted() {
		try {
			const [tableros, preguntas] = await Promise.all([ocsGet('tableros'), ocsGet('preguntas')])
			this.preguntas = preguntas
			this.tableros = tableros
		} catch (error) {
			warnError('could not read the tableros', error)
			this.loadFailed = await ocsMessageFrom(error, 'No se pudieron leer los tableros. Recargue la página.')
		}
	},

	methods: {
		preguntaOf(id) {
			return this.preguntas.find((pregunta) => pregunta.id === id) ?? null
		},

		cardName(card) {
			return this.preguntaOf(card.pregunta)?.titulo ?? 'Pregunta eliminada'
		},

		open(id) {
			this.openId = id
			this.cards = this.tablero.tarjetas.map((card) => ({ ...card, key: nextKey++ }))
			this.title = this.tablero.titulo
			this.saveFailed = ''
		},

		/** The tablero as the server now has it, in the list; the cards on screen stay as they are. */
		replace(saved) {
			this.tableros = this.tableros.map((item) => (item.id === saved.id ? saved : item))
		},

		/** One write, and the cards put back as they were when it is refused. */
		async save(body, failure) {
			this.saving = true
			this.saveFailed = ''
			try {
				this.replace(await ocsPut(`tableros/${this.openId}`, body))
				return true
			} catch (error) {
				warnError('could not save the tablero', error)
				this.saveFailed = await ocsMessageFrom(error, failure)
				return false
			} finally {
				this.saving = false
			}
		},

		async saveCards(next) {
			const before = this.cards
			this.cards = next
			const saved = await this.save(
				{ tarjetas: next.map(({ pregunta, ancho }) => ({ pregunta, ancho })) },
				'No se pudo guardar el tablero. Inténtelo de nuevo.',
			)
			if (!saved) {
				this.cards = before
			}
			return saved
		},

		move(index, step) {
			const next = [...this.cards]
			const [card] = next.splice(index, 1)
			next.splice(index + step, 0, card)
			return this.saveCards(next)
		},

		resize(index, ancho) {
			return this.saveCards(this.cards.map((card, i) => (i === index ? { ...card, ancho } : card)))
		},

		drop(index) {
			return this.saveCards(this.cards.filter((_, i) => i !== index))
		},

		async add() {
			// A refused add keeps the choice, for another try.
			if (await this.saveCards([...this.cards, { pregunta: this.adding, ancho: 1, key: nextKey++ }])) {
				this.adding = null
			}
		},

		rename() {
			return this.save({ titulo: this.title }, 'No se pudo cambiar el título. Inténtelo de nuevo.')
		},

		async create() {
			this.saving = true
			this.saveFailed = ''
			try {
				const created = await ocsPost('tableros', { titulo: this.newTitle, tarjetas: [] })
				this.tableros = [created, ...this.tableros]
				this.newTitle = ''
				this.open(created.id)
			} catch (error) {
				warnError('could not create the tablero', error)
				this.saveFailed = await ocsMessageFrom(error, 'No se pudo crear el tablero. Inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},

		/** Confirmed in the dialog, which closes at once: a refusal is said on the page. */
		async remove() {
			this.confirmingRemove = false
			this.saving = true
			this.saveFailed = ''
			try {
				await ocsDelete(`tableros/${this.openId}`)
				this.tableros = this.tableros.filter((item) => item.id !== this.openId)
				this.openId = null
				this.cards = []
			} catch (error) {
				warnError('could not delete the tablero', error)
				this.saveFailed = await ocsMessageFrom(error, 'No se pudo eliminar el tablero. Inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.estadistica-dashboards {
	display: flex;
	flex-direction: column;
	gap: 24px;
}

.estadistica-dashboards h2 {
	font-size: 1.1em;
	margin: 0;
}

.estadistica-dashboards__list {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.estadistica-dashboards__bar,
.estadistica-dashboards__form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.estadistica-dashboards__bar {
	align-items: end;
}

.estadistica-dashboards__picker {
	min-width: 260px;
}

.estadistica-dashboards__head {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 16px;
}

/* Three columns, a card spanning one to three; one column on a narrow screen. */
.estadistica-dashboards__grid {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 16px;
	margin-bottom: 16px;
}

.estadistica-dashboards__cell--span-2 {
	grid-column: span 2;
}

.estadistica-dashboards__cell--span-3 {
	grid-column: span 3;
}

@media (max-width: 1024px) {
	.estadistica-dashboards__grid {
		grid-template-columns: minmax(0, 1fr);
	}

	.estadistica-dashboards__cell--span-2,
	.estadistica-dashboards__cell--span-3 {
		grid-column: auto;
	}
}

.estadistica-dashboards__hint {
	color: var(--color-text-maxcontrast);
}
</style>
