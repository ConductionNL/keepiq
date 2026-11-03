// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, itemMeta, sentOf } from '../testing'
import { DeleteItemDialog, MoveItemDialog } from './ItemDialogs'

afterEach(cleanup)

function withShell(children: React.ReactNode, refreshState = vi.fn()) {
	return <ShellContext.Provider value={{ ...DEFAULT_SHELL, refreshState }}>{children}</ShellContext.Provider>
}

describe('DeleteItemDialog', () => {
	it('names the item and deletes it on confirm', async () => {
		const sent = fakeBackground()
		const onDeleted = vi.fn()
		render(withShell(<DeleteItemDialog item={itemMeta()} onDeleted={onDeleted} onCancel={() => {}} />))
		expect(screen.getByRole('dialog', { name: 'Delete item' }).textContent).toContain('Move GitHub to the trash? It stops being shared, also after a restore from the trash in the Keepiq web app.')
		await userEvent.setup().click(screen.getByRole('button', { name: 'Delete' }))
		await waitFor(() => expect(onDeleted).toHaveBeenCalled())
		expect(sentOf(sent, 'item.delete')).toEqual([{ kind: 'item.delete', accountId: 'a1', id: 'i1' }])
	})

	it('stays open on a failure and retries', async () => {
		let attempts = 0
		fakeBackground({ replies: { 'item.delete': () => (++attempts === 1 ? { ok: false, error: { code: 'offline' } } : { ok: true, value: null }) } })
		const onDeleted = vi.fn()
		render(withShell(<DeleteItemDialog item={itemMeta()} onDeleted={onDeleted} onCancel={() => {}} />))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Delete' }))
		expect((await screen.findByRole('alert')).textContent).toContain('Could not reach the server')
		await user.click(screen.getByRole('button', { name: 'Try again' }))
		await waitFor(() => expect(onDeleted).toHaveBeenCalled())
	})

	it('hands a locked vault back to the popup state', async () => {
		fakeBackground({ replies: { 'item.delete': { ok: false, error: { code: 'locked' } } } })
		const refreshState = vi.fn()
		render(withShell(<DeleteItemDialog item={itemMeta()} onDeleted={() => {}} onCancel={() => {}} />, refreshState))
		await userEvent.setup().click(screen.getByRole('button', { name: 'Delete' }))
		await waitFor(() => expect(refreshState).toHaveBeenCalled())
	})
})

describe('MoveItemDialog', () => {
	const folders = [{ id: 'w', name: 'Work', parentId: null }]

	it('sends only the folder, and null for No folder', async () => {
		const sent = fakeBackground()
		const onMoved = vi.fn()
		render(withShell(<MoveItemDialog item={itemMeta({ folderId: 'w' })} folders={folders} onMoved={onMoved} onCancel={() => {}} />))
		await userEvent.setup().click(screen.getByRole('button', { name: 'No folder' }))
		await waitFor(() => expect(onMoved).toHaveBeenCalledWith(null))
		expect(sentOf(sent, 'item.update')).toEqual([{ kind: 'item.update', accountId: 'a1', id: 'i1', patch: { folderId: null } }])
	})

	it('closes without a request when the folder stays the same', async () => {
		const sent = fakeBackground()
		const onCancel = vi.fn()
		render(withShell(<MoveItemDialog item={itemMeta({ folderId: 'w' })} folders={folders} onMoved={() => {}} onCancel={onCancel} />))
		await userEvent.setup().click(screen.getByRole('button', { name: /^Work/ }))
		expect(onCancel).toHaveBeenCalled()
		expect(sentOf(sent, 'item.update')).toEqual([])
	})
})
