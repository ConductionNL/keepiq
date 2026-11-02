// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { generate } from '@/src/generator/generate'
import App from '../App'
import { editableItem, everythingVisible, fakeBackground } from '../testing'

vi.mock('@/src/generator/generate', async (original) => ({ ...await original<typeof import('@/src/generator/generate')>(), generate: vi.fn() }))

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/Shell.chrome.golden.json')

afterEach(cleanup)
beforeEach(everythingVisible)
beforeEach(() => {
	vi.mocked(generate).mockImplementation(async (kind) => ({ ok: true, value: `${kind}-1` }))
})

/** What the shell shows around a page: its title, the header actions and whether the tab bar is there. */
function chrome() {
	const has = (name: string) => screen.queryByRole('button', { name }) !== null
	return {
		title: screen.getByRole('heading', { level: 1 }).textContent,
		back: has('Back'),
		add: has('New'),
		popout: has('Pop out'),
		tabBar: screen.queryByRole('navigation', { name: 'Main' }) !== null,
		dialog: screen.queryByRole('dialog')?.getAttribute('aria-label') ?? screen.queryByRole('dialog')?.textContent ?? null,
	}
}

async function walk(): Promise<Record<string, unknown>> {
	fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } }, items: { i1: editableItem() } })
	render(<App />)
	const user = userEvent.setup()
	const trace: Record<string, unknown> = {}
	const step = async (name: string, act: () => Promise<unknown>, title: string) => {
		await act()
		await waitFor(() => expect(screen.getByRole('heading', { level: 1 }).textContent).toBe(title))
		trace[name] = chrome()
	}
	const click = (name: string | RegExp) => async () => user.click(await screen.findByRole('button', { name }))
	await step('01 vault', async () => screen.findByRole('searchbox'), 'Vault')
	await step('02 new item', click('New'), 'New item')
	await step('03 pick mode', click('Generate password'), 'Generator')
	await step('04 back from pick', click('Back'), 'New item')
	await step('05 back from clean form', click('Back'), 'Vault')
	await step('06 detail', click(/^GitHub/), 'View item')
	await step('07 edit', click('Edit'), 'Edit item')
	await step('08 dirty edit back', async () => {
		await user.type(await screen.findByLabelText('Name'), ' work')
		await click('Back')()
	}, 'Edit item')
	await step('09 discard', click('Discard'), 'View item')
	await step('10 back from detail', click('Back'), 'Vault')
	await step('11 generator', click('Generator'), 'Generator')
	await step('12 history', click('Generator history'), 'Generator history')
	await step('13 back from history', click('Back'), 'Generator')
	await step('14 settings', click('Settings'), 'Settings')
	await step('15 section', click(/^Account security/), 'Account security')
	await step('16 back from section', click('Back'), 'Settings')
	const body = () => within(document.querySelector<HTMLElement>('.popup__body')!)
	await step('16a vault section', async () => user.click(await body().findByRole('button', { name: /^Vault/ })), 'Vault')
	await step('16b folders', async () => user.click(await body().findByRole('button', { name: /^Folders/ })), 'Folders')
	await step('16c back to the parent section', click('Back'), 'Vault')
	await step('16d back to settings', click('Back'), 'Settings')
	await step('17 send', click('Send'), 'Send')
	await step('18 new send', click('New'), 'New send')
	await step('19 back from new send', click('Back'), 'Send')
	return trace
}

/** The vault tab when the background does not answer the snapshot read. */
async function unanswered(): Promise<Record<string, unknown>> {
	fakeBackground({ unanswered: ['vault.snapshot'] })
	render(<App />)
	const alert = await screen.findByRole('alert')
	return { ...chrome(), alert: alert.textContent }
}

describe('Shell chrome golden record', () => {
	it('shows the recorded title, header actions and tab bar on every page of a walk', async () => {
		const got = { walk: await walk(), unanswered: (cleanup(), await unanswered()) }
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		expect(got).toEqual(JSON.parse(readFileSync(GOLDEN, 'utf8')))
	}, 30_000)
})
