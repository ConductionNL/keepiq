// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { generate } from '@/src/generator/generate'
import App from '../App'
import { editableItem, everythingVisible, fakeBackground, sentOf } from '../testing'

vi.mock('@/src/generator/generate', async (original) => ({ ...await original<typeof import('@/src/generator/generate')>(), generate: vi.fn() }))

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/Shell.places.golden.json')

afterEach(cleanup)
beforeEach(everythingVisible)
beforeEach(() => {
	vi.mocked(generate).mockImplementation(async (kind) => ({ ok: true, value: `${kind}-1` }))
})

const body = () => document.querySelector<HTMLElement>('.popup__body')!
const footer = () => document.querySelector<HTMLElement>('.popup__footer')!
const heading = () => screen.getByRole('heading', { level: 1 }).textContent
const value = (label: string) => (screen.queryByLabelText(label) as HTMLInputElement | null)?.value ?? null

/** Where the popup body is scrolled and which control holds focus. */
function place() {
	const focused = document.activeElement
	return {
		title: heading(),
		scroll: body().scrollTop,
		focus: focused === document.body ? null : focused?.getAttribute('aria-label') ?? focused?.textContent ?? null,
	}
}

async function walk(): Promise<Record<string, unknown>> {
	fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } }, items: { i1: editableItem() } })
	render(<App />)
	const user = userEvent.setup()
	const trace: Record<string, unknown> = {}
	const step = async (name: string, act: () => Promise<unknown>, title: string, extra: () => object = () => ({})) => {
		await act()
		await waitFor(() => expect(heading()).toBe(title))
		trace[name] = { ...place(), ...extra() }
	}
	const click = (name: string | RegExp) => async () => user.click(await screen.findByRole('button', { name }))
	const scrollTo = (top: number) => {
		body().scrollTop = top
	}
	const more = async (entry: string) => {
		await user.click(await within(footer()).findByRole('button', { name: 'More, GitHub' }))
		await user.click(await screen.findByRole('menuitem', { name: entry }))
	}
	await step('01 vault', async () => screen.findByRole('searchbox'), 'Vault')
	scrollTo(120)
	await step('02 detail', click(/^GitHub/), 'View item')
	scrollTo(15)
	await step('03 back from detail', click('Back'), 'Vault')
	scrollTo(30)
	await step('04 new item', click('New'), 'New item')
	scrollTo(40)
	await step('05 pick mode', click('Generate password'), 'Generator')
	scrollTo(7)
	await step('06 back from pick', click('Back'), 'New item')
	await step('07 back from form', click('Back'), 'Vault')
	await step('08 detail again', click(/^GitHub/), 'View item')
	await step('09 clone', () => more('Clone'), 'New item', () => ({ name: value('Name'), username: value('Username') }))
	await step('10 back from clone', click('Back'), 'View item')
	await step('11 send from item', () => more('Send'), 'New send', () => ({
		username: value('Username'),
		password: value('Password'),
	}))
	await step('12 back from new send', click('Back'), 'Send', () => ({
		current: within(screen.getByRole('navigation', { name: 'Main' })).getAllByRole('button').find((b) => b.getAttribute('aria-current') === 'page')?.textContent,
	}))
	await step('13 vault tab', click('Vault'), 'Vault')
	return trace
}

/** Send from an item whose login the background could not decrypt, or which it found locked. */
async function sendUnreadable(decrypt: unknown): Promise<Record<string, unknown>> {
	const sent = fakeBackground({ items: { i1: editableItem() }, replies: { 'item.decrypt': decrypt } })
	render(<App />)
	const user = userEvent.setup()
	await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
	await user.click(await within(footer()).findByRole('button', { name: 'More, GitHub' }))
	const before = sentOf(sent, 'vault.status').length
	await user.click(await screen.findByRole('menuitem', { name: 'Send' }))
	await new Promise((done) => setTimeout(done, 50))
	return { title: heading(), toast: document.querySelector('.popup__bottom')?.textContent ?? '', statusReread: sentOf(sent, 'vault.status').length > before }
}

/** Edit on an item that was deleted elsewhere: the form and the detail close. */
async function editGone(): Promise<Record<string, unknown>> {
	fakeBackground()
	render(<App />)
	const user = userEvent.setup()
	await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
	await user.click(await within(footer()).findByRole('button', { name: 'Edit' }))
	await new Promise((done) => setTimeout(done, 50))
	return { title: heading(), toast: document.querySelector('.popup__bottom')?.textContent ?? '' }
}

/** Back on New send while its create is still in flight. */
async function backWhileCreating(): Promise<Record<string, unknown>> {
	fakeBackground({ lastTab: 'send', replies: { 'send.create': () => new Promise(() => {}) } })
	render(<App />)
	const user = userEvent.setup()
	await user.click(await screen.findByRole('button', { name: 'New' }))
	await user.type(screen.getByLabelText('Text to share'), 'the secret')
	await user.click(within(footer()).getByRole('button', { name: 'Create' }))
	await user.click(screen.getByRole('button', { name: 'Back' }))
	await new Promise((done) => setTimeout(done, 50))
	return { title: heading(), dialog: screen.queryByRole('dialog') !== null }
}

/** A created send's row takes focus once, and not again when the Send tab is picked later. */
async function sendTabAgain(): Promise<Record<string, unknown>> {
	fakeBackground({ lastTab: 'send' })
	render(<App />)
	const user = userEvent.setup()
	await user.click(await screen.findByRole('button', { name: 'New' }))
	await user.type(screen.getByLabelText('Text to share'), 'the secret')
	await user.click(within(footer()).getByRole('button', { name: 'Create' }))
	await user.click(await within(footer()).findByRole('button', { name: 'Done' }))
	const row = await screen.findByRole('button', { name: /^Copy link, Text send/ })
	await waitFor(() => expect(document.activeElement).toBe(row))
	const afterDone = place()
	await user.click(screen.getByRole('button', { name: 'Vault' }))
	await screen.findByRole('searchbox')
	await user.click(screen.getByRole('button', { name: 'Send' }))
	await screen.findByRole('button', { name: /^Copy link, Text send/ })
	await new Promise((done) => setTimeout(done, 50))
	// A row's name ends in its creation time, which changes per run.
	const undated = (at: ReturnType<typeof place>) => ({ ...at, focus: at.focus?.split(', ').slice(0, 2).join(', ') ?? null })
	return { afterDone: undated(afterDone), again: undated(place()) }
}

describe('Shell places golden record', () => {
	it('keeps the recorded scroll, focus and item-action pages on every step of a walk', async () => {
		const got = {
			walk: await walk(),
			undecrypted: (cleanup(), await sendUnreadable({ ok: true, items: {} })),
			locked: (cleanup(), await sendUnreadable({ ok: false, error: 'locked' })),
			editGone: (cleanup(), await editGone()),
			backWhileCreating: (cleanup(), await backWhileCreating()),
			sendTabAgain: (cleanup(), await sendTabAgain()),
		}
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		expect(got).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	}, 30_000)
})
