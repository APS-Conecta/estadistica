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

		<NcSettingsSection name="Cifras REM cargadas"
			description="Cada año y serie del REM se carga completo y en segundo plano, desde un ZIP del
				DEIS elegido en Archivos. Si una carga falla, se sigue mostrando la anterior y aquí queda
				escrito por qué.">
			<NcNoteCard v-if="ingestsFailed" type="error">
				{{ ingestsFailed }}
			</NcNoteCard>

			<p v-else-if="ingestsLoading" class="estadistica-admin__hint">
				Cargando…
			</p>

			<p v-else-if="ingests.length === 0" class="estadistica-admin__hint">
				Todavía no hay cifras REM cargadas.
			</p>

			<table v-else class="estadistica-admin__table">
				<thead>
					<tr>
						<th>Año</th>
						<th>Serie</th>
						<th>Estado</th>
						<th>Fecha de corte</th>
						<th>Filas</th>
						<th>Último intento</th>
					</tr>
				</thead>
				<tbody>
					<template v-for="ingest in ingests" :key="`${ingest.anio}-${ingest.serie}`">
						<tr>
							<td>{{ ingest.anio }}</td>
							<td>{{ ingest.serie }}</td>
							<td>{{ status(ingest) }}</td>
							<td>{{ ingest.fechaCorte ?? '—' }}</td>
							<td>{{ count(ingest.rowCount) }}</td>
							<td>{{ when(ingest.lastAttempt) }}</td>
						</tr>
						<tr v-if="ingest.lastError || ingest.detail">
							<td colspan="6" class="estadistica-admin__detail">
								<p v-if="ingest.lastError" class="estadistica-admin__error">
									{{ ingest.lastError }}
								</p>
								<p v-if="ingest.detail">
									{{ ingest.detail }}
								</p>
							</td>
						</tr>
					</template>
				</tbody>
			</table>

			<NcNoteCard v-if="dropFailed" type="error">
				{{ dropFailed }}
			</NcNoteCard>

			<NcNoteCard v-else-if="dropped" type="success">
				Recibido: cifras de {{ dropped.anio }} ({{ seriesText(dropped.series) }}). Se cargan en
				segundo plano, una serie por hora.
			</NcNoteCard>

			<NcButton :disabled="dropping"
				class="estadistica-admin__field"
				@click="pickZip">
				{{ dropping ? 'Revisando el archivo…' : 'Elegir un ZIP en Archivos…' }}
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
			ingests: [],
			ingestsLoading: true,
			ingestsFailed: '',
			dropping: false,
			dropped: null,
			dropFailed: '',
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

	mounted() {
		this.loadIngests()
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

		async loadIngests() {
			this.ingestsFailed = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/estadistica/admin/ingests'))
				this.ingests = data.ingests
			} catch (error) {
				warnError('could not read the ingest state', error)
				this.ingestsFailed = await messageFrom(error, 'No se pudo leer el estado de las cargas. Recargue la página.')
			} finally {
				this.ingestsLoading = false
			}
		},

		/**
		 * The platform's own file picker, which hands back a PATH: nothing is
		 * read in the browser and nothing is uploaded again (territorio #104).
		 *
		 * ponytail: `OC.dialogs.filepicker`, which Nextcloud 34 still ships, so
		 * no new dependency. The upgrade path, if core drops `OC.dialogs`, is
		 * `@nextcloud/dialogs`. No mimetype filter: the server tells a zip by its
		 * bytes, and says in Spanish what is wrong with anything else.
		 */
		pickZip() {
			if (!window.OC?.dialogs?.filepicker) {
				this.dropFailed = 'Esta versión de Nextcloud no ofrece el selector de archivos.'
				return
			}

			window.OC.dialogs.filepicker(
				'Elegir un ZIP del DEIS',
				(path) => this.drop(path),
				false,
				[],
				true,
			)
		},

		async drop(path) {
			this.dropping = true
			this.dropped = null
			this.dropFailed = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/estadistica/admin/drop'), { path })
				this.dropped = data
				await this.loadIngests()
			} catch (error) {
				warnError('could not hand over the file', error)
				this.dropFailed = await messageFrom(error, 'No se pudo recibir el archivo. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.dropping = false
			}
		},

		status(ingest) {
			if (ingest.pending) {
				return 'En cola'
			}
			if (ingest.rowCount === 0) {
				return ingest.lastError ? 'Con error' : 'Sin datos'
			}
			return ingest.incomplete ? 'Incompleto' : 'Cargado'
		},

		seriesText(series) {
			return series.map((serie) => `Serie ${serie}`).join(' y ')
		},

		count(n) {
			return n.toLocaleString('es-CL')
		},

		when(seconds) {
			return seconds ? new Date(seconds * 1000).toLocaleString('es-CL') : '—'
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

.estadistica-admin__table {
	margin-top: 8px;
	border-collapse: collapse;
}

.estadistica-admin__table th,
.estadistica-admin__table td {
	padding: 4px 12px 4px 0;
	text-align: start;
}

.estadistica-admin__detail {
	white-space: pre-line;
	color: var(--color-text-maxcontrast);
}

.estadistica-admin__error {
	color: var(--color-error-text);
}
</style>
