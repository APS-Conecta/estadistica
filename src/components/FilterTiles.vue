<!--
	The Resumen's counts, which are its filter (#83): each tile says how many
	metas hold that status and, pressed, shows only them. Exact counts, and a
	tile only for a status some meta holds.
-->
<template>
	<div class="estadistica-tiles" role="group" aria-label="Filtrar metas por estado">
		<button v-for="filter in visible"
			:key="filter.id"
			type="button"
			class="estadistica-tiles__tile"
			:aria-pressed="String(modelValue === filter.id)"
			@click="$emit('update:modelValue', filter.id)">
			<span class="estadistica-tiles__count">{{ counts[filter.id] }}</span>
			<span class="estadistica-tiles__label">
				<NcIconSvgWrapper v-if="filter.kind" :path="ICONS[filter.kind]" :size="16" inline />
				{{ filter.label }}
			</span>
		</button>
	</div>
</template>

<script>
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { FILTERS, ICONS } from '../status.js'

export default {
	name: 'FilterTiles',

	components: { NcIconSvgWrapper },

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

.estadistica-tiles__tile {
	display: grid;
	gap: 2px;
	height: auto;
	margin: 0;
	padding: calc(var(--default-grid-baseline) * 2.5) calc(var(--default-grid-baseline) * 3.5);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	text-align: start;
	cursor: pointer;
}

.estadistica-tiles__tile:hover {
	background-color: var(--color-background-hover);
}

.estadistica-tiles__tile:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.estadistica-tiles__tile[aria-pressed='true'] {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
	background-color: var(--color-primary-element-light);
}

.estadistica-tiles__count {
	font-size: 22px;
	font-weight: bold;
	line-height: 1.2;
}

.estadistica-tiles__label {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
	color: var(--color-text-maxcontrast);
}
</style>
