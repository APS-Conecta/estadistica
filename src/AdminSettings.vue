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
			description="Cada año y serie del REM se carga completo y en segundo plano, desde el DEIS o
				desde un ZIP elegido en «Cargar un archivo a mano», junto con el diccionario de códigos de
				ese año. Si una carga falla, se sigue mostrando la anterior y aquí queda escrito por qué.">
			<p v-if="deis" class="estadistica-admin__hint">
				{{ deisText }}
			</p>

			<NcNoteCard v-if="deis && deis.errors" type="warning">
				<p>La última revisión del DEIS no pudo revisar todos los años. Lo ya cargado se sigue mostrando.</p>
				<p class="estadistica-admin__detail">
					{{ deis.errors }}
				</p>
			</NcNoteCard>

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
						<th>Sin descripción</th>
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
							<td>{{ unmapped(ingest) }}</td>
							<td>{{ when(ingest.lastAttempt) }}</td>
						</tr>
						<tr v-if="ingest.lastError || ingest.detail || labelsNote(ingest)">
							<td colspan="7" class="estadistica-admin__detail">
								<p v-if="ingest.lastError" class="estadistica-admin__error">
									{{ ingest.lastError }}
								</p>
								<p v-if="ingest.detail">
									{{ ingest.detail }}
								</p>
								<p v-if="labelsNote(ingest)">
									{{ labelsNote(ingest) }}
								</p>
							</td>
						</tr>
					</template>
				</tbody>
			</table>

			<h3>Base de Establecimientos</h3>
			<p class="estadistica-admin__hint">
				{{ registerText }}
			</p>
		</NcSettingsSection>

		<NcSettingsSection name="Población inscrita validada (FONASA)"
			description="La población que FONASA valida cada año por establecimiento, edad y sexo: el
				denominador de varias Metas Sanitarias. FONASA no la publica en un archivo que se pueda
				descargar solo, así que se carga a mano: el CSV «Inscritos APS» que exporta su reporte, o
				las cifras de su establecimiento escritas aquí.">
			<p v-if="piv.cortes.length === 0" class="estadistica-admin__hint">
				Todavía no hay ningún corte de FONASA cargado. Elija el CSV en «Cargar un archivo a mano»,
				más abajo.
			</p>

			<ul v-else class="estadistica-admin__hint">
				<li v-for="corte in piv.cortes" :key="corte.corte">
					Corte al {{ corteText(corte.corte) }}: {{ count(corte.establishments) }} establecimientos,
					{{ count(corte.inscritos) }} inscritos con tramo de edad, cargado el {{ when(corte.loadedAt) }}.
				</li>
			</ul>

			<h3>Población de su establecimiento</h3>
			<p v-if="!piv.local" class="estadistica-admin__hint">
				Sin un establecimiento configurado no hay población propia que escribir.
			</p>

			<template v-else>
				<p class="estadistica-admin__hint">
					Escriba la población inscrita de su establecimiento (código DEIS {{ piv.local.deisCode }})
					cuando FONASA todavía no la entrega, o para corregirla. Una casilla en blanco usa la cifra
					de FONASA, la que se ve en gris. Lo escrito reemplaza a FONASA solo para su establecimiento:
					el país y los pares siguen con las cifras de FONASA.
				</p>

				<NcNoteCard v-if="pivFailed" type="error">
					{{ pivFailed }}
				</NcNoteCard>

				<NcNoteCard v-else-if="pivSaved" type="success">
					Guardado.
				</NcNoteCard>

				<label class="estadistica-admin__field estadistica-admin__year">
					Corte al 31 de diciembre de
					<input v-model="pivYear"
						type="number"
						:min="yearWindow.earliest"
						:max="yearWindow.currentYear"
						:disabled="pivSaving">
				</label>

				<table class="estadistica-admin__table">
					<thead>
						<tr>
							<th>Tramo de edad</th>
							<th v-for="sex in sexes" :key="sex.id">
								{{ sex.label }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="band in bands" :key="band.edad">
							<th scope="row">
								{{ band.label }}
							</th>
							<td v-for="sex in sexes" :key="sex.id">
								<input v-model="pivCells[cellKey(band.edad, sex.id)]"
									type="number"
									min="0"
									step="1"
									class="estadistica-admin__cell"
									:aria-label="`${sex.label} de ${band.label}`"
									:placeholder="fonasaOf(band.edad, sex.id)"
									:disabled="pivSaving">
							</td>
						</tr>
					</tbody>
				</table>

				<NcButton :disabled="pivSaving"
					variant="primary"
					class="estadistica-admin__field"
					@click="savePiv">
					Guardar
				</NcButton>
			</template>
		</NcSettingsSection>

		<NcSettingsSection name="Cargar un archivo a mano"
			description="Para lo que esta instalación no descarga sola: suba el archivo a Archivos y
				elíjalo aquí. Se revisa al momento, y un archivo que no sea uno de estos tres se rechaza sin
				guardar nada.">
			<ul class="estadistica-admin__hint">
				<li>
					El ZIP de cifras REM que publica el DEIS (por ejemplo «SERIE_REM_2024.zip»): se carga en
					segundo plano, igual que una descarga automática.
				</li>
				<li>La «Base de Establecimientos» del DEIS (.xlsx): queda en uso de inmediato.</li>
				<li>El CSV «Inscritos APS» que exporta el reporte de FONASA: queda en uso de inmediato.</li>
			</ul>

			<NcNoteCard v-if="dropFailed" type="error">
				{{ dropFailed }}
			</NcNoteCard>

			<NcNoteCard v-else-if="dropped && dropped.piv" type="success">
				{{ pivDroppedText }}
			</NcNoteCard>

			<NcNoteCard v-else-if="dropped && dropped.establecimientos !== undefined" type="success">
				Recibida la Base de Establecimientos: {{ count(dropped.establecimientos) }} establecimientos,
				ya en uso.
			</NcNoteCard>

			<NcNoteCard v-else-if="dropped" type="success">
				Recibido: cifras de {{ dropped.anio }} ({{ seriesText(dropped.series) }}). Se cargan en
				segundo plano, una serie por hora.
			</NcNoteCard>

			<NcButton :disabled="dropping"
				class="estadistica-admin__field"
				@click="pickZip">
				{{ dropping ? 'Revisando el archivo…' : 'Elegir un archivo en Archivos…' }}
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

/** FONASA's age bands by their first year (PivReader::BANDS). */
const BANDS = [
	{ edad: 0, label: '0 a 9 años' },
	{ edad: 10, label: '10 a 19 años' },
	{ edad: 20, label: '20 a 29 años' },
	{ edad: 30, label: '30 a 39 años' },
	{ edad: 40, label: '40 a 49 años' },
	{ edad: 50, label: '50 a 59 años' },
	{ edad: 60, label: '60 a 69 años' },
	{ edad: 70, label: '70 a 79 años' },
	{ edad: 80, label: '80 a más años' },
]

/** The sexes a figure can be typed for (PivImporter::TYPED_SEXES). */
const SEXES = [
	{ id: 'M', label: 'Mujeres' },
	{ id: 'H', label: 'Hombres' },
]

const cellKey = (edad, sexo) => `${edad}-${sexo}`

/** Every typed cell of the grid, empty. */
function emptyCells() {
	return Object.fromEntries(BANDS.flatMap((band) => SEXES.map((sex) => [cellKey(band.edad, sex.id), ''])))
}

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
			dictionaries: [],
			register: null,
			deis: null,
			ingestsLoading: true,
			ingestsFailed: '',
			dropping: false,
			dropped: null,
			dropFailed: '',
			piv: { cortes: [], local: null },
			pivYear: String(yearWindow.currentYear - 1),
			pivCells: emptyCells(),
			pivSaving: false,
			pivSaved: false,
			pivFailed: '',
			bands: BANDS,
			sexes: SEXES,
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
		registerText() {
			if (!this.register || this.register.rows === 0) {
				return 'Todavía no se ha cargado: sin ella las cifras no se pueden comparar con establecimientos parecidos. Se descarga del DEIS en la revisión diaria, o puede elegirla aquí abajo.'
			}
			const source = this.register.origin === 'deis'
				? `la ${this.register.year} del DEIS`
				: 'la elegida a mano'
			return `En uso ${source}: ${this.count(this.register.rows)} establecimientos, cargada el ${this.when(this.register.loadedAt)}.`
		},
		deisText() {
			if (!this.deis.online) {
				return 'Esta instalación está configurada sin acceso a internet, así que no consulta el DEIS: las cifras se cargan solo desde un ZIP elegido aquí.'
			}
			const cadence = 'El DEIS se revisa una vez al día, y cada año nuevo o modificado se carga en segundo plano.'
			return this.deis.checkedAt
				? `Última revisión del DEIS: ${this.when(this.deis.checkedAt)}. ${cadence}`
				: `El DEIS se revisará dentro de la próxima hora. ${cadence}`
		},
		/** The corte the grid shows: 31 December of the chosen year. */
		pivCorte() {
			return `${Number.parseInt(this.pivYear, 10) || 0}-12-31`
		},
		pivDroppedText() {
			const piv = this.dropped.piv
			const unaged = piv.unknownAge > 0
				? ` ${this.count(piv.unknownAge)} inscritos sin tramo de edad no entran en ningún denominador.`
				: ''
			return `Recibida la población inscrita de FONASA, corte al ${piv.cortes.map(this.corteText).join(', ')}: `
				+ `${this.count(piv.establishments)} establecimientos, ${this.count(piv.inscritos)} inscritos, ya en uso.${unaged}`
		},
	},

	watch: {
		pivCorte() {
			this.pivSaved = false
			this.fillPivCells()
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
				// The save moved the next DEIS check up to the coming hour.
				await this.loadIngests()
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
				this.dictionaries = data.dictionaries
				this.register = data.register
				this.deis = data.deis
				this.piv = data.piv
				this.fillPivCells()
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
		 * `@nextcloud/dialogs`. No mimetype filter: the server tells a zip from
		 * FONASA's CSV by their bytes, and says in Spanish what is wrong with
		 * anything else.
		 */
		pickZip() {
			if (!window.OC?.dialogs?.filepicker) {
				this.dropFailed = 'Esta versión de Nextcloud no ofrece el selector de archivos.'
				return
			}

			window.OC.dialogs.filepicker(
				'Elegir un archivo del DEIS o de FONASA',
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

		dictionaryOf(ingest) {
			return this.dictionaries.find((dictionary) => dictionary.anio === ingest.anio && dictionary.serie === ingest.serie) ?? null
		},

		unmapped(ingest) {
			const dictionary = this.dictionaryOf(ingest)
			return dictionary && ingest.rowCount > 0 ? this.count(dictionary.unmapped) : '—'
		},

		/** Whose labels the figures read, when they are not the year's own, and why. */
		labelsNote(ingest) {
			const dictionary = this.dictionaryOf(ingest)
			if (!dictionary) {
				return ''
			}
			if (dictionary.error) {
				return `Diccionario: ${dictionary.error}`
			}
			if (dictionary.labelsFrom === null) {
				return 'No hay diccionario de este año ni de uno posterior: sus códigos se muestran «sin descripción».'
			}
			if (dictionary.labelsFrom !== ingest.anio) {
				return `Este año no trae un diccionario que se pueda leer: sus códigos usan las etiquetas del diccionario de ${dictionary.labelsFrom}.`
			}
			return ''
		},

		cellKey,

		/** This install's establishment's figures for the corte the grid shows. */
		cellsOfCorte() {
			return (this.piv.local?.cells ?? []).filter((cell) => cell.corte === this.pivCorte)
		},

		/** FONASA's figure for a cell, shown in gray where nothing is typed. */
		fonasaOf(edad, sexo) {
			const cell = this.cellsOfCorte().find((c) => c.edad === edad && c.sexo === sexo)
			return cell && cell.fonasa !== null ? this.count(cell.fonasa) : '—'
		},

		/** The grid, from what is typed for the corte it shows. */
		fillPivCells() {
			const cells = emptyCells()
			for (const cell of this.cellsOfCorte()) {
				const key = cellKey(cell.edad, cell.sexo)
				if (cell.manual !== null && key in cells) {
					cells[key] = String(cell.manual)
				}
			}
			this.pivCells = cells
		},

		/**
		 * Sends the typed cells, and only those: a blank one is FONASA's.
		 * The server replaces what was typed for the corte with exactly this.
		 */
		async savePiv() {
			this.pivSaved = false
			this.pivFailed = ''
			const cells = []
			for (const band of BANDS) {
				for (const sex of SEXES) {
					const typed = String(this.pivCells[cellKey(band.edad, sex.id)] ?? '').trim()
					if (typed === '') {
						continue
					}
					if (!/^\d+$/.test(typed)) {
						this.pivFailed = `${sex.label} de ${band.label}: escriba un número entero, o deje la casilla en blanco.`
						return
					}
					cells.push({ edad: band.edad, sexo: sex.id, inscritos: Number.parseInt(typed, 10) })
				}
			}

			this.pivSaving = true
			try {
				const { data } = await axios.put(
					generateUrl('/apps/estadistica/admin/piv'),
					{ year: Number.parseInt(this.pivYear, 10) || 0, cells },
				)
				this.piv = data
				this.fillPivCells()
				this.pivSaved = true
			} catch (error) {
				warnError('could not save the PIV figures', error)
				this.pivFailed = await messageFrom(error, 'No se pudo guardar. Revise la conexión e inténtelo de nuevo.')
			} finally {
				this.pivSaving = false
			}
		},

		/** 2025-12-31 as FONASA writes it: 31-12-2025. */
		corteText(corte) {
			return corte.split('-').reverse().join('-')
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

.estadistica-admin__year input {
	width: 7em;
	margin-inline-start: 8px;
}

.estadistica-admin__cell {
	width: 8em;
}
</style>
