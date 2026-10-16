// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { FolderMeta } from '@/src/messages'
import { fakeBackground, sentOf } from '../testing'
import { FolderPicker } from './FolderPicker'

afterEach(cleanup)

const folders: FolderMeta[] = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }]

function renderPicker(current: string | null, list = folders) {
	const sent = fakeBackground()
	const onPick = vi.fn()
	const onCancel = vi.fn()
	render(<FolderPicker title="Choose folder" folders={list} current={current} onPick={onPick} onCancel={onCancel} />)
	return { sent, onPick, onCancel, user: userEvent.setup() }
}

const choices = () => within(screen.getByRole('dialog')).getAllByRole('button').map((button) => [button.textContent, button.getAttribute('aria-current')])

describe('FolderPicker', () => {
	it('lists No folder, the tree and New folder, with the current folder marked', () => {
		renderPicker('c')
		expect(choices()).toEqual([['No folder', null], ['Work', null], ['ClientsCurrent folder', 'true'], ['New folder', null], ['Cancel', null]])
	})

	it('lists only No folder and New folder without folders', () => {
		renderPicker(null, [])
		expect(choices().map(([text]) => text)).toEqual(['No folderCurrent folder', 'New folder', 'Cancel'])
	})

	it('returns the pick without a request, and null for No folder', async () => {
		const { sent, onPick, user } = renderPicker('w')
		await user.click(screen.getByRole('button', { name: 'Clients' }))
		expect(onPick).toHaveBeenLastCalledWith('c')
		await user.click(screen.getByRole('button', { name: 'No folder' }))
		expect(onPick).toHaveBeenLastCalledWith(null)
		expect(sent).not.toHaveBeenCalled()
	})

	it('creates a root folder and picks it', async () => {
		const { sent, onPick, user } = renderPicker(null)
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(screen.getByLabelText('Folder name'), 'Banking')
		await user.click(screen.getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(onPick).toHaveBeenCalledWith('f-new'))
		expect(sentOf(sent, 'folder.create')).toEqual([{ kind: 'folder.create', accountId: 'a1', name: 'Banking', parentId: null }])
	})
})
