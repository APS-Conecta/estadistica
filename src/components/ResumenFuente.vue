<!--
	The source panel (docs/adr/0009): where one figure of a Resumen card comes
	from, for its own month. The origen and its fecha de corte, each part's
	ruta (its fuente's name, periodo, and the códigos × columnas added and
	taken away, labelled by that year's diccionario, as GET resumen answers
	them), and the metas' referencia. It is the app sidebar, beside the
	content as Nextcloud lays one out; Escape closes it.
-->
<template>
	<Teleport to="#content-vue">
		<NcAppSidebar ref="sidebar"
			:name="`Meta ${meta.codigo} ${anio}`"
			:subname="cifra.mes ? `Cifra de ${monthName(cifra.mes)}` : 'Cumplimiento informado'"
			@close="$emit('close')"
			@keydown.esc="$emit('close')">
			<div class="estadistica-fuente">
				<p v-if="meta.manual">
					Lo informa quien administra Estadística: no se cuenta en el REM.
				</p>
				<p v-else-if="cifra.origen">
					Origen: {{ cifra.origen }}, con cifras hasta {{ monthName(cifra.fecha_corte) }}.
				</p>

				<section v-for="parte in partes" :key="parte.label">
					<h3>{{ parte.label }}</h3>
					<p v-if="!parte.ruta">
						Lo comprometió el establecimiento.
					</p>
					<template v-else>
						<p>{{ parte.ruta.nombre }}<template v-if="parte.ruta.desde">, de {{ monthName(parte.ruta.desde) }} a {{ monthName(parte.ruta.hasta) }}</template>.</p>
						<template v-for="sign in signs(parte.ruta)" :key="sign.label">
							<p class="estadistica-fuente__signo">
								{{ sign.label }}
							</p>
							<ul>
								<li v-for="(codigo, i) in sign.codigos" :key="i" class="estadistica-fuente__codigo">
									<strong>{{ codigo.codigo }}</strong> {{ codigo.prestacion }}
									<span v-if="codigo.hoja" class="estadistica-fuente__hoja">
										Hoja {{ codigo.hoja }}, {{ codigo.seccion }}{{ codigo.nota ? ` (${codigo.nota})` : '' }}
									</span>
									<ul>
										<li v-for="(columna, col) in codigo.columnas" :key="col">
											{{ col }}: {{ columna }}
										</li>
									</ul>
								</li>
							</ul>
						</template>
					</template>
				</section>

				<p class="estadistica-fuente__referencia">
					Referencia: {{ referencia }}
				</p>
			</div>
		</NcAppSidebar>
	</Teleport>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import { monthName } from '../query.js'

export default {
	name: 'ResumenFuente',

	components: { NcAppSidebar },

	props: {
		/** One card of GET resumen. */
		meta: { type: Object, required: true },
		/** The figure's month: its mes, origen and fecha_corte, and its year's ruta. */
		cifra: { type: Object, required: true },
		/** GET resumen's anio and referencia. */
		anio: { type: Number, required: true },
		referencia: { type: String, required: true },
	},

	emits: ['close'],

	// The focus moves into the panel at once, whatever a click did with it (a
	// button clicked in Firefox or Safari may not take it), so Escape reaches it.
	mounted() {
		this.$refs.sidebar.focus()
	},

	computed: {
		partes() {
			return this.meta.manual
				? []
				: [{ label: 'Numerador', ruta: this.cifra.ruta.numerador }, { label: 'Denominador', ruta: this.cifra.ruta.denominador }]
		},
	},

	methods: {
		monthName,

		/** What a part adds and takes away, each consulta's códigos in one list. */
		signs(ruta) {
			return [{ label: 'Suma:', codigos: ruta.sumar.flat() }, { label: 'Resta:', codigos: ruta.restar.flat() }]
				.filter((sign) => sign.codigos.length > 0)
		},
	},
}
</script>

<style scoped>
.estadistica-fuente {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 0 12px 12px;
}

.estadistica-fuente h3 {
	margin: 8px 0 0;
	font-size: 1em;
	font-weight: bold;
}

.estadistica-fuente ul {
	margin: 0 0 4px;
	padding-inline-start: 16px;
	list-style: disc;
}

.estadistica-fuente__hoja,
.estadistica-fuente__referencia {
	display: block;
	color: var(--color-text-maxcontrast);
}
</style>
