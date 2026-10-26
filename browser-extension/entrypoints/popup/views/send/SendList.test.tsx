// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import App from '../../App'
import { fakeBackground, fakeClipboard, sendRow, sentOf } from '../../testing'

afterEach(() => {
	cleanup()
	setLocale('en')
})

const rows = () => within(screen.getByRole('list')).getAllByRole('listitem')

describe('the sends list', () => {
	it('lists the server\'s sends in its order, for the shown account', async () => {
		const sent = fakeBackground({
			lastTab: 'send',
			send: {
				sends: [
					sendRow({ id: 's1', payloadType: 'credential', hasPassword: true, link: 'https://cloud.example.org/index.php/apps/keepiq/public/send/t1' }),
					sendRow({ id: 's2', expiresAt: null }),
					sendRow({ id: 's3', expiresAt: '2020-01-01T00:00:00Z' }),
				],
			},
		})
		render(<App />)
		await screen.findByRole('list')
		const [credential, never, expired] = rows()
		expect(credential!.textContent).toContain('Credential send, ')
		expect(credential!.textContent).toContain('2 of 5 views left')
		expect(within(credential!).getByText('Password')).toBeTruthy()
		expect(never!.textContent).toContain('Text send, ')
		expect(never!.textContent).toContain('Never expires')
		expect(never!.textContent).toContain('Link no longer available')
		expect(expired!.textContent).toContain('Expired')
		expect(expired!.textContent).not.toContain('Link no longer available')
		expect(within(expired!).queryByRole('button', { name: /^Copy link/ })).toBeNull()
		expect(within(expired!).getByRole('button', { name: /^Remove/ })).toBeTruthy()
		expect(sentOf(sent, 'send.list')).toEqual([{ kind: 'send.list', accountId: 'a1' }])
	})

	it('copies the link of a password send from an earlier session', async () => {
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ hasPassword: true, link: 'https://cloud.example.org/index.php/apps/keepiq/public/send/t1' })] } })
		render(<App />)
		// After `setup`, which installs a clipboard of its own.
		const user = userEvent.setup()
		const clipboard = fakeClipboard()
		await user.click(await screen.findByRole('button', { name: /^Copy link, Text send/ }))
		expect(clipboard).toHaveBeenCalledWith('https://cloud.example.org/index.php/apps/keepiq/public/send/t1')
		expect(await screen.findByText('Link copied')).toBeTruthy()
	})

	it('shows the empty state with New send, which opens the form', async () => {
		fakeBackground({ lastTab: 'send' })
		render(<App />)
		expect(await screen.findByRole('heading', { name: 'No sends yet' })).toBeTruthy()
		await userEvent.setup().click(screen.getByRole('button', { name: 'New send' }))
		expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('New send')
	})

	it('shows no list when it fails to load, and retries', async () => {
		let fail = true
		fakeBackground({
			lastTab: 'send',
			replies: { 'send.list': () => (fail ? { ok: false, error: { code: 'offline' } } : { ok: true, value: { sends: [sendRow()], passwordProtection: true } }) },
		})
		render(<App />)
		expect((await screen.findByRole('alert')).textContent).toContain('Could not load your sends')
		expect(screen.queryByRole('list')).toBeNull()
		const user = userEvent.setup()
		const first = screen.getByRole('alert')
		await user.click(screen.getByRole('button', { name: 'Retry' }))
		// A new alert, not the same one left standing, so a second failure is announced anew.
		await waitFor(() => expect(screen.getByRole('alert')).not.toBe(first))
		fail = false
		await user.click(screen.getByRole('button', { name: 'Retry' }))
		await waitFor(() => expect(rows()).toHaveLength(1))
	})

	it('names two sends of the same minute apart', async () => {
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ id: 's1' }), sendRow({ id: 's2' })] } })
		render(<App />)
		await screen.findByRole('list')
		const names = screen.getAllByRole('button', { name: /^Remove, / }).map((button) => button.getAttribute('aria-label'))
		expect(names[0]).toMatch(/ \(1\)$/)
		expect(names[1]).toMatch(/ \(2\)$/)
	})

	it('moves focus to the next row after a removal, and to New send after the last', async () => {
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ id: 's1', createdAt: '2026-10-22T09:00:00Z' }), sendRow({ id: 's2', createdAt: '2026-10-22T08:00:00Z' })] } })
		render(<App />)
		const user = userEvent.setup()
		await screen.findByRole('list')
		await user.click(within(rows()[0]!).getByRole('button', { name: /^Remove/ }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove' }))
		await waitFor(() => expect(rows()).toHaveLength(1))
		await waitFor(() => expect(document.activeElement).toBe(within(rows()[0]!).getByRole('button', { name: /^Remove/ })))
		await user.click(document.activeElement as HTMLElement)
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove' }))
		await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'New send' })))
	})

	it('goes to the unlock screen when the vault locked meanwhile', async () => {
		const sent = fakeBackground({ lastTab: 'send', replies: { 'send.list': { ok: false, error: { code: 'locked' } } } })
		render(<App />)
		await waitFor(() => expect(sentOf(sent, 'vault.status')).toHaveLength(2))
	})

	it('removes a send after the confirm, and says so', async () => {
		const sent = fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ id: 's1' }), sendRow({ id: 's2' })] } })
		render(<App />)
		const user = userEvent.setup()
		await screen.findByRole('list')
		await user.click(within(rows()[0]!).getByRole('button', { name: /^Remove/ }))
		const dialog = screen.getByRole('dialog', { name: 'Remove send' })
		expect(dialog.textContent).toContain('Anyone with the link loses access.')
		await user.click(within(dialog).getByRole('button', { name: 'Remove' }))
		await waitFor(() => expect(rows()).toHaveLength(1))
		expect(sentOf(sent, 'send.delete')).toEqual([{ kind: 'send.delete', accountId: 'a1', id: 's1' }])
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(await screen.findByText('Send removed')).toBeTruthy()
	})

	it('keeps the row and says why when the remove fails', async () => {
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow()] }, replies: { 'send.delete': { ok: false, error: { code: 'offline' } } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: /^Remove/ }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Remove' }))
		expect((await within(screen.getByRole('dialog')).findByRole('alert')).textContent).toContain('Could not reach the server')
		expect(rows()).toHaveLength(1)
	})

	it('reads in Dutch', async () => {
		setLocale('nl')
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ maxViews: 1, remainingViews: 1, expiresAt: null })] } })
		render(<App />)
		await screen.findByRole('list')
		expect(rows()[0]!.textContent).toContain('Nog 1 van 1 weergave')
		expect(rows()[0]!.textContent).toContain('Verloopt nooit')
		expect(rows()[0]!.textContent).toContain('Link niet meer beschikbaar')
	})
})
