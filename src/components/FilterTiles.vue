<!--
	The Resumen's counts, which are its filter (#83): each tile says how many
	metas hold that status and, pressed, shows only them. Exact counts, and a
	tile only for a status some meta holds.
-->
<template>
	<div class="estadistica-tiles" role="group" aria-label="Filtrar metas por estado">
		<NcButton v-for="filter in visible"
			:key="filter.id"
			class="estadistica-tiles__tile"
			alignment="start"
			wide
			:pressed="modelValue === filter.id"
			@click="$emit('update:modelValue', filter.id)">
			<span class="estadistica-tiles__count">{{ counts[filter.id] }}</span>
			<span class="estadistica-tiles__label">
				<NcIconSvgWrapper v-if="filter.kind" :path="ICONS[filter.kind]" :size="16" inline />
				{{ filter.label }}
			</span>
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { FILTERS, ICONS } from '../status.js'

export default {
	name: 'FilterTiles',

	components: { NcButton, NcIconSvgWrapper },

	props: {
		/** The pressed filter's id. */
		modelValue: { type: String, required: true },
		/** countByFilter()'s answer. */
		counts: { type: Object, required: true },
	},

	emits: ['update:modelValue'],

	data() {
		return { ICONS }
	},

	computed: {
		visible() {
			return FILTERS.filter((filter) => this.counts[filter.id])
		},
	},
}
</script>

<style scoped>
.estadistica-tiles {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.estadistica-tiles .estadistica-tiles__tile {
	height: auto;
	padding: calc(var(--default-grid-baseline) * 2.5) calc(var(--default-grid-baseline) * 3.5);
}

/* NcButton's text holds the count above its label. */
.estadistica-tiles__tile :deep(.button-vue__text) {
	display: grid;
	gap: 2px;
	white-space: normal;
}

.estadistica-tiles__count {
	font-size: 1.5rem;
	font-weight: bold;
	line-height: 1.2;
}

.estadistica-tiles__label {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
	font-weight: normal;
}
</style>
