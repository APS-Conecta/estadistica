<!--
	One pregunta, asked on its own (a8): a tablero's card, or a pregunta opened
	in the list. It posts its consulta to the route the builder uses, so its
	figures are the API's (a15). A refusal is shown here, in this card, and the
	cards beside it keep theirs.
-->
<template>
	<article class="estadistica-card">
		<header class="estadistica-card__head">
			<h3>{{ pregunta ? pregunta.titulo : 'Pregunta eliminada' }}</h3>
			<slot name="actions" />
		</header>

		<NcNoteCard v-if="!pregunta" type="warning">
			Esta pregunta fue eliminada. Quien edita el tablero puede quitar la tarjeta.
		</NcNoteCard>

		<NcNoteCard v-else-if="failed" type="error">
			{{ failed }}
		</NcNoteCard>

		<p v-else-if="answer === null" class="estadistica-card__hint">
			Consultando…
		</p>

		<Visualization v-else
			:answer="answer"
			:spec="asked"
			:tipo="pregunta.tipo" />
	</article>
</template>

<script>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Visualization from './Visualization.vue'
import { ocsMessageFrom, warnError } from '../api.js'
import { consult } from '../query.js'

export default {
	name: 'QuestionCard',

	components: { NcNoteCard, Visualization },

	props: {
		/** A pregunta as GET preguntas serves it, or null when the card's pregunta was deleted. */
		pregunta: { type: Object, default: null },
	},

	data() {
		return { answer: null, failed: '' }
	},

	computed: {
		/**
		 * The consulta as Visualization reads it. A pregunta saved over the API
		 * may leave out agrupar and metrica, which QuerySpec reads as none and
		 * suma.
		 */
		asked() {
			return { agrupar: [], metrica: 'suma', ...this.pregunta.consulta }
		},
		/** What is asked, as text: a renamed pregunta arrives as a new object and is not asked again. */
		question() {
			return JSON.stringify(this.pregunta?.consulta ?? null)
		},
	},

	watch: {
		question: 'ask',
	},

	mounted() {
		this.ask()
	},

	methods: {
		async ask() {
			this.answer = null
			this.failed = ''
			if (!this.pregunta) {
				return
			}
			try {
				this.answer = await consult(this.pregunta.consulta)
			} catch (error) {
				warnError('could not answer a card', error)
				this.failed = await ocsMessageFrom(error, 'No se pudo hacer esta consulta. Revise la conexión y recargue la página.')
			}
		},
	},
}
</script>

<style scoped>
.estadistica-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-width: 0;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.estadistica-card__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.estadistica-card__head h3 {
	flex: 1;
	margin: 0;
	font-size: 1.05em;
}

.estadistica-card__hint {
	color: var(--color-text-maxcontrast);
}
</style>
