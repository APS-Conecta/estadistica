<template>
	<NcContent app-name="estadistica">
		<NcAppContent>
			<!-- Tabs, not NcAppNavigation (owner, Slice 7): the sidebar and its
			     items weighed 176 KB of a 700 KB bundle for three links.
			     ponytail: the view lives in the page, so a reload returns to
			     Consultar and a tablero cannot be linked; hash links
			     (#/tableros/<id>) are the upgrade when one must be sent. -->
			<nav class="estadistica-app__tabs" aria-label="Estadística">
				<NcButton v-for="item in VIEWS"
					:key="item.id"
					:variant="view === item.id ? 'primary' : 'tertiary'"
					:aria-current="view === item.id ? 'page' : undefined"
					@click="view = item.id">
					{{ item.nombre }}
				</NcButton>
			</nav>

			<div class="estadistica-app">
				<!-- The mount-failure card (farmacia L2-22): data() that cannot read
				     its initial state renders THIS, not an eternal «Cargando…». -->
				<NcNoteCard v-if="stateFailed" type="error">
					No se pudieron cargar los datos de la aplicación. Recargue la página;
					si persiste, avise al administrador.
				</NcNoteCard>

				<template v-else>
					<NcNoteCard v-if="!establishment.configured" type="warning">
						Esta instalación todavía no tiene su establecimiento configurado: verá las
						cifras nacionales y de pares, pero no las propias.
					</NcNoteCard>

					<!-- The builder stays mounted, so a consulta half-built survives a look
					     at the tableros; the lists are read afresh each time they open. -->
					<QueryBuilder v-show="view === 'consultar'" :establishment="establishment" />
					<SavedQuestions v-if="view === 'preguntas'" />
					<EstadisticaDashboard v-if="view === 'tableros'" />
					<SanitaryGoals v-if="view === 'metas'" :establishment="establishment" />
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import EstadisticaDashboard from './components/Dashboard.vue'
import SanitaryGoals from './components/Goals.vue'
import QueryBuilder from './components/QueryBuilder.vue'
import SavedQuestions from './components/Questions.vue'
import { safeError } from './api.js'

/** The page's views. */
const VIEWS = [
	{ id: 'consultar', nombre: 'Consultar' },
	{ id: 'preguntas', nombre: 'Preguntas' },
	{ id: 'tableros', nombre: 'Tableros' },
	{ id: 'metas', nombre: 'Metas Sanitarias' },
]

export default {
	name: 'App',

	components: { EstadisticaDashboard, NcAppContent, NcButton, NcContent, NcNoteCard, QueryBuilder, SanitaryGoals, SavedQuestions },

	data() {
		const shared = { VIEWS, view: 'consultar' }
		try {
			return {
				...shared,
				stateFailed: false,
				establishment: loadState('estadistica', 'establishment'),
			}
		} catch (error) {
			safeError('could not read the initial state', error)
			return { ...shared, stateFailed: true, establishment: { configured: false } }
		}
	},
}
</script>

<style scoped>
.estadistica-app__tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	padding: 16px 24px 0;
}

.estadistica-app {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 24px 24px;
}
</style>

<style>
/* A title field, as every view that saves one writes it: a native input,
   which Nextcloud's own stylesheet already dresses (owner, Slice 7:
   NcTextField weighed 35 KB for three fields). */
.estadistica-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.estadistica-field input {
	width: min(100%, 360px);
	margin: 0;
}
</style>
