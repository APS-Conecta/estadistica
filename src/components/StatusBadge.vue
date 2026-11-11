<!--
	A meta's status as its icon and its word, never colour alone (#83). The
	colours are Nextcloud's status pairs, the plain token as the background
	and its -text form as the text, so the badge follows the theme, dark
	included.
-->
<template>
	<span :class="['estadistica-estado', `estadistica-estado--${status.kind}`]">
		<NcIconSvgWrapper :path="ICONS[status.kind]" :size="16" inline />
		<span>{{ status.label }}</span>
	</span>
</template>

<script>
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { ICONS } from '../status.js'

export default {
	name: 'StatusBadge',

	components: { NcIconSvgWrapper },

	props: {
		/** statusOf()'s answer: its kind and its word. */
		status: { type: Object, required: true },
	},

	data() {
		return { ICONS }
	},
}
</script>

<style scoped>
.estadistica-estado {
	display: inline-flex;
	align-self: start;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
	padding-block: 2px;
	padding-inline: calc(var(--default-grid-baseline) * 2) calc(var(--default-grid-baseline) * 3);
	border-radius: var(--border-radius-pill);
	font-weight: bold;
	white-space: nowrap;
}

.estadistica-estado--success {
	background-color: var(--color-success);
	color: var(--color-success-text);
}

.estadistica-estado--error {
	background-color: var(--color-error);
	color: var(--color-error-text);
}

.estadistica-estado--warning {
	background-color: var(--color-warning);
	color: var(--color-warning-text);
}

.estadistica-estado--waiting,
.estadistica-estado--pending {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
</style>
