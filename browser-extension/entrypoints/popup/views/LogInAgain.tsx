import { useState, type FormEvent } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, NoticeCode } from '@/src/messages'
import { Button } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Identity } from '../components/Identity'
import { LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
import { TextField } from '../components/TextField'
import { errorText, noticeText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'

interface Props {
	account: AccountSummary
	notice: NoticeCode | null
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
			setError(errorText(result.code, account.host))
			if (result.code === 'unauthorized') setAppPassword('')
		}
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			{notice && <Banner tone="warning" role="status">{noticeText(notice)}</Banner>}
			<TextField label={i18n.t('common.appPassword')} type="password" value={appPassword} onChange={setAppPassword} autoFocus autoComplete="off" />
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!appPassword}>{i18n.t('logInAgain.submit')}</Button>
			{logOut.confirming
				? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
				: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>{i18n.t('common.logOut')}</Button>}
		</form>
	)
}
