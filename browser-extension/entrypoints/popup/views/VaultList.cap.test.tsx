// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { everythingVisible, fakeBackground, itemMeta, snapshotReply, type UnlockedSnapshot } from '../testing'
import { VaultList } from './VaultList'

// Thousands of real cards take seconds to render; the cap only needs a row per item.
vi.mock('../components/ItemCard', () => ({ ItemCard: ({ item }: { item: { name: string } }) => <li>{item.name}</li> }))

afterEach(cleanup)
beforeEach(everythingVisible)

function renderList(snapshot: UnlockedSnapshot) {
	fakeBackground({ snapshot })
	const items = { add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn(), send: vi.fn() }
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast: vi.fn(), launch: vi.fn(), items, refreshState: vi.fn() }}>
			<VaultList snapshot={snapshot} tab={{ id: 1, windowId: 1, url: undefined, host: null }} syncNow={vi.fn()} onOpen={vi.fn()} />
		</ShellContext.Provider>,
	)
}

function listed(): string[] {
	return within(screen.getByRole('region', { name: 'Items' })).queryAllByRole('listitem').map((row) => row.textContent!)
}

const rows = (n: number) => Array.from({ length: n }, (_, i) => itemMeta({ id: String(i), name: `Item ${String(i).padStart(4, '0')}`, hasLogin: false }))

describe('row cap', () => {
	it('lists all 2,000 rows without a hint', () => {
		renderList(snapshotReply({ items: rows(2000) }))
		expect(listed()).toHaveLength(2000)
		expect(screen.queryByText(/^Showing the first/)).toBeNull()
	})

	it('lists the first 2,000 of 2,001 rows and asks for a narrower search', () => {
		renderList(snapshotReply({ items: rows(2001) }))
		const names = listed()
		expect(names).toHaveLength(2000)
		expect(names.at(-1)).toBe('Item 1999')
		expect(screen.getByText('Showing the first 2,000, refine your search')).toBeTruthy()
	})
})
