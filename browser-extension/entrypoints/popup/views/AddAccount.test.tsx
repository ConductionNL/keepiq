// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { dispatchReturning } from '../testing'
import { AddAccount } from './AddAccount'

let permissionRequest: ReturnType<typeof vi.fn>

beforeEach(() => {
	fakeBrowser.reset()
	permissionRequest = vi.fn(async () => true)
	vi.spyOn(browser.permissions, 'request').mockImplementation(permissionRequest as never)
})
afterEach(cleanup)

async function fill(serverUrl = 'cloud.example.org', username = 'alice', appPassword = 'app-pw') {
	const user = userEvent.setup()
	await user.type(screen.getByLabelText('Server URL'), serverUrl)
	await user.type(screen.getByLabelText('Username'), username)
	await user.type(screen.getByLabelText('App password'), appPassword)
	return user
}

describe('AddAccount', () => {
	it('enables Add account only when every field has a value', async () => {
		render(<AddAccount dispatch={dispatchReturning()} />)
		const button = screen.getByRole('button', { name: 'Add account' })
		expect(button).toHaveProperty('disabled', true)
		await fill()
		expect(button).toHaveProperty('disabled', false)
	})

	it('links to the server security settings once the URL is entered', async () => {
		render(<AddAccount dispatch={dispatchReturning()} />)
		const user = userEvent.setup()
		await user.type(screen.getByLabelText('Server URL'), 'cloud.example.org')
		await user.tab()
		const link = screen.getByRole('link', { name: 'open security settings' })
		expect(link.getAttribute('href')).toBe('https://cloud.example.org/index.php/settings/user/security')
		expect(link.getAttribute('target')).toBe('_blank')
	})

	it('keeps the install subpath, and asks permission for the whole host', async () => {
		const dispatch = dispatchReturning()
		render(<AddAccount dispatch={dispatch} />)
		const user = await fill('https://example.org/nextcloud/apps/keepiq')
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(permissionRequest).toHaveBeenCalledWith({ origins: ['https://example.org/*'] })
		expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ serverUrl: 'https://example.org/nextcloud' }))
	})

	it('requests the host permission, then adds with the server URL', async () => {
		const dispatch = dispatchReturning()
		const onAdded = vi.fn()
		render(<AddAccount dispatch={dispatch} onAdded={onAdded} />)
		const user = await fill('https://cloud.example.org/index.php/apps/keepiq/vault')
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(permissionRequest).toHaveBeenCalledWith({ origins: ['https://cloud.example.org/*'] })
		expect(dispatch).toHaveBeenCalledWith({ kind: 'accounts.add', serverUrl: 'https://cloud.example.org', username: 'alice', appPassword: 'app-pw' })
		expect(onAdded).toHaveBeenCalled()
	})

	it('stops at plain http on a public host without asking anything', async () => {
		const dispatch = dispatchReturning()
		render(<AddAccount dispatch={dispatch} />)
		const user = await fill('http://cloud.example.org')
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(screen.getByRole('alert').textContent).toBe('Use https for this server')
		expect(permissionRequest).not.toHaveBeenCalled()
		expect(dispatch).not.toHaveBeenCalled()
	})

	it('keeps the values when the permission is declined', async () => {
		permissionRequest.mockResolvedValue(false)
		const dispatch = dispatchReturning()
		render(<AddAccount dispatch={dispatch} />)
		const user = await fill()
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(screen.getByRole('alert').textContent).toBe('Keepiq needs permission to reach cloud.example.org')
		expect(dispatch).not.toHaveBeenCalled()
		expect(screen.getByLabelText<HTMLInputElement>('App password').value).toBe('app-pw')
	})

	it('clears only the app password after a 401', async () => {
		render(<AddAccount dispatch={dispatchReturning({ ok: false, code: 'unauthorized', message: '' })} />)
		const user = await fill()
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(screen.getByRole('alert').textContent).toBe('Wrong username or app password')
		expect(screen.getByLabelText<HTMLInputElement>('App password').value).toBe('')
		expect(screen.getByLabelText<HTMLInputElement>('Username').value).toBe('alice')
		expect(document.activeElement).toBe(screen.getByLabelText('App password'))
	})

	it('puts focus back on the server URL after a server error', async () => {
		render(<AddAccount dispatch={dispatchReturning({ ok: false, code: 'unreachable', message: '' })} />)
		const user = await fill()
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(document.activeElement).toBe(screen.getByLabelText('Server URL'))
	})

	it('names the host in server errors', async () => {
		render(<AddAccount dispatch={dispatchReturning({ ok: false, code: 'keepiq_missing', message: '' })} />)
		const user = await fill()
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect(screen.getByRole('alert').textContent).toBe('Keepiq is not installed on cloud.example.org')
	})

	it('restores the server URL and username, never the app password, after the popup reopens', async () => {
		render(<AddAccount dispatch={dispatchReturning()} />)
		await fill()
		cleanup()
		render(<AddAccount dispatch={dispatchReturning()} />)
		await waitFor(() => expect(screen.getByLabelText<HTMLInputElement>('Server URL').value).toBe('cloud.example.org'))
		expect(screen.getByLabelText<HTMLInputElement>('Username').value).toBe('alice')
		expect(screen.getByLabelText<HTMLInputElement>('App password').value).toBe('')
	})
})
