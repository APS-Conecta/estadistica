<!--
	Where a meta's figure comes from (docs/adr/0009, #86): the origen and its
	fecha de corte, each part's ruta (its fuente's name, periodo, and the
	códigos × columnas added and taken away, labelled by that year's
	diccionario, as GET resumen answers them), and the metas' referencia. A
	manual meta says who reports it; a meta with no ruta yet says it waits.
-->
<template>
	<div class="estadistica-route">
		<p v-if="manual">
			Lo informa quien administra Estadística: no se cuenta en el REM.
		</p>
		<template v-else-if="ruta">
			<p v-if="origen" class="estadistica-route__hint">
				Origen: {{ origen }}. Cada casilla se lee con el diccionario de su año.
			</p>
			<section v-for="parte in partes" :key="parte.label" class="estadistica-route__parte">
				<h3>{{ parte.label }}</h3>
				<p v-if="!parte.ruta">
					Lo comprometió el establecimiento.
				</p>
				<template v-else>
					<p>{{ parte.ruta.nombre }}<template v-if="parte.ruta.desde">, de {{ monthName(parte.ruta.desde) }} a {{ monthName(parte.ruta.hasta) }}</template>.</p>
					<template v-for="sign in signs(parte.ruta)" :key="sign.label">
						<p class="estadistica-route__hint">
							{{ sign.label }}
						</p>
						<ul>
							<li v-for="(codigo, i) in sign.codigos" :key="i" class="estadistica-route__codigo">
								<code>{{ codigo.codigo }}</code> {{ codigo.prestacion }}
								<span v-if="codigo.hoja" class="estadistica-route__hoja">
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
		</template>
		<p v-else>
			La ruta REM de esta meta se mostrará aquí cuando MINSAL la confirme. Estadística no la deduce.
		</p>

		<p class="estadistica-route__hint">
			Referencia: {{ referencia }}
		</p>
	</div>
</template>

<script>
import { monthName } from '../query.js'

export default {
	name: 'RouteList',

	props: {
		/** GET resumen's ruta of a year ({ numerador, denominador }), or null while it is not official. */
		ruta: { type: Object, default: null },
		manual: { type: Boolean, default: false },
		/** Where the figure comes from, as originText says it; empty when it has none. */
		origen: { type: String, default: '' },
		referencia: { type: String, required: true },
	},

	computed: {
		partes() {
			return [{ label: 'Numerador', ruta: this.ruta.numerador }, { label: 'Denominador', ruta: this.ruta.denominador }]
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
.estadistica-route {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 3);
}

.estadistica-route p {
	margin: 0;
}

.estadistica-route__hint,
.estadistica-route__hoja {
	color: var(--color-text-maxcontrast);
}

.estadistica-route__hoja {
	display: block;
}

.estadistica-route__parte {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 1.5);
	padding: calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 3.5);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-container);
}

.estadistica-route__parte h3 {
	margin: 0;
	font-size: 16px;
}

.estadistica-route ul {
	margin: 0 0 4px;
	padding-inline-start: calc(var(--default-grid-baseline) * 4);
	list-style: disc;
}

.estadistica-route code {
	padding: 0 var(--default-grid-baseline);
	border-radius: var(--border-radius-small);
	background-color: var(--color-background-dark);
}
</style>
