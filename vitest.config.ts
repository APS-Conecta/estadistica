import { configDefaults, defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// The component-mount tests (L2-21) import the real .vue files, whose
// @nextcloud/vue children import their .css assets — vite needs to own all
// of it: plugin-vue for the SFCs, and the dep taken OUT of ssr externals
// (vitest 4's form of inlining) so its .css imports reach vite's pipeline
// instead of Node's loader, which has no answer for a stylesheet.
// Everything else stays config-free: each mount test carries its own
// `// @vitest-environment jsdom` directive, so the module tests keep the
// fast node environment.
export default defineConfig({
	plugins: [vue()],
	ssr: {
		noExternal: [/@nextcloud\/vue/],
	},
	test: {
		css: true,
		// vendor/ is PHP's node_modules: `make test`'s composer install copies aps-common
		// whole into vendor/aps/common, js/filters.test.js (a node:test file) included, and
		// `npm test` after it would collect that as a suite with no tests and fail.
		exclude: [...configDefaults.exclude, 'vendor/**'],
	},
})
