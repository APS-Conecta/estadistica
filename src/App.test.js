// @vitest-environment jsdom
import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'

// Each tab's module records when it is first evaluated: a static import would
// record all four the moment App.vue loads.
const { loaded, tab } = vi.hoisted(() => {
	const loaded = []
	const tab = (view, name) => () => {
		loaded.push(view)
		// __esModule: Vue's async loader takes `default` only from a module namespace.
		return { __esModule: true, default: { name, render: () => null } }
	}
	return { loaded, tab }
})

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key === 'establishment' ? { configured: true } : fallback),
}))
vi.mock('./components/QueryBuilder.vue', tab('consultar', 'QueryBuilder'))
vi.mock('./components/Questions.vue', tab('preguntas', 'SavedQuestions'))
vi.mock('./components/Dashboard.vue', tab('tableros', 'EstadisticaDashboard'))
vi.mock('./components/Goals.vue', tab('metas', 'SanitaryGoals'))

const open = async (wrapper, label) => {
	await wrapper.findAll('nav button').find((b) => b.text() === label).trigger('click')
	await flushPromises()
}

describe('App', () => {
	it('loads a tab\'s code the first time the tab opens', async () => {
		const wrapper = mount(App)
		await flushPromises()
		expect(loaded).toEqual(['consultar'])
		expect(wrapper.findComponent({ name: 'QueryBuilder' }).exists()).toBe(true)

		await open(wrapper, 'Preguntas')
		expect(loaded).toEqual(['consultar', 'preguntas'])
		expect(wrapper.findComponent({ name: 'SavedQuestions' }).exists()).toBe(true)
		// The builder stays mounted, as before: a consulta half-built survives.
		expect(wrapper.findComponent({ name: 'QueryBuilder' }).exists()).toBe(true)

		await open(wrapper, 'Tableros')
		await open(wrapper, 'Metas Sanitarias')
		expect(loaded).toEqual(['consultar', 'preguntas', 'tableros', 'metas'])
		expect(wrapper.findComponent({ name: 'SanitaryGoals' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'SavedQuestions' }).exists()).toBe(false)
	})

	it('shows an error card in place of a tab whose code cannot load', async () => {
		// A chunk the server no longer serves, in a fresh App: a tab keeps its
		// first load's outcome for the page's life.
		vi.doMock('./components/Dashboard.vue', () => {
			throw new Error('no chunk for tableros')
		})
		vi.resetModules()
		const { default: FreshApp } = await import('./App.vue')
		const wrapper = mount(FreshApp)
		await flushPromises()

		await open(wrapper, 'Tableros')
		const card = wrapper.findComponent({ name: 'NcNoteCard' })
		expect(card.exists()).toBe(true)
		expect(card.props('type')).toBe('error')
		expect(card.text()).toContain('No se pudo cargar esta sección.')
		expect(wrapper.findComponent({ name: 'EstadisticaDashboard' }).exists()).toBe(false)
	})
})
