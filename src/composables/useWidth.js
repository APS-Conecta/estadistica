import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * The rendered width of an element, kept current (#86). A chart draws at its
 * real width, so its text keeps its size in a card, the page or the sidebar.
 * A hidden element (a tab not shown) reads as the narrowest width.
 *
 * @param {import('vue').Ref<?Element>} target the element to measure
 * @param {number} fallback the width before the first measure, and where nothing measures
 * @return {import('vue').Ref<number>}
 */
export function useWidth(target, fallback = 460) {
	const width = ref(fallback)
	let observer = null
	onMounted(() => {
		if (!('ResizeObserver' in window) || !target.value) {
			return
		}
		observer = new window.ResizeObserver(([entry]) => {
			width.value = Math.max(260, Math.round(entry.contentRect.width))
		})
		observer.observe(target.value)
	})
	onBeforeUnmount(() => observer?.disconnect())
	return width
}
