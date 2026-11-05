<!--
	A Resumen card's histórico (docs/adr/0009): a chart with one line per year
	with figures, the most recent emphasised and the meta as a line, and the
	table under it, which holds every figure the chart draws. A year that does
	not compare, or waits for its official denominator, says so in words.
-->
<template>
	<div class="estadistica-historico">
		<template v-if="lines.length">
			<div class="estadistica-historico__canvas">
				<canvas ref="canvas"
					role="img"
					:aria-label="`Gráfico de la Meta ${meta.codigo} por año; sus valores están en la tabla.`" />
			</div>

			<p class="estadistica-historico__note">
				Porcentaje de cada mes y, entre paréntesis, su numerador y denominador. {{ metaLine.nombre }}.
			</p>
			<div class="estadistica-historico__scroll">
				<table class="estadistica-historico__table">
					<caption>Meta {{ meta.codigo }} por mes y año</caption>
					<thead>
						<tr>
							<th scope="col">
								Mes
							</th>
							<th v-for="year in lines" :key="year.anio" scope="col">
								{{ yearName(year.anio, current) }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="month in months" :key="month">
							<th scope="row">
								{{ monthName(`2000-${month}`).split(' ')[0] }}
							</th>
							<td v-for="year in lines" :key="year.anio">
								{{ cell(year, month) }}
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr>
							<th scope="row">
								Cierre
							</th>
							<td v-for="year in lines" :key="year.anio">
								{{ year.cierre === null ? '—' : percent(year.cierre) }}
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
		</template>

		<p v-for="year in notes" :key="year.anio" class="estadistica-historico__note">
			{{ year.anio }}: {{ year.estado }}.
		</p>
	</div>
</template>

<script>
import { draw, formatValue, historicoConfig, themeOf, yearName } from '../charts.js'
import { monthName } from '../query.js'

export default {
	name: 'EstadisticaResumenHistorico',

	props: {
		/** A GET resumen card (Resumen::card), with its historico. */
		meta: { type: Object, required: true },
	},

	computed: {
		/** The years drawn: those with figures to show. */
		lines() {
			return this.meta.historico.filter((year) => year.estado === null)
		},
		/** The years that show none, and why. */
		notes() {
			return this.meta.historico.filter((year) => year.estado !== null)
		},
		current() {
			return this.meta.historico.at(-1).anio
		},
		/** Every month any drawn year has, as 'MM': Serie P's are its cortes. */
		months() {
			return [...new Set(this.lines.flatMap((year) => year.meses.map((mes) => mes.mes.slice(5))))].sort()
		},
		metaLine() {
			const valor = this.meta.meta_comprometida ?? this.meta.meta_nacional
			return { nombre: `Meta ${this.meta.meta_comprometida === null ? 'nacional' : 'comprometida'} (${this.percent(valor)})`, valor }
		},
	},

	mounted() {
		if (this.lines.length === 0) {
			return
		}
		const style = window.getComputedStyle(this.$el)
		const theme = themeOf((name) => style.getPropertyValue(name))
		// A plain field, not reactive state: Chart.js owns it.
		this.chart = draw(this.$refs.canvas, historicoConfig(this.lines, this.months, this.current, this.metaLine, theme))
	},

	beforeUnmount() {
		this.chart?.destroy()
	},

	methods: {
		monthName,
		yearName,

		percent(value) {
			return `${formatValue(value)} %`
		},

		/** A month's figure: sin informar, never 0; — for a month that has not come yet. */
		cell(year, month) {
			const mes = year.meses.find((one) => one.mes.endsWith(`-${month}`))
			if (!mes) {
				return '—'
			}
			if (mes.porcentaje === null) {
				return 'sin informar'
			}
			return `${this.percent(mes.porcentaje)} (${formatValue(mes.numerador)} de ${formatValue(mes.denominador)})`
		},
	},
}
</script>

<style scoped>
.estadistica-historico {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.estadistica-historico__canvas {
	position: relative;
	height: 200px;
}

/* A narrow card scrolls its table, never the page. */
.estadistica-historico__scroll {
	min-width: 0;
	overflow-x: auto;
}

/* As wide as the card, so the caption wraps instead of widening the table. */
.estadistica-historico__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.estadistica-historico__table caption {
	white-space: normal;
	text-align: start;
	font-weight: bold;
}

.estadistica-historico__table th,
.estadistica-historico__table td {
	padding: 2px 6px;
	border-bottom: 1px solid var(--color-border);
	text-align: end;
}

.estadistica-historico__table th[scope='row'],
.estadistica-historico__table th:first-child {
	text-align: start;
}

.estadistica-historico__note {
	color: var(--color-text-maxcontrast);
}
</style>
