<!--
	A meta's year at a glance (#83): its months' percentages, the newest
	marked, against its target as a dashed line, in the theme's own colours.
	Decorative: its row says the last figure and the status in words, so
	assistive tech skips it.
-->
<template>
	<svg class="estadistica-sparkline"
		:viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
		:width="WIDTH"
		:height="HEIGHT"
		aria-hidden="true">
		<line class="estadistica-sparkline__target"
			x1="0"
			:x2="WIDTH"
			:y1="y(target)"
			:y2="y(target)" />
		<path v-if="points.length > 1" class="estadistica-sparkline__line" :d="path" />
		<circle v-if="last"
			class="estadistica-sparkline__dot"
			:cx="x(last.month)"
			:cy="y(last.value)"
			r="3" />
	</svg>
</template>

<script>
const WIDTH = 120
const HEIGHT = 28

export default {
	name: 'GoalSparkline',

	props: {
		/** The months with a percentage: [{ month: 0–11, value: percent }]. */
		points: { type: Array, required: true },
		target: { type: Number, required: true },
	},

	data() {
		return { WIDTH, HEIGHT }
	},

	computed: {
		last() {
			return this.points.at(-1) ?? null
		},
		path() {
			return this.points.map((point, index) => `${index ? 'L' : 'M'}${this.x(point.month)} ${this.y(point.value)}`).join(' ')
		},
	},

	methods: {
		x: (month) => 2 + (month / 11) * (WIDTH - 4),
		y: (value) => HEIGHT - 3 - (Math.max(0, Math.min(value, 100)) / 100) * (HEIGHT - 6),
	},
}
</script>

<style scoped>
.estadistica-sparkline__target {
	stroke: var(--color-border-dark);
	stroke-dasharray: 3 3;
}

.estadistica-sparkline__line {
	fill: none;
	stroke: var(--color-primary-element);
	stroke-width: 2;
	stroke-linecap: round;
	stroke-linejoin: round;
}

.estadistica-sparkline__dot {
	fill: var(--color-primary-element);
}
</style>
