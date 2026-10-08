import { useState, type FormEvent } from 'react'
import type { AccountSummary } from '@/src/messages'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { Identity } from '../components/Identity'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'

interface Props {
	account: AccountSummary
	notice: string | null
	dispatch: Dispatch
}

export function LogInAgain({ account, notice, dispatch }: Props) {
	const [appPassword, setAppPassword] = useState('')
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)

	async function submit(event: FormEvent) {
		event.preventDefault()
		setBusy(true)
		setError(null)
		const result = await dispatch({ kind: 'accounts.reauthenticate', accountId: account.id, appPassword })
		setBusy(false)
		if (!result.ok) {
			setError(errorText(result.code, account.host, result.message))
			if (result.code === 'unauthorized') setAppPassword('')
		}
	}

	return (
		<form className="stack" onSubmit={submit}>
			{notice && <p className="banner banner--warning" role="status">{notice}</p>}
			<Identity account={account} />
			<TextField label="App password" type="password" value={appPassword} onChange={setAppPassword} autoFocus autoComplete="off" />
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!appPassword}>Log in</Button>
			<Button variant="link" onClick={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })}>Log out</Button>
		</form>
	)
}
