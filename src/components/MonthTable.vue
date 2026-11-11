<!--
	The table twin of a meta's chart (#86): each month's percentage with its
	numerador and denominador, one column per year, and each year's cierre.
	It holds every figure the chart draws. Sin informar is said in words, never
	0; «—» is a month that has not come yet.
-->
<template>
	<div class="estadistica-month-table">
		<table>
			<caption>{{ caption }}</caption>
			<thead>
				<tr>
					<th scope="col">
						Mes
					</th>
					<th v-for="year in years" :key="year.anio" scope="col">
						{{ year.anio }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="month in months" :key="month">
					<th scope="row">
						{{ MONTHS[Number(month) - 1] }}
					</th>
					<td v-for="year in years" :key="year.anio">
						{{ cell(year, month) }}
					</td>
				</tr>
			</tbody>
			<tfoot v-if="years.some((year) => year.cierre !== null)">
				<tr>
					<th scope="row">
						Cierre
					</th>
					<td v-for="year in years" :key="year.anio">
						{{ year.cierre === null ? '—' : `${formatValue(year.cierre)} %` }}
					</td>
				</tr>
			</tfoot>
		</table>
	</div>
</template>

<script>
import { formatValue } from '../charts.js'
import { MONTHS } from '../query.js'

export default {
	name: 'MonthTable',

	props: {
		/** GET resumen's historico years, with figures: [{ anio, cierre, meses: [{ mes, numerador, denominador, porcentaje }] }]. */
		years: { type: Array, required: true },
		caption: { type: String, required: true },
	},

	data() {
		return { MONTHS }
	},

	computed: {
		/** Every month any year has, as 'MM': Serie P's are its cortes. */
		months() {
			return [...new Set(this.years.flatMap((year) => year.meses.map((mes) => mes.mes.slice(5))))].sort()
		},
	},

	methods: {
		formatValue,

		/** A month's figure: «58,3 % (7 de 12)», sin informar, or «—» for a month that has not come yet. */
		cell(year, month) {
			const mes = year.meses.find((one) => one.mes.endsWith(`-${month}`))
			if (!mes) {
				return '—'
			}
			if (mes.porcentaje === null) {
				return 'sin informar'
			}
			return `${formatValue(mes.porcentaje)} % (${formatValue(mes.numerador)} de ${formatValue(mes.denominador)})`
		},
	},
}
</script>

<style scoped>
/* A narrow sidebar scrolls the table, never the page. */
.estadistica-month-table {
	min-width: 0;
	overflow-x: auto;
}

.estadistica-month-table table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.estadistica-month-table caption {
	white-space: normal;
	text-align: start;
	font-weight: bold;
}

.estadistica-month-table th,
.estadistica-month-table td {
	padding: 2px 6px;
	border-bottom: 1px solid var(--color-border);
	text-align: end;
}

.estadistica-month-table th[scope='row'],
.estadistica-month-table th:first-child {
	text-align: start;
}
</style>
