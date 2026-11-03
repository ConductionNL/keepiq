// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import type { SyncStatus, VaultSnapshotReply } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { everythingVisible, fakeBackground, snapshotReply, type UnlockedSnapshot } from '../../testing'
import { Vault } from './Vault'

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/settings/Vault.golden.json')

afterEach(cleanup)
beforeEach(everythingVisible)

const sync = (overrides: Partial<SyncStatus> = {}): SyncStatus => ({ syncing: false, syncedAt: '2026-10-20T08:15:00Z', offline: false, lastError: null, ...overrides })

/** The settings page with React's generated ids numbered in order of appearance. */
function markup(): string {
	const ids = new Map<string, string>()
	return document.body.innerHTML.replace(/«[^»]*»|:r[0-9a-z]+:|_r_[0-9a-z]+_/g, (id) => {
		if (!ids.has(id)) ids.set(id, `id${ids.size}`)
		return ids.get(id)!
	})
}

interface World {
	snapshot?: UnlockedSnapshot
	snapshotFailed?: boolean
	/** What Sync now resolves to; `click` presses it. */
	after?: VaultSnapshotReply | undefined
	click?: boolean
	exportDisabled?: boolean
	unanswered?: string[]
	/** Presses Sync now once more, on a sync that does not end, and records the page meanwhile. */
	again?: boolean
}

async function world({ snapshot, snapshotFailed = false, after, click = false, exportDisabled = false, unanswered, again = false }: World) {
	const calls: string[] = []
	fakeBackground({ accountSettings: { exportDisabled }, unanswered })
	const syncNow = async () => {
		calls.push('syncNow')
		if (calls.filter((call) => call === 'syncNow').length > 1) await new Promise(() => undefined)
		return after
	}
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, syncNow }}>
			<Vault onOpen={(section) => calls.push(`open ${section}`)} snapshot={snapshot} snapshotFailed={snapshotFailed} reload={() => calls.push('reload')} />
		</ShellContext.Provider>,
	)
	const user = userEvent.setup()
	await waitFor(() => expect(document.querySelector('.menu')).not.toBeNull())
	// Past Loading's delay, so a spinner shows if there is one.
	await new Promise((done) => setTimeout(done, 350))
	if (click) await user.click(screen.getByRole('button', { name: /Sync now/ }))
	await new Promise((done) => setTimeout(done, 20))
	if (again) {
		await user.click(screen.getByRole('button', { name: /Sync now/ }))
		await new Promise((done) => setTimeout(done, 20))
		calls.push(markup())
	}
	for (const button of screen.queryAllByRole('button', { name: /Folders/ })) await user.click(button)
	return { html: markup(), calls }
}

const unlocked = (status: Partial<SyncStatus> = {}) => snapshotReply({ sync: sync(status) })

const WORLDS: Record<string, World> = {
	'loaded': { snapshot: unlocked() },
	'snapshot loading': {},
	'snapshot failed': { snapshotFailed: true },
	'snapshot failed but cached': { snapshot: unlocked(), snapshotFailed: true },
	'settings unanswered': { snapshot: unlocked(), unanswered: ['account.settings.get'] },
	'settings unanswered, no snapshot': { unanswered: ['account.settings.get'] },
	'a failed sync, then one still running': { snapshot: unlocked(), click: true, after: undefined, again: true },
	'never synced': { snapshot: unlocked({ syncedAt: null }) },
	'syncing': { snapshot: unlocked({ syncing: true }), click: true, after: unlocked() },
	'export turned off': { snapshot: unlocked(), exportDisabled: true },
	'export turned off, no snapshot': { exportDisabled: true },
	'sync ends well': { snapshot: unlocked(), click: true, after: unlocked() },
	'sync gets no answer': { snapshot: unlocked(), click: true, after: undefined },
	'sync ends locked': { snapshot: unlocked(), click: true, after: { state: 'locked' } as VaultSnapshotReply },
	'sync ends offline': { snapshot: unlocked(), click: true, after: unlocked({ offline: true }) },
	'sync ends offline with an error': { snapshot: unlocked(), click: true, after: unlocked({ offline: true, lastError: 'network' }) },
	'sync ends with an error': { snapshot: unlocked(), click: true, after: unlocked({ lastError: 'network' }) },
}

describe('Vault settings golden record', () => {
	it('renders every recorded world as recorded', async () => {
		const got: Record<string, unknown> = {}
		for (const [name, options] of Object.entries(WORLDS)) {
			got[name] = await world(options)
			cleanup()
		}
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') writeFileSync(GOLDEN, JSON.stringify(got, null, '\t') + '\n')
		const want = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, unknown>
		for (const name of Object.keys(want)) expect(got[name], name).toEqual(want[name])
		expect(Object.keys(got)).toEqual(Object.keys(want))
	}, 120_000)
})
