<!--
	The builder's first step, Datos: which fuente is asked, and beside each one
	where it comes from and up to when (a22: «each fuente shows its fecha de
	corte and origen»). The establishments are a fuente too; they are listed,
	not chosen, because a consulta counts REM figures (QuerySpec's fuente).
-->
<template>
	<fieldset class="estadistica-sources">
		<legend>Fuente</legend>

		<template v-for="fuente in rem" :key="fuente.id">
			<NcCheckboxRadioSwitch :model-value="modelValue"
				:value="fuente.id"
				:disabled="fuente.filas === 0"
				name="estadistica-fuente"
				type="radio"
				@update:model-value="$emit('update:modelValue', $event)">
				{{ fuente.nombre }}
			</NcCheckboxRadioSwitch>
			<p class="estadistica-sources__about">
				{{ about(fuente) }}
			</p>
		</template>

		<p v-if="register" class="estadistica-sources__about">
			{{ registerText }}
		</p>
	</fieldset>
</template>

<script>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import { formatValue } from '../charts.js'
import { monthName } from '../query.js'

export default {
	name: 'SourcesPanel',

	components: { NcCheckboxRadioSwitch },

	props: {
		/** GET fuentes, as the OCS route serves it (Sources::all). */
		fuentes: { type: Array, required: true },
		/** The chosen fuente id. */
		modelValue: { type: String, default: null },
	},

	emits: ['update:modelValue'],

	computed: {
		rem() {
			return this.fuentes.filter((fuente) => fuente.id.startsWith('rem-'))
		},
		register() {
			return this.fuentes.find((fuente) => fuente.id === 'establecimientos') ?? null
		},
		registerText() {
			if (this.register.filas === 0) {
				return 'Base de Establecimientos: todavía no se ha cargado, así que no hay pares con qué comparar.'
			}
			return `Base de Establecimientos (${this.register.origen}, ${this.register.fecha_corte}): ${formatValue(this.register.filas)} establecimientos, con los que se eligen los pares.`
		},
	},

	methods: {
		about(fuente) {
			if (fuente.filas === 0) {
				return `${fuente.origen}: todavía no hay cifras cargadas.`
			}
			const years = fuente.anios.filter((anio) => anio.filas > 0).map((anio) => anio.anio)
			const span = years.length === 1 ? `${years[0]}` : `${Math.min(...years)} a ${Math.max(...years)}`
			const incomplete = fuente.estado === 'incompleto'
				? ' Algún año está incompleto: el DEIS lo publicó cortado y se cargaron solo sus filas enteras.'
				: ''
			const until = fuente.fecha_corte ? `, con cifras hasta ${monthName(fuente.fecha_corte)}` : ''
			return `${fuente.origen}, ${span}${until}.${incomplete}`
		},
	},
}
</script>

<style scoped>
.estadistica-sources {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.estadistica-sources legend {
	font-weight: bold;
}

.estadistica-sources__about {
	margin: 0 0 8px 36px;
	color: var(--color-text-maxcontrast);
}
</style>
