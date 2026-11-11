<!--
	A meta's detail beside the Resumen (docs/adr/0009, #86), in the app
	sidebar as Nextcloud lays one out. «Evolución»: its figure and status, a
	chart with one line per comparable year, the newest emphasised and the
	target as a threshold, and the table twin; a year that does not compare
	says so in words. «Origen»: where the figure comes from, the newest year's
	ruta and the referencia. The Resumen compares a meta only with the
	establishment's own past years, so there is no country or pares here.
	Escape closes it.
-->
<template>
	<Teleport to="#content-vue">
		<NcAppSidebar ref="sidebar"
			v-model:active="active"
			:name="`Meta ${meta.codigo} ${anio}`"
			:subname="meta.nombre"
			@close="$emit('close')"
			@keydown.esc="$emit('close')">
			<NcAppSidebarTab id="evolucion" name="Evolución" :order="1">
				<template #icon>
					<NcIconSvgWrapper :path="mdiChartLine" />
				</template>
				<div class="estadistica-detail">
					<p v-if="judged" class="estadistica-detail__cifra">
						<span class="estadistica-detail__porcentaje">{{ formatValue(meta.porcentaje) }} %</span> <span v-if="!meta.manual" class="estadistica-detail__cuenta">{{ formatValue(meta.numerador) }} de {{ formatValue(meta.denominador) }}</span>
					</p>
					<StatusBadge :status="status" />

					<template v-if="years.length">
						<MetricChart :series="series"
							:target="target"
							:target-name="`${targetName[0].toUpperCase()}${targetName.slice(1)}`"
							:label="`Meta ${meta.codigo} por mes y año; sus valores están en la tabla.`" />
						<MonthTable :years="years"
							:caption="`Meta ${meta.codigo} por mes y año. Porcentaje de cada mes y, entre paréntesis, su numerador y denominador.`" />
					</template>
					<NcEmptyContent v-else name="Todavía no hay cifras" :description="status.label">
						<template #icon>
							<NcIconSvgWrapper :path="mdiChartLine" />
						</template>
					</NcEmptyContent>

					<p v-for="year in notes" :key="year.anio" class="estadistica-detail__hint">
						{{ year.anio }}: {{ year.estado }}.
					</p>
				</div>
			</NcAppSidebarTab>

			<NcAppSidebarTab id="origen" name="Origen" :order="2">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDatabaseOutline" />
				</template>
				<RouteList :ruta="meta.historico.at(-1)?.ruta ?? null"
					:manual="meta.manual"
					:origen="meta.origen ? originText(meta.origen, meta.fecha_corte) : ''"
					:referencia="referencia" />
			</NcAppSidebarTab>
		</NcAppSidebar>
	</Teleport>
</template>

<script>
import NcAppSidebar from '@nextcloud/vue/components/NcAppSidebar'
import NcAppSidebarTab from '@nextcloud/vue/components/NcAppSidebarTab'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { formatValue } from '../charts.js'
import { mdiChartLine, mdiDatabaseOutline } from '../icons.js'
import { originText } from '../query.js'
import { statusOf, targetNameOf, targetOf } from '../status.js'
import MetricChart from './MetricChart.vue'
import MonthTable from './MonthTable.vue'
import RouteList from './RouteList.vue'
import StatusBadge from './StatusBadge.vue'

export default {
	name: 'DetailSidebar',

	components: { MetricChart, MonthTable, NcAppSidebar, NcAppSidebarTab, NcEmptyContent, NcIconSvgWrapper, RouteList, StatusBadge },

	props: {
		/** One of GET resumen's metas, with its historico. */
		meta: { type: Object, required: true },
		/** GET resumen's anio and referencia. */
		anio: { type: Number, required: true },
		referencia: { type: String, required: true },
		/** The tab it opens on: 'evolucion' or 'origen'. */
		tab: { type: String, default: 'evolucion' },
	},

	emits: ['close'],

	data() {
		return { active: this.tab, mdiChartLine, mdiDatabaseOutline }
	},

	computed: {
		status() {
			return statusOf(this.meta)
		},
		/** Whether the meta has a figure to judge: it cumple or it does not. */
		judged() {
			return this.status.kind === 'success' || this.status.kind === 'error'
		},
		target() {
			return targetOf(this.meta)
		},
		targetName() {
			return targetNameOf(this.meta)
		},
		/** The comparable years, oldest first: those with figures to show. */
		years() {
			return this.meta.historico.filter((year) => year.estado === null)
		},
		/** The years that show none, and why. */
		notes() {
			return this.meta.historico.filter((year) => year.estado !== null)
		},
		/** One line per comparable year; the newest is the point, the earlier ones are context. */
		series() {
			const newest = this.meta.historico.at(-1)?.anio
			return this.years.map((year) => ({
				id: String(year.anio),
				name: year.anio === newest ? `${year.anio} (más reciente)` : String(year.anio),
				short: String(year.anio),
				emphasis: year.anio === newest,
				points: year.meses.map((mes) => ({
					i: Number(mes.mes.slice(5)) - 1,
					v: mes.porcentaje,
					detail: mes.porcentaje === null ? null : `${formatValue(mes.numerador)} de ${formatValue(mes.denominador)}`,
				})),
			}))
		},
	},

	// The focus moves into the panel at once, whatever a click did with it (a
	// button clicked in Firefox or Safari may not take it), so Escape reaches it.
	mounted() {
		this.$refs.sidebar.focus()
	},

	methods: {
		formatValue,
		originText,
	},
}
</script>

<style scoped>
.estadistica-detail {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 4);
	padding-bottom: calc(var(--default-grid-baseline) * 6);
}

.estadistica-detail p {
	margin: 0;
}

.estadistica-detail__cifra {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: calc(var(--default-grid-baseline) * 2.5);
	font-variant-numeric: tabular-nums;
}

.estadistica-detail__porcentaje {
	font-size: 2em;
	font-weight: bold;
	line-height: 1.2;
}

.estadistica-detail__cuenta,
.estadistica-detail__hint {
	color: var(--color-text-maxcontrast);
}
</style>
