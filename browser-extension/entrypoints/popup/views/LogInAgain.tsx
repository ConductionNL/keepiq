import { useState, type FormEvent } from 'react'
import type { AccountSummary } from '@/src/messages'
import { Button } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Identity } from '../components/Identity'
import { LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
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
	const logOut = useLogOutConfirm()

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
			<Identity account={account}>{!notice && 'Logged out'}</Identity>
			{notice && <Banner tone="warning" role="status">{notice}</Banner>}
			<TextField label="App password" type="password" value={appPassword} onChange={setAppPassword} autoFocus autoComplete="off" />
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!appPassword}>Log in</Button>
			{logOut.confirming
				? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
				: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>Log out</Button>}
		</form>
	)
}
