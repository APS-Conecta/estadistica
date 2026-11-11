<template>
	<NcContent app-name="estadistica">
		<!-- ponytail: the view lives in the page, so a reload returns to the Resumen
		     and a tablero cannot be linked; hash links (#/tableros/<id>) are the
		     upgrade when one must be sent. -->
		<NcAppNavigation aria-label="Estadística">
			<template #list>
				<NcAppNavigationItem v-for="item in VIEWS"
					:key="item.id"
					:name="item.nombre"
					:active="view === item.id"
					@click.prevent="view = item.id">
					<template #icon>
						<NcIconSvgWrapper :path="item.icon" />
					</template>
				</NcAppNavigationItem>
			</template>
			<!-- The admin section's page, offered to an administrator only (#84); a
			     delegate (docs/adr/0003) reaches it from Nextcloud's own settings. -->
			<template v-if="settingsUrl" #footer>
				<NcAppNavigationList>
					<NcAppNavigationItem name="Ajustes de Estadística" :href="settingsUrl">
						<template #icon>
							<NcIconSvgWrapper :path="mdiCogOutline" />
						</template>
					</NcAppNavigationItem>
				</NcAppNavigationList>
			</template>
		</NcAppNavigation>

		<NcAppContent :page-heading="current.nombre">
			<header class="estadistica-heading">
				<h2>{{ current.nombre }}</h2>
			</header>

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

					<EstadisticaResumen v-if="view === 'resumen'" />
					<!-- The builder mounts when Consultar first opens and then stays mounted,
					     so a consulta half-built survives a look at the tableros; the lists
					     are read afresh each time they open. -->
					<QueryBuilder v-if="builderOpened" v-show="view === 'consultar'" :establishment="establishment" />
					<SavedQuestions v-if="view === 'preguntas'" />
					<EstadisticaDashboard v-if="view === 'tableros'" />
					<SanitaryGoals v-if="view === 'metas'" :establishment="establishment" />
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { defineAsyncComponent, h } from 'vue'
import { safeError } from './api.js'
import { mdiBookmarkOutline, mdiBullseyeArrow, mdiChartBox, mdiCogOutline, mdiMagnify, mdiViewGridOutline } from './icons.js'

// A tab whose chunk cannot be fetched shows this in its place, not an empty page.
// inheritAttrs: false — the loader's error and the tab's props stay off the card.
const TabLoadFailed = {
	name: 'TabLoadFailed',
	inheritAttrs: false,
	render: () => h(NcNoteCard, { type: 'error' }, () => 'No se pudo cargar esta vista. Recargue la página; si persiste, avise al administrador.'),
}

// Each tab is its own chunk (js/estadistica-<view>.js), fetched the first time
// the tab opens, so the main bundle carries only the shell (#43, #23).
const lazyTab = (loader) => defineAsyncComponent({ loader, errorComponent: TabLoadFailed })

const EstadisticaResumen = lazyTab(() => import(/* webpackChunkName: "resumen" */ './components/Resumen.vue'))
const EstadisticaDashboard = lazyTab(() => import(/* webpackChunkName: "tableros" */ './components/Dashboard.vue'))
const SanitaryGoals = lazyTab(() => import(/* webpackChunkName: "metas" */ './components/Goals.vue'))
const QueryBuilder = lazyTab(() => import(/* webpackChunkName: "consultar" */ './components/QueryBuilder.vue'))
const SavedQuestions = lazyTab(() => import(/* webpackChunkName: "preguntas" */ './components/Questions.vue'))

/** The page's views, in the navigation's order; the first opens with the page (docs/adr/0009). */
const VIEWS = [
	{ id: 'resumen', nombre: 'Resumen', icon: mdiChartBox },
	{ id: 'metas', nombre: 'Metas Sanitarias', icon: mdiBullseyeArrow },
	{ id: 'consultar', nombre: 'Consultar', icon: mdiMagnify },
	{ id: 'preguntas', nombre: 'Preguntas', icon: mdiBookmarkOutline },
	{ id: 'tableros', nombre: 'Tableros', icon: mdiViewGridOutline },
]

export default {
	name: 'App',

	components: {
		EstadisticaDashboard, EstadisticaResumen, NcAppContent, NcAppNavigation, NcAppNavigationItem, NcAppNavigationList,
		NcContent, NcIconSvgWrapper, NcNoteCard, QueryBuilder, SanitaryGoals, SavedQuestions,
	},

	data() {
		const shared = {
			VIEWS,
			mdiCogOutline,
			view: VIEWS[0].id,
			builderOpened: false,
			settingsUrl: getCurrentUser()?.isAdmin ? generateUrl('/settings/admin/estadistica') : null,
		}
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

	computed: {
		current() {
			return VIEWS.find((item) => item.id === this.view)
		},
	},

	watch: {
		view(id) {
			this.builderOpened ||= id === 'consultar'
		},
	},
}
</script>

<style scoped>
/* NcAppNavigation's toggle sits in the content's top corner, open or folded:
   the view's title starts beside it, never under it. */
.estadistica-heading {
	display: flex;
	align-items: center;
	min-height: var(--default-clickable-area);
	margin-top: var(--app-navigation-padding);
	padding-inline: calc(var(--default-clickable-area) + var(--default-grid-baseline) * 3) 24px;
}

.estadistica-heading h2 {
	margin: 0;
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

/* Every view's data table (#83): the header row stays in view while the
   content scrolls, the caption says what the table holds, and the figures
   line up. Header cells carry scope="col" or scope="row" in the markup. */
.estadistica-table {
	width: 100%;
	border-collapse: collapse;
}

.estadistica-table caption {
	caption-side: bottom;
	padding: calc(var(--default-grid-baseline) * 2) 0;
	color: var(--color-text-maxcontrast);
	text-align: start;
}

.estadistica-table th,
.estadistica-table td {
	padding: calc(var(--default-grid-baseline) * 1.5) calc(var(--default-grid-baseline) * 2.5);
	border-bottom: 1px solid var(--color-border);
	text-align: end;
	font-variant-numeric: tabular-nums;
}

.estadistica-table th:first-child,
.estadistica-table td:first-child {
	text-align: start;
}

.estadistica-table thead th {
	position: sticky;
	top: 0;
	z-index: 1;
	background-color: var(--color-main-background);
	color: var(--color-text-maxcontrast);
}
</style>
