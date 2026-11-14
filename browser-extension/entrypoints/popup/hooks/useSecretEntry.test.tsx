// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import type { UnlockMethod } from '@/src/messages'
import { account } from '../testing'
import { useSecretEntry } from './useSecretEntry'

afterEach(cleanup)

type Reply = { ok: false; code: string } | undefined

function SecretField({ unlock }: { unlock: (method: UnlockMethod) => Promise<Reply> }) {
	const { secret, setSecret, error, field, submit } = useSecretEntry(account())
	return (
		<form onSubmit={(event) => {
			event.preventDefault()
			void submit(unlock)
		}}>
			<input aria-label="Secret" ref={field} value={secret} onChange={(event) => setSecret(event.target.value)} />
			<button type="submit">Send</button>
			<p role="alert">{error}</p>
		</form>
	)
}

describe('useSecretEntry', () => {
	it('returns focus to the field after a refused secret, wherever the submit left it', async () => {
		render(<SecretField unlock={async () => ({ ok: false, code: 'invalid_master_password' })} />)
		const user = userEvent.setup()
		await user.type(screen.getByLabelText('Secret'), 'wrong')
		await user.click(screen.getByRole('button', { name: 'Send' }))
		await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('Invalid master password'))
		expect(document.activeElement).toBe(screen.getByLabelText('Secret'))
	})

	it('says Keepiq is not responding when no reply comes', async () => {
		render(<SecretField unlock={async () => undefined} />)
		const user = userEvent.setup()
		await user.type(screen.getByLabelText('Secret'), 'secret')
		await user.click(screen.getByRole('button', { name: 'Send' }))
		await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('Keepiq is not responding. Reload the extension and reopen this popup.'))
	})
})
