<!--
	One or more series over a row of categories (#86, #91): the months of a
	year by default, or the labels given. Lines, or columns side by side. The
	series that is the point wears the theme's primary; the others are context
	in the muted text colour, each with a dash of its own (a column: the first
	solid, the next hollow with its dash), and the legend always names every
	series. A target is an optional labelled dashed threshold. It draws at its
	container's width, so its text keeps its 11 px anywhere. A line chart is
	read by hover, focus and the arrow keys; each column is a keyboard stop
	that says its value. The readout sits above the plot, where it is
	announced and never clipped; the table the caller puts beside it holds
	every value. Colours come from Nextcloud's variables only, so the theme
	reaches it.
-->
<template>
	<figure class="estadistica-chart">
		<ul class="estadistica-chart__legend">
			<li v-for="line in drawn" :key="line.id">
				<svg v-if="columns"
					class="estadistica-chart__key"
					:class="line.className"
					viewBox="0 0 10 10"
					width="10"
					height="10"
					aria-hidden="true">
					<rect class="estadistica-chart__column"
						:class="{ 'estadistica-chart__column--hollow': line.hollow }"
						x="1"
						y="1"
						width="8"
						height="8"
						rx="2"
						:stroke-dasharray="line.hollow ? line.dash : null" />
				</svg>
				<svg v-else
					class="estadistica-chart__key"
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
			<li v-if="target !== null">
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
				{{ targetName }} ({{ formatted(target) }})
			</li>
		</ul>

		<p class="estadistica-chart__readout" aria-live="polite">
			<template v-if="active !== null">
				<strong>{{ categories[active] }}:</strong>
				<!-- A space before each value, which the template would trim, keeps the readings words apart when announced. -->
				<span v-for="row in readout" :key="row.id">{{ ' ' }}<strong>{{ row.value }}</strong> {{ row.name }}</span>
			</template>
			<span v-else class="estadistica-chart__hint">
				{{ hint }}
			</span>
		</p>

		<div ref="plot" class="estadistica-chart__plot">
			<svg :viewBox="`0 0 ${width} ${HEIGHT}`"
				:width="width"
				:height="HEIGHT"
				:role="columns ? 'group' : 'img'"
				:aria-label="label"
				:tabindex="columns ? null : 0"
				@pointermove="pointAt"
				@pointerleave="active = null"
				@focus="active = lastIndex"
				@blur="active = null"
				@keydown="step">
				<g class="estadistica-chart__grid" aria-hidden="true">
					<template v-for="tick in ticks" :key="tick">
						<line :x1="left"
							:x2="left + innerWidth"
							:y1="y(tick)"
							:y2="y(tick)" />
						<text :x="left - 6" :y="y(tick) + 4" text-anchor="end">{{ formatted(tick) }}</text>
					</template>
					<text v-for="name in axis"
						:key="name.i"
						:x="name.x"
						:y="HEIGHT - 6"
						text-anchor="middle">{{ name.text }}</text>
				</g>
				<template v-if="target !== null">
					<line class="estadistica-chart__target"
						:x1="left"
						:x2="left + innerWidth"
						:y1="y(target)"
						:y2="y(target)" />
					<text class="estadistica-chart__label" :x="left + 4" :y="y(target) - 5">{{ targetName }} {{ formatted(target) }}</text>
				</template>
				<template v-if="columns">
					<g v-for="group in groups" :key="group.i">
						<path v-for="column in group.columns"
							:key="column.id"
							class="estadistica-chart__column"
							:class="[column.className, { 'estadistica-chart__column--hollow': column.hollow }]"
							:d="column.d"
							:stroke-dasharray="column.hollow ? column.dash : null"
							tabindex="0"
							role="img"
							:aria-label="column.said"
							@focus="active = group.i"
							@blur="active = null" />
					</g>
				</template>
				<template v-else>
					<g v-for="line in drawn" :key="line.id" :class="line.className">
						<path v-for="(run, index) in line.runs"
							:key="index"
							:d="path(run)"
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
						:x="x(end.i) + 8"
						:y="end.y + 4">{{ end.text }}</text>
					<line v-if="active !== null"
						class="estadistica-chart__cross"
						:x1="x(active)"
						:x2="x(active)"
						:y1="TOP"
						:y2="TOP + innerHeight" />
				</template>
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
/** An 11 px character's width, near enough to lay text out by. */
const CHAR = 6.5
/** Past this many characters a category is cut on the axis; the readout says it whole. */
const AXIS_CHARS = 16

/** Each context line's dash, in turn, so two context lines differ by more than their order. */
const DASHES = ['6 3', '2 3', '10 3 2 3']

const textWidth = (text) => text.length * CHAR

/** A step of 1, 2, 2.5 or 5 times a power of ten, about a quarter of `highest`; 2.5 only where its ticks keep one decimal. */
function niceStep(highest) {
	const raw = highest / 4
	if (!(raw > 0)) {
		return 1
	}
	const magnitude = 10 ** Math.floor(Math.log10(raw))
	return [1, 2, ...(magnitude >= 1 ? [2.5] : []), 5, 10].map((k) => k * magnitude).find((k) => k >= raw)
}

export default {
	name: 'MetricChart',

	props: {
		/** The series, in legend order: [{ id, name, short, emphasis, points: [{ i: category index, v: value or null, detail: text }] }]. */
		series: { type: Array, required: true },
		/** The categories' names, in order; the months of a year when not given. */
		labels: { type: Array, default: null },
		/** Columns side by side instead of lines. */
		columns: { type: Boolean, default: false },
		/** The scale reaches at least this: a percentage's whole, or the highest value of panels that share one scale. */
		max: { type: Number, default: 100 },
		/** Written after each value: a percentage's «%», or nothing for a figure. */
		unit: { type: String, default: '%' },
		/** An optional threshold and its name. */
		target: { type: Number, default: null },
		targetName: { type: String, default: '' },
		/** What the chart shows, for a screen reader; it says the values are in the table. */
		label: { type: String, required: true },
	},

	setup() {
		const plot = ref(null)
		return { plot, width: useWidth(plot) }
	},

	data() {
		return { HEIGHT, TOP, active: null }
	},

	computed: {
		categories() {
			return this.labels ?? MONTHS
		},
		hint() {
			return this.columns
				? 'Pase el cursor sobre una barra, o recórralas con el tabulador, para leer su valor.'
				: `Pase el cursor sobre el gráfico, o enfóquelo y use las flechas, para leer ${this.labels ? 'cada punto' : 'un mes'}.`
		},
		scale() {
			const values = this.series.flatMap((line) => line.points.map((point) => point.v)).filter((v) => v !== null)
			const highest = Math.max(this.max, this.target ?? 0, ...values)
			const step = niceStep(highest)
			return { step, top: Math.max(step, Math.ceil(highest / step) * step) }
		},
		ticks() {
			return Array.from({ length: Math.round(this.scale.top / this.scale.step) + 1 }, (_, i) => i * this.scale.step)
		},
		/** Room for the widest tick, set at its end 6 px left of the plot. */
		left() {
			return Math.max(LEFT, Math.ceil(Math.max(...this.ticks.map((tick) => textWidth(this.formatted(tick))))) + 6)
		},
		/** Room for the names at the lines' ends. */
		right() {
			return Math.max(12, ...this.endLabels.map((end) => textWidth(end.text) + 8))
		},
		innerWidth() {
			return this.width - this.left - this.right
		},
		innerHeight() {
			return HEIGHT - TOP - BOTTOM
		},
		/** The categories the axis has room for: every step-th, cut short, each kept inside the chart. */
		axis() {
			const texts = this.labels
				? this.labels.map((name) => (name.length > AXIS_CHARS ? `${name.slice(0, AXIS_CHARS - 1)}…` : name))
				: MONTHS.map((name) => name.slice(0, 3))
			const room = this.innerWidth / Math.max(1, this.columns ? texts.length : texts.length - 1)
			const step = Math.max(1, Math.ceil((Math.max(...texts.map(textWidth)) + 12) / room))
			return texts.flatMap((text, i) => {
				const half = textWidth(text) / 2
				return i % step ? [] : [{ i, text, x: Math.min(Math.max(this.x(i), half), this.width - half) }]
			})
		},
		/** Each series with its points that have a value, its runs between gaps (a lone point is a dot, not a run), its class and dash. */
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
				const order = line.emphasis ? null : context++
				return {
					...line,
					className: line.emphasis ? 'estadistica-chart__line--emphasis' : 'estadistica-chart__line--context',
					dash: line.emphasis ? null : DASHES[order % DASHES.length],
					hollow: order > 0,
					points: line.points.filter((point) => point.v !== null),
					runs: runs.filter((run) => run.length > 1),
				}
			})
		},
		/** Each category's columns, side by side in legend order, 24 px at most and 2 px apart, with a rounded top and a square base. */
		groups() {
			const band = this.innerWidth / this.categories.length
			const w = Math.max(1, Math.min(24, (band * 0.8) / this.series.length - 2))
			const base = this.y(0)
			return this.categories.map((category, i) => {
				const start = this.x(i) - (this.series.length * (w + 2) - 2) / 2
				return {
					i,
					columns: this.drawn.flatMap((line, j) => {
						const point = line.points.find((candidate) => candidate.i === i)
						if (!point) {
							return []
						}
						const x = start + j * (w + 2)
						const top = this.y(point.v)
						const r = Math.min(4, w / 2, base - top)
						const d = `M${x.toFixed(1)} ${base} V${(top + r).toFixed(1)} Q${x.toFixed(1)} ${top.toFixed(1)} ${(x + r).toFixed(1)} ${top.toFixed(1)}`
							+ ` H${(x + w - r).toFixed(1)} Q${(x + w).toFixed(1)} ${top.toFixed(1)} ${(x + w).toFixed(1)} ${(top + r).toFixed(1)} V${base} Z`
						return [{ ...line, d, said: `${category}: ${this.formatted(point.v)} ${this.named(line, point)}` }]
					}),
				}
			})
		},
		/** Each line named at its last point, unless two would collide or the names would take a third of the width: then the legend and the readout carry them. */
		endLabels() {
			if (this.columns) {
				return []
			}
			const ends = this.drawn.filter((line) => line.points.length).map((line) => {
				const last = line.points.at(-1)
				return { id: line.id, emphasis: line.emphasis, i: last.i, y: this.y(last.v), text: `${line.short ?? line.name} ${this.formatted(last.v)}` }
			})
			const collide = ends.some((a, i) => ends.some((b, j) => i < j && Math.abs(a.y - b.y) < 14))
			const shown = collide ? ends.filter((end) => end.emphasis) : ends
			return shown.every((end) => textWidth(end.text) + 8 <= this.width / 3) ? shown : []
		},
		/** The categories any series has, in order: where the crosshair stops. */
		indexes() {
			return [...new Set(this.series.flatMap((line) => line.points.map((point) => point.i)))].sort((a, b) => a - b)
		},
		lastIndex() {
			return this.indexes.at(-1) ?? null
		},
		/** The active category's value of each series, «—» where it has none, then the target. */
		readout() {
			const rows = this.series.map((line) => {
				const point = line.points.find((candidate) => candidate.i === this.active)
				return { id: line.id, value: this.formatted(point?.v), name: this.named(line, point) }
			})
			return this.target === null ? rows : [...rows, { id: 'target', value: this.formatted(this.target), name: this.targetName }]
		},
	},

	methods: {
		/** Where a category sits: a line's point, from the first category at the left edge to the last at the right, or a column group's centre. */
		x(index) {
			const n = this.categories.length
			const at = this.columns ? (index + 0.5) / n : n > 1 ? index / (n - 1) : 0.5
			return this.left + at * this.innerWidth
		},

		y(value) {
			return TOP + this.innerHeight - (value / this.scale.top) * this.innerHeight
		},

		path(run) {
			return run.map((point, k) => `${k ? 'L' : 'M'}${this.x(point.i).toFixed(1)} ${this.y(point.v).toFixed(1)}`).join(' ')
		},

		formatted(value) {
			if (value === null || value === undefined) {
				return '—'
			}
			return this.unit ? `${formatValue(value)} ${this.unit}` : formatValue(value)
		},

		/** A series' name and what its point adds. */
		named(line, point) {
			return [line.name, point?.detail].filter(Boolean).join(' · ')
		},

		/** The category nearest the pointer. */
		pointAt(event) {
			const box = event.currentTarget.getBoundingClientRect()
			const at = ((event.clientX - box.left) / box.width) * this.width
			this.active = this.indexes.reduce((best, i) => (Math.abs(this.x(i) - at) < Math.abs(this.x(best) - at) ? i : best), this.indexes[0] ?? null)
		},

		/** On a line chart the arrow keys move one category, and stop at the first and the last. */
		step(event) {
			const move = { ArrowRight: 1, ArrowLeft: -1 }[event.key]
			if (this.columns || !move || this.indexes.length === 0) {
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

.estadistica-chart .estadistica-chart__column {
	fill: currentColor;
	stroke: none;
	outline: none;
}

.estadistica-chart .estadistica-chart__column--hollow {
	fill: none;
	stroke: currentColor;
	stroke-width: 1.5;
}

.estadistica-chart .estadistica-chart__column:focus-visible {
	stroke: var(--color-main-text);
	stroke-width: 2;
}
</style>
