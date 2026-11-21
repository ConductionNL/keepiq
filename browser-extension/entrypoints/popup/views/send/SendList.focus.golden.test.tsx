// @vitest-environment happy-dom
import { act, cleanup, getConfig, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { fakeBackground, sendRow } from '../../testing'
import { SendProvider } from '../../hooks/useSends'
import { SendList } from './SendList'

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/send/SendList.focus.golden.json')

afterEach(cleanup)

// The fake background answers in microtasks, so one macrotask runs every reply and the renders after it.
const settle = () => act(() => new Promise<void>((done) => setTimeout(done)))
const sends = (...ids: string[]) => ids.map((id) => sendRow({ id }))

/** Which control holds focus: its row's index and its name, or `body`. */
function focused(): string {
	const active = document.activeElement as HTMLElement | null
	if (!active || active === document.body) return 'body'
	const row = active.closest('li')
	const index = row ? [...row.parentElement!.children].indexOf(row) : -1
	return `row ${index}: ${(active.getAttribute('aria-label') ?? active.textContent ?? '').replace(/\d+/g, '#')}`
}

function open(options: Parameters<typeof fakeBackground>[0], focusId: string | null = null) {
	fakeBackground(options)
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast: vi.fn() }}>
			<SendProvider>
				<SendList onNew={vi.fn()} focusId={focusId} />
			</SendProvider>
		</ShellContext.Provider>,
	)
	return userEvent.setup()
}

async function removeRow(user: ReturnType<typeof userEvent.setup>, index: number, trace: string[]) {
	const rows = within(await screen.findByRole('list')).getAllByRole('listitem')
	await user.click(within(rows[index]!).getByRole('button', { name: /^Remove/ }))
	await settle()
	trace.push(`dialog open: ${focused()}`)
	const dialog = screen.getByRole('dialog')
	await user.click(within(dialog).getByRole('button', { name: /Remove|Delete/ }))
	await settle()
	trace.push(`removed: ${focused()}`)
}

const WORLDS: Record<string, () => Promise<string[]>> = {
	'no focus asked': async () => {
		open({ send: { sends: sends('a', 'b') } })
		await settle()
		return [focused()]
	},
	'a listed row': async () => {
		open({ send: { sends: sends('a', 'b', 'c') } }, 'b')
		await settle()
		return [focused()]
	},
	'a row not listed': async () => {
		open({ send: { sends: sends('a', 'b') } }, 'gone')
		await settle()
		return [focused()]
	},
	'a row asked, the load fails': async () => {
		open({ unanswered: ['send.list'] }, 'a')
		await settle()
		return [focused()]
	},
	'the load fails, nothing asked': async () => {
		open({ unanswered: ['send.list'] })
		await settle()
		return [focused()]
	},
	'a removed middle row': async () => {
		const trace: string[] = []
		await removeRow(open({ send: { sends: sends('a', 'b', 'c') } }), 1, trace)
		return trace
	},
	'a removed last row': async () => {
		const trace: string[] = []
		await removeRow(open({ send: { sends: sends('a', 'b', 'c') } }), 2, trace)
		return trace
	},
	'the only row removed': async () => {
		const trace: string[] = []
		await removeRow(open({ send: { sends: sends('a') } }), 0, trace)
		return trace
	},
	'a listed row, then an earlier row removed': async () => {
		const trace: string[] = []
		const user = open({ send: { sends: sends('a', 'b', 'c') } }, 'c')
		await settle()
		trace.push(focused())
		await removeRow(user, 0, trace)
		return trace
	},
	'a removal, then a remove cancelled': async () => {
		const trace: string[] = []
		const user = open({ send: { sends: sends('a', 'b', 'c') } })
		await removeRow(user, 0, trace)
		const rows = within(screen.getByRole('list')).getAllByRole('listitem')
		await user.click(within(rows[1]!).getByRole('button', { name: /^Remove/ }))
		await settle()
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }))
		await settle()
		trace.push(`cancelled: ${focused()}`)
		return trace
	},
	'a removal that fails': async () => {
		const trace: string[] = []
		await removeRow(open({ send: { sends: sends('a', 'b') }, replies: { 'send.delete': { ok: false, error: { code: 'server_error' } } } }), 1, trace)
		return trace
	},
}

describe('SendList focus golden record', () => {
	it('moves focus as recorded', async () => {
		const got: Record<string, string[]> = {}
		for (const [name, run] of Object.entries(WORLDS)) {
			got[name] = await run()
			cleanup()
			;(document.activeElement as HTMLElement | null)?.blur?.()
		}
		// Both popup projects run this file; only the production one writes the record.
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1' && !getConfig().reactStrictMode) writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		const want = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, string[]>
		for (const name of Object.keys(want)) expect(got[name], name).toEqual(want[name])
		expect(Object.keys(got)).toEqual(Object.keys(want))
	}, 60_000)
})
