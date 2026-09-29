<template>
	<NcContent app-name="estadistica">
		<NcAppContent>
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

					<NcEmptyContent name="Todavía no hay cifras REM"
						description="Aparecerán aquí cuando se carguen los datos públicos del DEIS." />
				</template>
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { safeError } from './api.js'

export default {
	name: 'App',

	components: { NcAppContent, NcContent, NcEmptyContent, NcNoteCard },

	data() {
		try {
			return {
				stateFailed: false,
				establishment: loadState('estadistica', 'establishment'),
			}
		} catch (error) {
			safeError('could not read the initial state', error)
			return { stateFailed: true, establishment: { configured: false } }
		}
	},
}
</script>

<style scoped>
.estadistica-app {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px 24px 24px;
}
</style>
