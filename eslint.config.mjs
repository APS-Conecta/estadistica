import eslintPluginVue from 'eslint-plugin-vue'

// One rule with teeth — undeclared identifiers (territorio's "Guardar never
// submitted the form" lesson, farmacia's copy of it). Style stays off on purpose.
export default [
	...eslintPluginVue.configs['flat/essential'],
	{
		files: ['src/**/*.{js,vue}'],
		// A flat config declares no browser globals of its own, so no-undef needs
		// the surface the app actually uses named. Blob: the shared error-shape
		// reader (src/aps/api.js) unwraps a blob body behind a typeof guard.
		languageOptions: {
			globals: {
				console: 'readonly',
				window: 'readonly',
				document: 'readonly',
				URL: 'readonly',
				Blob: 'readonly',
			},
		},
		rules: {
			'no-undef': 'error',
		},
	},
]
