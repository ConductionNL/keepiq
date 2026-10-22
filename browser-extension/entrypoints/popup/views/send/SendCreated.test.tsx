// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { fakeBackground, fakeClipboard, sendRow } from '../../testing'
import { SendCreated } from './SendCreated'

afterEach(cleanup)

const link = 'https://cloud.example.org/index.php/apps/keepiq/public/send/abc#k=key'

function renderCreated(row = sendRow({ maxViews: 3 })) {
	fakeBackground()
	const footer = document.body.appendChild(document.createElement('div'))
	const toast = vi.fn()
	const onDone = vi.fn()
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, footer, toast }}>
			<SendCreated row={row} link={link} onDone={onDone} />
		</ShellContext.Provider>,
	)
	return { onDone, toast }
}

describe('SendCreated', () => {
	it('shows the link in a read-only field and takes focus on its heading', () => {
		renderCreated()
		const field = screen.getByLabelText('Link') as HTMLInputElement
		expect(field.value).toBe(link)
		expect(field.readOnly).toBe(true)
		expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'Your send is ready' }))
	})

	it('has one Copy link, which copies the link', async () => {
		const { toast } = renderCreated()
		const user = userEvent.setup()
		const clipboard = fakeClipboard()
		const copies = screen.getAllByRole('button', { name: /copy/i })
		expect(copies).toHaveLength(1)
		await user.click(copies[0]!)
		expect(clipboard).toHaveBeenCalledWith(link)
		expect(toast).toHaveBeenCalledWith('Link copied')
	})

	it('says the link is shown once and when the content burns, for a send without a password', () => {
		renderCreated()
		expect(screen.getByText(/shown only until you close this window, and the content burns after 3 views\./)).toBeTruthy()
	})

	it('says to share the password separately, for a password send', () => {
		renderCreated(sendRow({ hasPassword: true }))
		expect(screen.getByText(/Share the access password separately from the link/)).toBeTruthy()
		expect(screen.queryByText(/shown only until/)).toBeNull()
	})

	it('goes back with Done', async () => {
		const { onDone } = renderCreated()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Done' }))
		expect(onDone).toHaveBeenCalled()
	})
})
