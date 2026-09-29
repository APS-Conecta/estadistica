<template>
	<div>
		<NcSettingsSection name="Años de historia nacional"
			description="Cuántos años de cifras REM de todo el país guarda esta instalación: todos
				los establecimientos, todos los meses, desde el año que elija hasta el actual.">
			<NcNoteCard v-if="failed" type="error">
				{{ failed }}
			</NcNoteCard>

			<NcNoteCard v-else-if="saved" type="success">
				Guardado. Los años se cargan o se quitan en segundo plano.
			</NcNoteCard>

			<NcTextField v-model="firstYear"
				label="Primer año"
				type="number"
				:min="yearWindow.earliest"
				:max="yearWindow.currentYear"
				:disabled="saving" />

			<p class="estadistica-admin__hint">
				{{ span }} — hasta ≈ {{ gigabytes }} GB en la base de datos y en cada respaldo.
			</p>

			<NcButton :disabled="saving"
				variant="primary"
				class="estadistica-admin__field"
				@click="save">
				Guardar
			</NcButton>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { messageFrom, warnError } from './api.js'

/**
 * Upper bound per year of national Serie A + P in the lean typed table
 * (research, 2025 A+P = 6.83M rows ≈ 1.08 GB with its indexes). Older years
 * are smaller, so the estimate errs on the side of the disk.
 */
const GB_PER_YEAR = 1.1

export default {
	name: 'AdminSettings',

	components: { NcButton, NcNoteCard, NcSettingsSection, NcTextField },

	data() {
		const yearWindow = loadState('estadistica', 'window')
		return {
			yearWindow,
			firstYear: String(yearWindow.firstYear),
			saving: false,
			saved: false,
			failed: '',
		}
	},

	computed: {
		years() {
			const first = Number.parseInt(this.firstYear, 10)
			return Number.isNaN(first) ? 0 : Math.max(0, this.yearWindow.currentYear - first + 1)
		},
		span() {
			return this.years === 1 ? '1 año' : `${this.years} años`
		},
		gigabytes() {
			return (this.years * GB_PER_YEAR).toLocaleString('es-CL', { maximumFractionDigits: 1 })
		},
	},

	methods: {
		async save() {
			this.saving = true
			this.saved = false
			this.failed = ''
			try {
				const { data } = await axios.put(
					generateUrl('/apps/estadistica/admin/window'),
					{ firstYear: Number.parseInt(this.firstYear, 10) || 0 },
				)
				this.yearWindow = data
				this.firstYear = String(data.firstYear)
				this.saved = true
			} catch (error) {
				warnError('could not save the year window', error)
				this.failed = await messageFrom(error, 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.estadistica-admin__field {
	margin-top: 12px;
}

.estadistica-admin__hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}
</style>
