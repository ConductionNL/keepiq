import { describe, expect, it, vi } from 'vitest'
import { headerFor, openPage, pageTitle, type ShellPage, type ShellView } from './shellPage'

const view = (over: Partial<ShellView> = {}): ShellView => ({
	current: 'vault', form: null, picking: false, detail: false, section: null, history: false, sendPage: null, ...over,
})

describe('openPage', () => {
	it.each<[string, Partial<ShellView>, ShellPage | null]>([
		['nothing open on a tab', {}, null],
		['pick mode over its form', { form: { mode: 'add' }, picking: true, detail: true }, 'pick'],
		['a form over the detail it came from', { form: { mode: 'edit' }, detail: true }, 'form'],
		['a detail', { detail: true }, 'detail'],
		['a detail over a section left open', { current: 'settings', section: 'vault', detail: true }, 'detail'],
		['a section on the Settings tab', { current: 'settings', section: 'autofill' }, 'section'],
		['a section while on another tab', { current: 'vault', section: 'autofill' }, null],
		['the history on the Generator tab', { current: 'generator', history: true }, 'history'],
		['the history while on another tab', { current: 'settings', history: true }, null],
		['a send page on the Send tab', { current: 'send', sendPage: { kind: 'new' } }, 'send'],
		['a send page while on another tab', { current: 'generator', sendPage: { kind: 'created' } }, null],
	])('%s', (_, over, page) => {
		expect(openPage(view(over))).toBe(page)
	})
})

describe('pageTitle', () => {
	it.each<[ShellPage | null, Partial<ShellView>, string]>([
		['pick', { form: { mode: 'edit' }, picking: true }, 'Generator'],
		['form', { form: { mode: 'add' } }, 'New item'],
		['form', { form: { mode: 'clone' } }, 'New item'],
		['form', { form: { mode: 'edit' } }, 'Edit item'],
		['detail', { detail: true }, 'View item'],
		['section', { current: 'settings', section: 'accountSecurity' }, 'Account security'],
		['section', { current: 'settings', section: 'folders' }, 'Folders'],
		['history', { current: 'generator', history: true }, 'Generator history'],
		['send', { current: 'send', sendPage: { kind: 'new' } }, 'New send'],
		['send', { current: 'send', sendPage: { kind: 'created' } }, 'Send created'],
		[null, { current: 'send' }, 'Send'],
		[null, { current: 'settings' }, 'Settings'],
	])('%s %o is titled %s', (page, over, title) => {
		expect(pageTitle(page, view(over))).toBe(title)
	})
})

describe('headerFor', () => {
	const backs = { pick: vi.fn(), form: vi.fn(), detail: vi.fn(), section: vi.fn(), history: vi.fn(), send: vi.fn() }
	const adds = { vault: vi.fn(), send: vi.fn() }

	it('gives a page its own Back and no New', () => {
		const header = headerFor(view({ current: 'send', sendPage: { kind: 'new' } }), { backs, adds })
		expect(header).toEqual({ page: 'send', title: 'New send', back: backs.send, add: undefined })
	})

	it('gives pick mode the Generator title and its own Back, not the form\'s', () => {
		const header = headerFor(view({ form: { mode: 'add' }, picking: true }), { backs, adds })
		expect(header).toEqual({ page: 'pick', title: 'Generator', back: backs.pick, add: undefined })
	})

	it('gives a tab its New and no Back', () => {
		expect(headerFor(view({ current: 'vault' }), { backs, adds })).toEqual({ page: null, title: 'Vault', back: undefined, add: adds.vault })
	})

	it('gives a tab without New neither', () => {
		expect(headerFor(view({ current: 'generator' }), { backs, adds })).toEqual({ page: null, title: 'Generator', back: undefined, add: undefined })
	})
})
