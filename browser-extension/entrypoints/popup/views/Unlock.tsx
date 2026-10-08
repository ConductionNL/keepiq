import { useRef, useState, type FormEvent } from 'react'
import type { AccountSummary } from '@/src/messages'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { Identity } from '../components/Identity'
import { LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'

export function Unlock({ account, dispatch }: { account: AccountSummary; dispatch: Dispatch }) {
	const [masterPassword, setMasterPassword] = useState('')
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const logOut = useLogOutConfirm()
	const field = useRef<HTMLInputElement>(null)

	async function submit(event: FormEvent) {
		event.preventDefault()
		setBusy(true)
		setError(null)
		const result = await dispatch({ kind: 'vault.unlock', accountId: account.id, method: { type: 'masterPassword', masterPassword } })
		setBusy(false)
		// On success this view unmounts; either way the password leaves React state.
		setMasterPassword('')
		if (!result.ok) {
			setError(errorText(result.code, account.host, result.message))
			field.current?.focus()
		}
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			<TextField ref={field} label="Master password" type="password" value={masterPassword} onChange={setMasterPassword} autoFocus autoComplete="current-password" />
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!masterPassword}>Unlock</Button>
			{logOut.confirming
				? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
				: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>Log out</Button>}
		</form>
	)
}
