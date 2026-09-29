<!--
	One consulta's answer: the charts, the side-by-side table under them, and
	what was counted and where it came from. It draws what it is given and asks
	nothing, so the builder and a tablero card (Slice 7) render an answer the
	same way.
-->
<template>
	<section class="estadistica-answer" aria-live="polite">
		<p class="estadistica-answer__about">
			{{ sourceText }}
		</p>

		<NcNoteCard v-if="answer.fuente.estado === 'incompleto'" type="warning">
			Algún año de esta fuente está incompleto: el DEIS lo publicó cortado y se cargaron solo sus
			filas enteras. Sus cifras pueden quedar bajo lo real.
		</NcNoteCard>

		<NcEmptyContent v-if="empty"
			name="No hay cifras para esta consulta"
			description="Nadie informó esas prestaciones y columnas en el periodo elegido." />

		<template v-else>
			<p v-if="tipo !== 'tabla' && view.tooMany" class="estadistica-answer__about">
				Hay más de {{ MAX_PANELS }} valores de «{{ secondName }}» para dibujar uno por uno: se muestran en la tabla.
			</p>

			<div v-if="tipo !== 'tabla'" class="estadistica-answer__panels">
				<figure v-for="(panel, index) in view.panels" :key="index" class="estadistica-answer__panel">
					<figcaption v-if="panel.titulo">
						{{ panel.titulo }}
					</figcaption>
					<div class="estadistica-answer__canvas">
						<canvas :ref="(el) => setCanvas(index, el)"
							role="img"
							:aria-label="`Gráfico${panel.titulo ? ` de ${panel.titulo}` : ''}; sus valores están en la tabla.`" />
					</div>
				</figure>
			</div>

			<table class="estadistica-answer__table">
				<caption>{{ metricText }}</caption>
				<thead>
					<tr>
						<th v-for="columna in table.columnas" :key="columna" scope="col">
							{{ columna }}
						</th>
						<th v-for="serie in table.series" :key="serie.id" scope="col">
							<span class="estadistica-answer__swatch" :style="{ backgroundColor: colors[serie.id] }" />
							{{ serie.nombre }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(fila, index) in table.filas" :key="index">
						<th v-for="(valor, i) in fila.grupo" :key="i" scope="row">
							{{ valor }}
						</th>
						<td v-for="(celda, i) in fila.celdas" :key="table.series[i].id">
							<template v-if="celda">
								{{ formatValue(celda.valor) }}
								<span v-if="table.series[i].id !== 'local'" class="estadistica-answer__who">
									({{ formatValue(celda.informantes) }})
								</span>
							</template>
							<template v-else>
								—
							</template>
						</td>
					</tr>
				</tbody>
			</table>
		</template>

		<p v-if="localText" class="estadistica-answer__about">
			{{ localText }}
		</p>
		<p v-if="peersText" class="estadistica-answer__about">
			{{ peersText }}
		</p>

		<details class="estadistica-answer__about">
			<summary>Qué se contó: {{ countedText }}</summary>
			<ul>
				<li v-for="codigo in answer.etiquetas.codigos" :key="codigo.codigo">
					{{ codigo.prestacion }} ({{ codigo.codigo }}, {{ codigo.hoja }}{{ codigo.seccion ? `, ${codigo.seccion}` : '' }}):
					{{ Object.entries(codigo.columnas).map(([col, label]) => `${label} (${col})`).join('; ') }}{{ codigo.nota ? ` — ${codigo.nota}` : '' }}
				</li>
			</ul>
		</details>
	</section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { chartConfig, draw, formatValue, MAX_PANELS, panelsOf, tableOf, themeOf } from '../charts.js'
import { dimensionName, monthName } from '../query.js'

export default {
	name: 'QueryVisualization',

	components: { NcEmptyContent, NcNoteCard },

	props: {
		/** POST consultas' answer: series, etiquetas, fuente (QueryExecutor::response). */
		answer: { type: Object, required: true },
		/** The spec that was asked, for its agrupar and metrica. */
		spec: { type: Object, required: true },
		/** barra, linea or tabla (query.js CHART_TYPES). */
		tipo: { type: String, default: 'barra' },
	},

	data() {
		return { colors: {}, MAX_PANELS }
	},

	computed: {
		view() {
			return panelsOf(this.answer, this.spec)
		},
		table() {
			return tableOf(this.answer, this.spec)
		},
		empty() {
			return this.table.filas.length === 0
		},
		secondName() {
			return dimensionName(this.spec.agrupar[1])
		},
		sourceText() {
			const { nombre, origen, fecha_corte: corte } = this.answer.fuente
			return `Fuente: ${nombre}, ${origen}${corte ? `, con cifras hasta ${monthName(corte)}` : ''}.`
		},
		metricText() {
			return this.spec.metrica === 'promedio_establecimiento'
				? 'Promedio por establecimiento: la suma dividida por los establecimientos que informaron, entre paréntesis.'
				: 'Suma de lo informado; entre paréntesis, los establecimientos que informaron.'
		},
		localText() {
			const local = this.answer.etiquetas.local
			return local ? `Su establecimiento es el de código DEIS ${local}, el que configuró el instalador.` : ''
		},
		peersText() {
			const pares = this.answer.etiquetas.pares
			if (!pares) {
				return ''
			}
			const traits = Object.entries(pares).map(([trait, value]) => `${dimensionName(trait).toLowerCase()} «${value}»`)
			return `Pares: los establecimientos con ${traits.join(' y ')}.`
		},
		countedText() {
			const n = this.answer.etiquetas.codigos.length
			return n === 1 ? '1 prestación' : `${formatValue(n)} prestaciones`
		},
	},

	watch: {
		answer: { handler: 'draw', flush: 'post' },
		tipo: { handler: 'draw', flush: 'post' },
	},

	created() {
		// Plain fields, not reactive state: Chart.js owns these objects.
		this.canvases = []
		this.charts = []
	},

	mounted() {
		this.draw()
	},

	beforeUnmount() {
		this.destroy()
	},

	methods: {
		formatValue,

		setCanvas(index, el) {
			this.canvases[index] = el
		},

		destroy() {
			this.charts.forEach((chart) => chart.destroy())
			this.charts = []
		},

		/**
		 * The theme is read where the chart sits, at every draw, so a theme
		 * changed since the last consulta is picked up by the next one.
		 */
		draw() {
			this.destroy()
			const style = window.getComputedStyle(this.$el)
			const theme = themeOf((name) => style.getPropertyValue(name))
			this.colors = theme.colors
			if (this.tipo === 'tabla' || this.empty) {
				return
			}
			this.view.panels.forEach((panel, index) => {
				const canvas = this.canvases[index]
				if (canvas) {
					this.charts.push(draw(canvas, chartConfig(panel, this.tipo, this.view.max, theme)))
				}
			})
		},
	},
}
</script>

<style scoped>
.estadistica-answer {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.estadistica-answer__about {
	color: var(--color-text-maxcontrast);
}

.estadistica-answer__panels {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 16px;
}

.estadistica-answer__panel {
	margin: 0;
}

.estadistica-answer__canvas {
	position: relative;
	height: 280px;
}

.estadistica-answer__table {
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.estadistica-answer__table caption {
	caption-side: bottom;
	text-align: start;
	padding-top: 4px;
	color: var(--color-text-maxcontrast);
}

.estadistica-answer__table th,
.estadistica-answer__table td {
	padding: 4px 16px 4px 0;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.estadistica-answer__table td {
	text-align: end;
}

.estadistica-answer__swatch {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-inline-end: 4px;
}

.estadistica-answer__who {
	color: var(--color-text-maxcontrast);
}
</style>
