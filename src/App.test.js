// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import App from './App.vue'

// Each section's module records when it is first evaluated: a static import would
// record all five the moment App.vue loads.
const { loaded, tab, user } = vi.hoisted(() => {
	const loaded = []
	const tab = (view, name) => () => {
		loaded.push(view)
		// __esModule: Vue's async loader takes `default` only from a module namespace.
		return { __esModule: true, default: { name, render: () => null } }
	}
	return { loaded, tab, user: { isAdmin: false } }
})

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => user }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key === 'establishment' ? { configured: true } : fallback),
}))
vi.mock('./components/QueryBuilder.vue', tab('consultar', 'QueryBuilder'))
vi.mock('./components/Questions.vue', tab('preguntas', 'SavedQuestions'))
vi.mock('./components/Dashboard.vue', tab('tableros', 'EstadisticaDashboard'))
vi.mock('./components/Goals.vue', tab('metas', 'SanitaryGoals'))
vi.mock('./components/Resumen.vue', tab('resumen', 'EstadisticaResumen'))

const entries = (wrapper) => wrapper.findAll('.app-navigation a')
const entry = (wrapper, label) => entries(wrapper).find((a) => a.text() === label)
const open = async (wrapper, label) => {
	await entry(wrapper, label).trigger('click')
	await flushPromises()
}
const current = (wrapper) => entries(wrapper).filter((a) => a.attributes('aria-current') === 'page').map((a) => a.text())
const title = (wrapper) => wrapper.find('.estadistica-heading h2').text()

describe('App', () => {
	beforeEach(() => {
		user.isAdmin = false
		// Nextcloud's page holds the skip links NcContent adds.
		document.body.innerHTML = '<div id="skip-actions"></div>'
	})

	it('lists the five sections in the navigation, opens on the Resumen, and loads a section\'s code the first time it opens', async () => {
		const wrapper = mount(App)
		await flushPromises()
		expect(entries(wrapper).map((a) => a.text())).toEqual(['Resumen', 'Metas Sanitarias', 'Consultar', 'Preguntas', 'Tableros'])
		expect(current(wrapper)).toEqual(['Resumen'])
		expect(title(wrapper)).toBe('Resumen')
		expect(wrapper.findComponent({ name: 'EstadisticaResumen' }).exists()).toBe(true)
		expect(loaded).toEqual(['resumen'])
		expect(wrapper.findComponent({ name: 'QueryBuilder' }).exists()).toBe(false)

		await open(wrapper, 'Consultar')
		expect(current(wrapper)).toEqual(['Consultar'])
		expect(title(wrapper)).toBe('Consultar')
		expect(loaded).toEqual(['resumen', 'consultar'])
		expect(wrapper.findComponent({ name: 'QueryBuilder' }).exists()).toBe(true)

		await open(wrapper, 'Preguntas')
		expect(loaded).toEqual(['resumen', 'consultar', 'preguntas'])
		expect(wrapper.findComponent({ name: 'SavedQuestions' }).exists()).toBe(true)
		// The builder stays mounted once opened, as before: a consulta half-built survives.
		expect(wrapper.findComponent({ name: 'QueryBuilder' }).exists()).toBe(true)

		await open(wrapper, 'Tableros')
		await open(wrapper, 'Metas Sanitarias')
		expect(current(wrapper)).toEqual(['Metas Sanitarias'])
		expect(title(wrapper)).toBe('Metas Sanitarias')
		expect(loaded).toEqual(['resumen', 'consultar', 'preguntas', 'tableros', 'metas'])
		expect(wrapper.findComponent({ name: 'SanitaryGoals' }).exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'SavedQuestions' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'EstadisticaResumen' }).exists()).toBe(false)
	})

	it('offers «Ajustes de Estadística», the app\'s admin settings page, to an administrator only', async () => {
		const member = mount(App)
		await flushPromises()
		expect(entry(member, 'Ajustes de Estadística')).toBeUndefined()

		user.isAdmin = true
		const admin = mount(App)
		await flushPromises()
		expect(entry(admin, 'Ajustes de Estadística').attributes('href')).toBe('/index.php/settings/admin/estadistica')
	})

	it('shows an error card in place of a section whose code cannot load', async () => {
		// A chunk the server no longer serves, in a fresh App: a section keeps its
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
