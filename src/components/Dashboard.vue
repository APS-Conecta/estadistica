<!--
	Tableros (a8): every tablero is everyone's to read (D6). Each card runs its
	own consulta and shows its own refusal (QuestionCard), so one broken card
	never blanks the others. Its creator, or whoever administers Estadística,
	arranges it: cards up and down, one to three columns wide, added from the
	saved preguntas and taken out (run plan baseline: a CSS grid and up/down
	buttons, no layout library). Every change is saved when it is made.
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
				<h2>Tableros</h2>
				<p v-if="tableros.length === 0" class="estadistica-dashboards__hint">
					Todavía no hay tableros. Cree el primero y agréguele preguntas guardadas.
				</p>
				<ul v-else>
					<li v-for="item in tableros" :key="item.id">
						<NcButton :variant="item.id === openId ? 'primary' : 'tertiary'" @click="open(item.id)">
							{{ item.titulo }}
						</NcButton>
						<span class="estadistica-dashboards__hint">de {{ item.autor_nombre }}</span>
					</li>
				</ul>

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
						Tablero de {{ tablero.autor_nombre }}. Cada tarjeta hace su propia consulta.
					</p>
					<form v-if="tablero.editable" class="estadistica-dashboards__form" @submit.prevent="rename">
						<label class="estadistica-field">
							<span>Título del tablero</span>
							<input v-model="title" type="text" :maxlength="MAX_TITLE">
						</label>
						<NcButton type="submit" :disabled="title.trim() === '' || title.trim() === tablero.titulo || saving">
							Cambiar el título
						</NcButton>
						<NcButton variant="error" :disabled="saving" @click="remove">
							Eliminar el tablero
						</NcButton>
					</form>
				</header>

				<p v-if="cards.length === 0" class="estadistica-dashboards__hint">
					Este tablero todavía no tiene tarjetas.
				</p>
				<div class="estadistica-dashboards__grid">
					<div v-for="(card, index) in cards"
						:key="card.key"
						class="estadistica-dashboards__cell"
						:style="{ gridColumn: `span ${card.ancho}` }">
						<QuestionCard :pregunta="preguntaOf(card.pregunta)">
							<template v-if="tablero.editable" #actions>
								<NcButton variant="tertiary"
									:aria-label="`Subir la tarjeta «${cardName(card)}»`"
									:disabled="index === 0 || saving"
									@click="move(index, -1)">
									Subir
								</NcButton>
								<NcButton variant="tertiary"
									:aria-label="`Bajar la tarjeta «${cardName(card)}»`"
									:disabled="index === cards.length - 1 || saving"
									@click="move(index, 1)">
									Bajar
								</NcButton>
								<select :value="card.ancho"
									aria-label="Ancho de la tarjeta"
									:disabled="saving"
									@change="resize(index, Number($event.target.value))">
									<option v-for="ancho in WIDTHS" :key="ancho" :value="ancho">
										{{ ancho === 1 ? '1 columna' : `${ancho} columnas` }}
									</option>
								</select>
								<NcButton variant="tertiary"
									:aria-label="`Quitar la tarjeta «${cardName(card)}»`"
									:disabled="saving"
									@click="drop(index)">
									Quitar
								</NcButton>
							</template>
						</QuestionCard>
					</div>
				</div>

				<form v-if="tablero.editable" class="estadistica-dashboards__form estadistica-dashboards__add" @submit.prevent="add">
					<NcSelect v-model="adding"
						input-label="Agregar una pregunta guardada"
						:options="questionOptions"
						:reduce="(option) => option.id"
						placeholder="Elija una pregunta" />
					<NcButton type="submit" :disabled="adding === null || saving">
						Agregar tarjeta
					</NcButton>
				</form>
			</section>
		</template>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
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

	components: { NcButton, NcNoteCard, NcSelect, QuestionCard },

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
		}
	},

	computed: {
		tablero() {
			return this.tableros?.find((item) => item.id === this.openId) ?? null
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

		/** ponytail: the browser's own confirm; NcDialog when the page needs more than one yes/no. */
		async remove() {
			if (!window.confirm(`¿Eliminar el tablero «${this.tablero.titulo}»? Sus preguntas no se eliminan.`)) {
				return
			}
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

.estadistica-dashboards__list ul {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	margin: 8px 0;
}

.estadistica-dashboards__list li,
.estadistica-dashboards__form {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
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

@media (max-width: 1024px) {
	.estadistica-dashboards__grid {
		grid-template-columns: minmax(0, 1fr);
	}

	.estadistica-dashboards__cell {
		grid-column: auto !important;
	}
}

.estadistica-dashboards__hint {
	color: var(--color-text-maxcontrast);
}
</style>
