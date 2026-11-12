import eslintPluginVue from 'eslint-plugin-vue'

// The theme is the only source of colour and font (#83): src/ reads Nextcloud's CSS variables, and the suite's
// theme gives the brand, light or dark. A comment is not read: blanked, it keeps every other line and column.
const WRITTEN = /#[\da-f]{3,8}\b|\b(?:rgb|hsl)a?\(|\bfont-?family\b/gi
// CSS's 148 named colours. A name means a colour only where CSS or SVG paint is written: a style block's or a style
// attribute's declarations, where the font shorthand, which names a family, is read too, and an SVG paint attribute.
const NAMES = `aliceblue antiquewhite aqua aquamarine azure beige bisque black blanchedalmond blue blueviolet brown burlywood
cadetblue chartreuse chocolate coral cornflowerblue cornsilk crimson cyan darkblue darkcyan darkgoldenrod darkgray
darkgreen darkgrey darkkhaki darkmagenta darkolivegreen darkorange darkorchid darkred darksalmon darkseagreen
darkslateblue darkslategray darkslategrey darkturquoise darkviolet deeppink deepskyblue dimgray dimgrey dodgerblue
firebrick floralwhite forestgreen fuchsia gainsboro ghostwhite gold goldenrod gray green greenyellow grey honeydew
hotpink indianred indigo ivory khaki lavender lavenderblush lawngreen lemonchiffon lightblue lightcoral lightcyan
lightgoldenrodyellow lightgray lightgreen lightgrey lightpink lightsalmon lightseagreen lightskyblue lightslategray
lightslategrey lightsteelblue lightyellow lime limegreen linen magenta maroon mediumaquamarine mediumblue
mediumorchid mediumpurple mediumseagreen mediumslateblue mediumspringgreen mediumturquoise mediumvioletred
midnightblue mintcream mistyrose moccasin navajowhite navy oldlace olive olivedrab orange orangered orchid
palegoldenrod palegreen paleturquoise palevioletred papayawhip peachpuff peru pink plum powderblue purple
rebeccapurple red rosybrown royalblue saddlebrown salmon sandybrown seagreen seashell sienna silver skyblue
slateblue slategray slategrey snow springgreen steelblue tan teal thistle tomato turquoise violet wheat white
whitesmoke yellow yellowgreen`
const NAMED = new RegExp(`(?<![\\w-])(?:${NAMES.split(/\s+/).join('|')})(?![\\w-])`, 'gi')
const STYLE_BLOCK = /<style\b[^>]*>([\s\S]*?)<\/style>/dg
// An attribute, bound or not, in either quote, its value ending at its own closing quote: style's declarations, or paint.
const ATTRIBUTE = /\s(?:v-bind:|:)?(style|fill|stroke|stop-color|flood-color|lighting-color|color)=(["'])([\s\S]*?)\2/dg
const DECLARATION = /([\w-]+)['"]?\s*:([^;{}]*)(?=[;}]|$)/dg
const KEYWORD = /^\s*(?:inherit|initial|unset|revert(?:-layer)?)\s*$/i
const blank = (text) => text.replace(/\S/g, ' ')

// The file as it ships: its script and template comments, which the parser knows, and its style blocks' own.
function uncommented(sourceCode) {
	let text = sourceCode.text
	for (const { range: [start, end] } of [...sourceCode.getAllComments(), ...(sourceCode.ast.templateBody?.comments ?? [])]) {
		text = text.slice(0, start) + blank(text.slice(start, end)) + text.slice(end)
	}
	return text.replace(/(<style\b[^>]*>)([\s\S]*?)(?=<\/style>)/g, (_, open, css) => open + css.replace(/\/\*[\s\S]*?\*\//g, blank))
}

const themeOnly = {
	meta: {
		type: 'problem',
		messages: { written: '«{{found}}» writes a colour or a font: read a Nextcloud CSS variable, the suite\'s theme gives the brand' },
	},
	create(context) {
		const { sourceCode } = context
		return {
			'Program:exit'() {
				const report = (index, found) => context.report({ loc: sourceCode.getLocFromIndex(index), messageId: 'written', data: { found } })
				const text = uncommented(sourceCode)
				for (const match of text.matchAll(WRITTEN)) {
					report(match.index, match[0])
				}
				const named = (value, at) => {
					for (const name of value.matchAll(NAMED)) {
						report(at + name.index, name[0])
					}
				}
				const css = [...text.matchAll(STYLE_BLOCK)].map((block) => [block[1], block.indices[1][0]])
				for (const { 1: attribute, 3: value, indices } of text.matchAll(ATTRIBUTE)) {
					if (attribute === 'style') {
						css.push([value, indices[3][0]])
					} else {
						named(value, indices[3][0])
					}
				}
				for (const [declarations, start] of css) {
					for (const { 1: property, 2: value, indices } of declarations.matchAll(DECLARATION)) {
						const at = start + indices[2][0]
						if (property.toLowerCase() === 'font' && !KEYWORD.test(value)) {
							report(at, `font: ${value.trim()}`)
						}
						named(value, at)
					}
				}
			},
		}
	},
}

// Two rules with teeth — undeclared identifiers (territorio's "Guardar never
// submitted the form" lesson, farmacia's copy of it) and the theme gate. Style stays off on purpose.
export default [
	...eslintPluginVue.configs['flat/essential'],
	{
		files: ['src/**/*.{js,vue}'],
		// A flat config declares no browser globals of its own, so no-undef needs
		// the surface the app actually uses named. Blob: the shared error-shape
		// reader (src/aps/api.js) unwraps a blob body behind a typeof guard.
		// __webpack_public_path__: main.js points the tabs' chunks at the app's js/.
		languageOptions: {
			globals: {
				console: 'readonly',
				window: 'readonly',
				document: 'readonly',
				URL: 'readonly',
				Blob: 'readonly',
				__webpack_public_path__: 'writable',
			},
		},
		plugins: { estadistica: { rules: { 'theme-only': themeOnly } } },
		rules: {
			'no-undef': 'error',
			'estadistica/theme-only': 'error',
		},
	},
]
