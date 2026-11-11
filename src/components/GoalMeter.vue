<!--
	A percentage against its target (#83): the fill is the figure, the mark
	the meta, both in the theme's own colours. Its text twin is the meter's
	value text, so a screen reader hears both.
-->
<template>
	<div class="estadistica-meter"
		role="meter"
		aria-valuemin="0"
		aria-valuemax="100"
		:aria-valuenow="clamp(value)"
		:aria-valuetext="`${percent(value)}; ${targetName} ${percent(target)}`"
		:aria-label="label">
		<span class="estadistica-meter__fill" :style="{ inlineSize: `${clamp(value)}%` }" />
		<span class="estadistica-meter__target" :style="{ insetInlineStart: `${clamp(target)}%` }">
			<span class="estadistica-meter__label" aria-hidden="true">meta {{ percent(target) }}</span>
		</span>
	</div>
</template>

<script>
import { formatValue } from '../charts.js'

export default {
	name: 'GoalMeter',

	props: {
		value: { type: Number, required: true },
		target: { type: Number, required: true },
		/** «meta comprometida» or «meta nacional». */
		targetName: { type: String, required: true },
		label: { type: String, required: true },
	},

	methods: {
		clamp: (number) => Math.max(0, Math.min(100, number)),
		percent: (number) => `${formatValue(number)} %`,
	},
}
</script>

<style scoped>
.estadistica-meter {
	position: relative;
	block-size: 8px;
	margin-block: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 5);
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element-light);
}

.estadistica-meter__fill {
	position: absolute;
	inset-block: 0;
	inset-inline-start: 0;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-primary-element);
}

.estadistica-meter__target {
	position: absolute;
	inset-block-start: -4px;
	inline-size: 2px;
	block-size: 16px;
	margin-inline-start: -1px;
	background-color: var(--color-main-text);
}

.estadistica-meter__label {
	position: absolute;
	inset-block-start: 18px;
	inset-inline-end: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	white-space: nowrap;
}
</style>
