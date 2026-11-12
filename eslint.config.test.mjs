import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

// The theme gate (#92) at the step that runs it, `npm run lint-js`: this repo's eslint.config.mjs on a file under
// src/. It sits outside src/ itself, so the colours it plants are not its own.
const eslint = new ESLint()

async function written(source, filePath = 'src/components/Planted.vue') {
	const [result] = await eslint.lintText(source, { filePath })
	return result.messages.filter((message) => message.ruleId === 'estadistica/theme-only')
		.map((message) => `${message.line}: ${message.message}`)
}

describe('the theme gate', () => {
	it('fails on a colour planted in a component', async () => {
		expect(await written('<template>\n\t<p class="a">Hola</p>\n</template>\n\n<style scoped>\n.a {\n\tcolor: #123456;\n}\n</style>\n'))
			.toEqual(['7: «#123456» writes a colour or a font: read a Nextcloud CSS variable, the suite\'s theme gives the brand'])
	})

	it('fails on rgb(), hsl() and a font family in a template, a script or a style block', async () => {
		const found = (await written('<template>\n\t<svg><rect fill="rgba(0, 0, 0, .5)" /></svg>\n</template>\n\n'
			+ '<script>\nconst shade = \'hsl(10 20% 30%)\'\nexport default { shade }\n</script>\n\n'
			+ '<style scoped>\nsvg {\n\tfont-family: Fraunces, serif;\n}\n</style>\n')).map((message) => message.split(' writes')[0])
		expect(found).toEqual(['2: «rgba(»', '6: «hsl(»', '12: «font-family»'])
		expect(await written('export const label = { fontFamily: \'Nunito Sans\' }\n', 'src/charts.js'))
			.toEqual(['1: «fontFamily» writes a colour or a font: read a Nextcloud CSS variable, the suite\'s theme gives the brand'])
	})

	it('fails on a colour named in CSS and on a font shorthand, never on a selector or a word in the text', async () => {
		const found = (await written('<template>\n\t<p class="red" style="color: Navy">La red de salud</p>\n</template>\n\n'
			+ '<style scoped>\n.red:hover {\n\twhite-space: nowrap;\n\tborder: 1px solid white;\n\tfont: 600 1rem Fraunces;\n'
			+ '\tcolor: var(--color-main-text);\n}\n</style>\n')).map((message) => message.split(' writes')[0])
		expect(found).toEqual(['2: «Navy»', '8: «white»', '9: «font: 600 1rem Fraunces»'])
	})

	it('fails on a colour named in a style binding, in either spelling', async () => {
		const found = (await written('<template>\n\t<p :style="{ color: \'red\', background: \'white\' }">Hola</p>\n'
			+ '\t<p v-bind:style="{ \'border-color\': \'navy\' }">Chao</p>\n</template>\n')).map((message) => message.split(' writes')[0])
		expect(found).toEqual(['2: «red»', '2: «white»', '3: «navy»'])
	})

	it('fails on a colour named in a single-quoted style attribute', async () => {
		const found = (await written('<template>\n\t<p style=\'background: white; color: navy\'>Hola</p>\n</template>\n'))
			.map((message) => message.split(' writes')[0])
		expect(found).toEqual(['2: «white»', '2: «navy»'])
	})

	it('fails on a colour named in an SVG paint attribute, never on currentColor or none', async () => {
		const found = (await written('<template>\n\t<svg><rect fill="red" stroke="white" /><path fill="none" stroke="currentColor" />\n'
			+ '\t\t<stop stop-color="Gold" /><feFlood flood-color="black" /><text :fill="\'navy\'">1</text></svg>\n</template>\n'))
			.map((message) => message.split(' writes')[0])
		expect(found).toEqual(['2: «red»', '2: «white»', '3: «Gold»', '3: «black»', '3: «navy»'])
	})

	it('takes SVG geometry and the theme\'s variables for what they are', async () => {
		expect(await written('<template>\n\t<svg class="a" viewBox="0 0 24 24"><path d="M19 3H5C3.9 3 3 3.9 3 5V19L12,2A10,10 0 0,0 2,12Z"'
			+ ' fill="currentColor" stroke-dasharray="4 2" /></svg>\n</template>\n\n'
			+ '<style scoped>\n.a path {\n\tfill: none;\n\tstroke: var(--color-primary-element);\n\tborder: 1px solid transparent;\n}\n</style>\n'))
			.toEqual([])
	})

	it('reads no comment, in a script, a template or a style block', async () => {
		expect(await written('<template>\n\t<!-- was #fff (#85) -->\n\t<p class="a">Hola</p>\n</template>\n\n'
			+ '<script>\n// territorio #104\n/* rgb(0 0 0), font-family: serif */\nexport default {}\n</script>\n\n'
			+ '<style scoped>\n/* was background: white; (#123) */\n.a {\n\tfont: inherit; /* not hsl(0 0% 0%) */\n}\n</style>\n')).toEqual([])
	})
})
