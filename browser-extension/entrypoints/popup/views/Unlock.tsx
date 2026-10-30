import { useRef, useState, type FormEvent } from 'react'
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
	notice?: NoticeCode | null
	dispatch: Dispatch
	/** Opens the generator, which needs no unlock. */
	onGenerate?: () => void
	/** Back from the generator: focus returns to its link rather than the master password. */
	focusGenerate?: boolean
}

export function Unlock({ account, notice = null, dispatch, onGenerate, focusGenerate = false }: Props) {
	const [secret, setSecret] = useState('')
	const [switched, setSwitched] = useState(false)
	// The PIN comes first while one is set; a forgotten one leaves only the master password.
	const withPin = account.pin && !switched
	// Only the sealed PIN is left: this master password rebuilds the session copy (ADR-002).
	const afterRestart = account.pinAfterRestart && !account.pin
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const logOut = useLogOutConfirm()
	const field = useRef<HTMLInputElement>(null)

	function switchToMasterPassword() {
		setSecret('')
		setSwitched(true)
	}

	async function submit(event: FormEvent) {
		event.preventDefault()
		setBusy(true)
		setError(null)
		const method = withPin ? { type: 'pin' as const, pin: secret } : { type: 'masterPassword' as const, masterPassword: secret }
		const result = await dispatch({ kind: 'vault.unlock', accountId: account.id, method })
		setBusy(false)
		// On success this view unmounts; either way the secret leaves React state.
		setSecret('')
		if (result.ok) return
		setError(errorText(result.code, account.host, result.attemptsLeft))
		if (result.code === 'pin_forgotten' || result.code === 'pin_key_changed') switchToMasterPassword()
		else field.current?.focus()
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			{notice && <Banner tone="warning" role="status">{noticeText(notice)}</Banner>}
			{withPin
				? <TextField key="pin" ref={field} label={i18n.t('unlock.pin')} type="password" value={secret} onChange={setSecret} autoFocus={!(focusGenerate && onGenerate)} autoComplete="off" />
				: <TextField key="password" ref={field} label={i18n.t('unlock.masterPassword')} type="password" value={secret} onChange={setSecret} autoFocus={switched || !(focusGenerate && onGenerate)} autoComplete="current-password" hint={afterRestart ? i18n.t('settings.pinAfterRestart') : undefined} />}
			<ErrorBanner>{error}</ErrorBanner>
			{withPin && <Button variant="link" onClick={() => {
				setError(null)
				switchToMasterPassword()
			}}>{i18n.t('unlock.useMasterPassword')}</Button>}
			<Button type="submit" busy={busy} disabled={!secret}>{i18n.t('unlock.submit')}</Button>
			{onGenerate && <Button variant="link" onClick={onGenerate} autoFocus={focusGenerate}>{i18n.t('generator.generatePasswordLink')}</Button>}
			{logOut.confirming
				? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
				: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>{i18n.t('common.logOut')}</Button>}
		</form>
	)
}
