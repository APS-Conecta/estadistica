<!--
	A percentage over the months of a year (#86): one line per series and the
	target as a labelled dashed threshold. The series that is the point wears
	the theme's primary; the others are context in the muted text colour, each
	with a dash of its own, and the legend names every line. It draws at its
	container's width, so its text keeps its 11 px anywhere. Hover, focus and
	the arrow keys read one month above the plot, where it is announced and
	never clipped; the table the caller puts beside it holds every value.
	Colours come from Nextcloud's variables only, so the theme reaches it.
-->
<template>
	<figure class="estadistica-chart">
		<ul class="estadistica-chart__legend">
			<li v-for="line in drawn" :key="line.id">
				<svg class="estadistica-chart__key"
					:class="line.className"
					viewBox="0 0 18 4"
					width="18"
					height="4"
					aria-hidden="true">
					<line x1="0"
						x2="18"
						y1="2"
						y2="2"
						:stroke-dasharray="line.dash" />
				</svg>
				{{ line.name }}
			</li>
			<li>
				<svg class="estadistica-chart__key estadistica-chart__key--target"
					viewBox="0 0 18 4"
					width="18"
					height="4"
					aria-hidden="true">
					<line x1="0"
						x2="18"
						y1="2"
						y2="2" />
				</svg>
				{{ targetName }} ({{ percent(target) }})
			</li>
		</ul>

		<p class="estadistica-chart__readout" aria-live="polite">
			<template v-if="active !== null">
				<strong>{{ MONTHS[active] }}:</strong>
				<!-- A space before each value, which the template would trim, keeps the readings words apart when announced. -->
				<span v-for="row in readout" :key="row.id">{{ ' ' }}<strong>{{ row.value }}</strong> {{ row.name }}</span>
			</template>
			<span v-else class="estadistica-chart__hint">
				Pase el cursor sobre el gráfico, o enfóquelo y use las flechas, para leer un mes.
			</span>
		</p>

		<div ref="plot" class="estadistica-chart__plot">
			<svg :viewBox="`0 0 ${width} ${HEIGHT}`"
				:width="width"
				:height="HEIGHT"
				role="img"
				:aria-label="label"
				tabindex="0"
				@pointermove="pointAt"
				@pointerleave="active = null"
				@focus="active = lastIndex"
				@blur="active = null"
				@keydown="step">
				<g class="estadistica-chart__grid">
					<template v-for="tick in ticks" :key="tick">
						<line :x1="LEFT"
							:x2="LEFT + innerWidth"
							:y1="y(tick)"
							:y2="y(tick)" />
						<text :x="LEFT - 6" :y="y(tick) + 4" text-anchor="end">{{ tick }} %</text>
					</template>
					<template v-for="(name, index) in MONTHS" :key="name">
						<text v-if="index % monthStep === 0 || index === 11"
							:x="x(index)"
							:y="HEIGHT - 6"
							text-anchor="middle">{{ name.slice(0, 3) }}</text>
					</template>
				</g>
				<line class="estadistica-chart__target"
					:x1="LEFT"
					:x2="LEFT + innerWidth"
					:y1="y(target)"
					:y2="y(target)" />
				<text class="estadistica-chart__label" :x="LEFT + 4" :y="y(target) - 5">{{ targetName }} {{ percent(target) }}</text>
				<g v-for="line in drawn" :key="line.id" :class="line.className">
					<path v-for="(segment, index) in line.segments"
						:key="index"
						:d="segment"
						:stroke-dasharray="line.dash" />
					<circle v-for="point in line.points"
						:key="point.i"
						:cx="x(point.i)"
						:cy="y(point.v)"
						:r="line.emphasis ? 4 : 3.5" />
				</g>
				<text v-for="end in endLabels"
					:key="end.id"
					class="estadistica-chart__label"
					:class="{ 'estadistica-chart__label--emphasis': end.emphasis }"
					:x="end.x + 8"
					:y="end.y + 4">{{ end.text }}</text>
				<line v-if="active !== null"
					class="estadistica-chart__cross"
					:x1="x(active)"
					:x2="x(active)"
					:y1="TOP"
					:y2="TOP + innerHeight" />
			</svg>
		</div>
	</figure>
</template>

<script>
import { ref } from 'vue'
import { formatValue } from '../charts.js'
import { useWidth } from '../composables/useWidth.js'
import { MONTHS } from '../query.js'

const HEIGHT = 220
const LEFT = 36
const TOP = 12
const BOTTOM = 26

/** Each context line's dash, in turn, so two context lines differ by more than their order. */
const DASHES = ['6 3', '2 3', '10 3 2 3']

export default {
	name: 'MetricChart',

	props: {
		/** The lines, in legend order: [{ id, name, short, emphasis, points: [{ i: month 0–11, v: percentage or null, detail: text }] }]. */
		series: { type: Array, required: true },
		/** The threshold, as a percentage, and its name. */
		target: { type: Number, required: true },
		targetName: { type: String, required: true },
		/** What the chart shows, for a screen reader; it says the values are in the table. */
		label: { type: String, required: true },
	},

	setup() {
		const plot = ref(null)
		return { plot, width: useWidth(plot) }
	},

	data() {
		return { MONTHS, HEIGHT, LEFT, TOP, active: null }
	},

	computed: {
		right() {
			return this.width < 420 ? 72 : 96
		},
		innerWidth() {
			return this.width - LEFT - this.right
		},
		innerHeight() {
			return HEIGHT - TOP - BOTTOM
		},
		monthStep() {
			return this.width < 420 ? 3 : 2
		},
		maxValue() {
			const values = this.series.flatMap((line) => line.points.map((point) => point.v)).filter((v) => v !== null)
			return Math.max(100, Math.ceil(Math.max(this.target, ...values) / 25) * 25)
		},
		ticks() {
			return Array.from({ length: this.maxValue / 25 + 1 }, (_, i) => i * 25)
		},
		/** Each line with its points that have a value, its runs between gaps as paths (a lone point is a dot), its class and dash. */
		drawn() {
			let context = 0
			return this.series.map((line) => {
				const runs = [[]]
				for (const point of line.points) {
					if (point.v === null) {
						runs.push([])
					} else {
						runs.at(-1).push(point)
					}
				}
				return {
					...line,
					className: line.emphasis ? 'estadistica-chart__line--emphasis' : 'estadistica-chart__line--context',
					dash: line.emphasis ? null : DASHES[context++ % DASHES.length],
					points: line.points.filter((point) => point.v !== null),
					segments: runs.filter((run) => run.length > 1)
						.map((run) => run.map((point, k) => `${k ? 'L' : 'M'}${this.x(point.i).toFixed(1)} ${this.y(point.v).toFixed(1)}`).join(' ')),
				}
			})
		},
		/** Each line named at its last point, unless two would collide: then the legend and the readout carry them. */
		endLabels() {
			const ends = this.drawn.filter((line) => line.points.length).map((line) => {
				const last = line.points.at(-1)
				return { id: line.id, emphasis: line.emphasis, x: this.x(last.i), y: this.y(last.v), text: `${line.short ?? line.name} ${this.percent(last.v)}` }
			})
			const collide = ends.some((a, i) => ends.some((b, j) => i < j && Math.abs(a.y - b.y) < 14))
			return collide ? ends.filter((end) => end.emphasis) : ends
		},
		/** The months any line has, in order: where the crosshair stops. */
		indexes() {
			return [...new Set(this.series.flatMap((line) => line.points.map((point) => point.i)))].sort((a, b) => a - b)
		},
		lastIndex() {
			return this.indexes.at(-1) ?? null
		},
		/** The active month's value of each line, «—» where it has none, then the target. */
		readout() {
			return [
				...this.series.map((line) => {
					const point = line.points.find((candidate) => candidate.i === this.active)
					return { id: line.id, value: this.percent(point?.v), name: [line.name, point?.detail].filter(Boolean).join(' · ') }
				}),
				{ id: 'target', value: this.percent(this.target), name: this.targetName },
			]
		},
	},

	methods: {
		x(index) {
			return LEFT + (index / 11) * this.innerWidth
		},

		y(value) {
			return TOP + this.innerHeight - (value / this.maxValue) * this.innerHeight
		},

		percent(value) {
			return value === null || value === undefined ? '—' : `${formatValue(value)} %`
		},

		/** The month nearest the pointer. */
		pointAt(event) {
			const box = event.currentTarget.getBoundingClientRect()
			const at = ((event.clientX - box.left) / box.width) * this.width
			this.active = this.indexes.reduce((best, i) => (Math.abs(this.x(i) - at) < Math.abs(this.x(best) - at) ? i : best), this.indexes[0] ?? null)
		},

		/** The arrow keys move one month, and stop at the first and the last. */
		step(event) {
			const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key]
			if (!move || this.indexes.length === 0) {
				return
			}
			event.preventDefault()
			const at = this.indexes.indexOf(this.active ?? this.lastIndex)
			this.active = this.indexes[Math.max(0, Math.min(this.indexes.length - 1, at + move))]
		},
	},
}
</script>

<style scoped>
.estadistica-chart {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
	margin: 0;
}

.estadistica-chart__legend {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 4);
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.estadistica-chart__legend li {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
}

.estadistica-chart__key line {
	stroke: currentColor;
	stroke-width: 2;
}

.estadistica-chart__key--target line {
	stroke: var(--color-main-text);
	stroke-width: 1;
	stroke-dasharray: 4 4;
}

.estadistica-chart__readout {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 3);
	min-height: 24px;
	margin: 0;
	font-size: var(--font-size-small, 13px);
	font-variant-numeric: tabular-nums;
}

.estadistica-chart__hint {
	color: var(--color-text-maxcontrast);
}

.estadistica-chart__plot svg {
	display: block;
	max-width: 100%;
	overflow: visible;
	border-radius: var(--border-radius-small);
}

.estadistica-chart__plot svg:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 4px;
}

.estadistica-chart__grid line {
	stroke: var(--color-border);
	stroke-width: 1;
}

.estadistica-chart__grid text,
.estadistica-chart__label {
	font-size: 11px;
	font-variant-numeric: tabular-nums;
}

.estadistica-chart__grid text {
	fill: var(--color-text-maxcontrast);
}

.estadistica-chart__label {
	fill: var(--color-main-text);
}

.estadistica-chart__label--emphasis {
	font-weight: bold;
}

.estadistica-chart__target {
	stroke: var(--color-main-text);
	stroke-width: 1;
	stroke-dasharray: 4 4;
}

.estadistica-chart__cross {
	stroke: var(--color-border-dark);
	stroke-width: 1;
}

.estadistica-chart__line--emphasis {
	color: var(--color-primary-element);
}

.estadistica-chart__line--context {
	color: var(--color-text-maxcontrast);
}

.estadistica-chart__plot path {
	fill: none;
	stroke: currentColor;
	stroke-width: 2;
	stroke-linejoin: round;
	stroke-linecap: round;
}

.estadistica-chart__plot circle {
	fill: currentColor;
	stroke: var(--color-main-background);
	stroke-width: 2;
}
</style>
