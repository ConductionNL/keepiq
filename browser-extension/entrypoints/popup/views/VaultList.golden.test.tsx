// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CurrentTab } from '../hooks/useCurrentTab'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { everythingVisible, fakeBackground, itemMeta, snapshotReply, type UnlockedSnapshot } from '../testing'
import { VaultList } from './VaultList'

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/VaultList.golden.json')

afterEach(cleanup)
beforeEach(everythingVisible)

const noTab: CurrentTab = { id: 1, windowId: 1, url: undefined, host: null }
const githubTab: CurrentTab = { id: 7, windowId: 1, url: 'https://github.com/login', host: 'github.com' }
const hoursAgo = (hours: number) => new Date(Date.now() - hours * 3_600_000).toISOString()
const sync = (overrides: Partial<UnlockedSnapshot['sync']> = {}): UnlockedSnapshot['sync'] => ({ syncing: false, syncedAt: hoursAgo(3), offline: false, lastError: null, ...overrides })
const three = () => [itemMeta({ id: '1', name: 'bank' }), itemMeta({ id: '2', name: 'Amazon', typeId: 't-note' }), itemMeta({ id: '3', name: 'apple' })]

/** The rendered list, with React's generated ids numbered in order of appearance. */
function markup(): string {
	const ids = new Map<string, string>()
	return document.body.innerHTML.replace(/«[^»]*»|:r[0-9a-z]+:|_r_[0-9a-z]+_/g, (id) => {
		if (!ids.has(id)) ids.set(id, `id${ids.size}`)
		return ids.get(id)!
	})
}

function renderList(snapshot: UnlockedSnapshot, tab: CurrentTab = noTab) {
	const calls: string[] = []
	fakeBackground({ snapshot })
	const items = { add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn(), send: vi.fn() }
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast: vi.fn(), launch: vi.fn(), items, refreshState: vi.fn() }}>
			<VaultList snapshot={snapshot} tab={tab} syncNow={() => calls.push('syncNow')} onOpen={(id) => calls.push(`open ${id}`)} />
		</ShellContext.Provider>,
	)
	return { calls, user: userEvent.setup() }
}

/** Renders a world and returns its markup; `then` drives it further and may add to the trace. */
async function world(snapshot: UnlockedSnapshot, then?: (user: ReturnType<typeof userEvent.setup>, calls: string[]) => Promise<void>, tab?: CurrentTab) {
	const { calls, user } = renderList(snapshot, tab)
	if (then) await then(user, calls)
	await waitFor(() => expect(document.body.innerHTML).not.toBe(''))
	return { html: markup(), calls }
}

const click = (name: RegExp | string) => async (user: ReturnType<typeof userEvent.setup>) => {
	for (const button of screen.getAllByRole('button', { name })) await user.click(button)
}

const WORLDS: Record<string, () => Promise<unknown>> = {
	'first sync failed': () => world(snapshotReply({ items: null, sync: sync({ syncedAt: null, lastError: 'network' }) }), click('Try again')),
	'first sync failed, still syncing': () => world(snapshotReply({ items: null, sync: sync({ syncedAt: null, syncing: true, lastError: 'network' }) })),
	'first sync running': () => world(snapshotReply({ items: null, sync: sync({ syncedAt: null, syncing: true }) })),
	'first sync, offline and busy flags': () => world(snapshotReply({ items: null, sync: sync({ offline: true, lastError: 'busy' }) })),
	'empty vault': () => world(snapshotReply({ items: [] })),
	'rows': () => world(snapshotReply({ items: three() }), async (user) => {
		for (const row of document.querySelectorAll<HTMLElement>('.items .item__main')) await user.click(row)
	}),
	'no match for a search': () => world(snapshotReply({ items: three() }), async (user, calls) => {
		await user.type(screen.getByRole('searchbox'), 'zzz')
		calls.push(markup())
		await click('Clear filters')(user)
	}),
	'no match for a blank search and a type': () => world(snapshotReply({ items: [itemMeta({ id: '1', name: 'bank' })] }), async (user) => {
		await user.type(screen.getByRole('searchbox'), '   ')
		await user.click(screen.getByRole('button', { name: 'Note' }))
	}),
	'offline with a last sync': () => world(snapshotReply({ items: three(), sync: sync({ offline: true }) }), click('Sync now')),
	'offline, never synced': () => world(snapshotReply({ items: three(), sync: sync({ offline: true, syncedAt: null }) })),
	'offline, syncing': () => world(snapshotReply({ items: three(), sync: sync({ offline: true, syncing: true }) })),
	'busy': () => world(snapshotReply({ items: three(), sync: sync({ lastError: 'busy' }) })),
	'busy while offline': () => world(snapshotReply({ items: three(), sync: sync({ lastError: 'busy', offline: true }) })),
	'other error': () => world(snapshotReply({ items: three(), sync: sync({ lastError: 'network' }) })),
	'all blocked with a reason': () => world(snapshotReply({ items: [itemMeta({ id: '1', blocked: true, blockedReason: 'suite_revoked' }), itemMeta({ id: '2', name: 'b', blocked: true })] })),
	'all blocked without a reason': () => world(snapshotReply({ items: [itemMeta({ id: '1', blocked: true })] })),
	'one blocked of two': () => world(snapshotReply({ items: [itemMeta({ id: '1', blocked: true }), itemMeta({ id: '2', name: 'b' })] })),
	'suggestions': () => world(snapshotReply({ items: [...three(), itemMeta({ id: '4', name: 'Blocked', blocked: true })], suggestionIds: ['4', '3', 'gone', '1'] }), undefined, githubTab),
}

const got: Record<string, unknown> = {}

describe('VaultList golden record', () => {
	it('renders every recorded world as recorded', async () => {
		for (const [name, run] of Object.entries(WORLDS)) {
			got[name] = await run()
			cleanup()
		}
	}, 120_000)


	it('matches the record', () => {
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		const want = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, unknown>
		for (const name of Object.keys(want)) expect(got[name], name).toEqual(want[name])
		expect(Object.keys(got)).toEqual(Object.keys(want))
	})
})
