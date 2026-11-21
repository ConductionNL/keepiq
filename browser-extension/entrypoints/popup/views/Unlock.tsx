import type { FormEvent } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, NoticeCode } from '@/src/messages'
import { Button } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Identity } from '../components/Identity'
import { LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
import { TextField } from '../components/TextField'
import { noticeText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'
import { useSecretEntry } from '../hooks/useSecretEntry'
import { openVaultPasskeyWindow, usePasskeySupport } from '../vault-passkey'

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
	const { secret, setSecret, switched, withPin, afterRestart, error, busy, field, switchToMasterPassword, submit: unlock } = useSecretEntry(account)
	const logOut = useLogOutConfirm()
	const support = usePasskeySupport()

	function submit(event: FormEvent) {
		event.preventDefault()
		void unlock((method) => dispatch({ kind: 'vault.unlock', accountId: account.id, method }))
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			{notice && <Banner tone="warning" role="status">{noticeText(notice)}</Banner>}
			{withPin
				? <TextField key="pin" ref={field} label={i18n.t('unlock.pin')} type="password" value={secret} onChange={setSecret} autoFocus={!(focusGenerate && onGenerate)} autoComplete="off" />
				: <TextField key="password" ref={field} label={i18n.t('unlock.masterPassword')} type="password" value={secret} onChange={setSecret} autoFocus={switched || !(focusGenerate && onGenerate)} autoComplete="current-password" hint={afterRestart ? i18n.t('settings.pinAfterRestart') : undefined} />}
			<ErrorBanner>{error}</ErrorBanner>
			{withPin && <Button variant="link" onClick={switchToMasterPassword}>{i18n.t('unlock.useMasterPassword')}</Button>}
			<Button type="submit" busy={busy} disabled={!secret}>{i18n.t('unlock.submit')}</Button>
			{account.passkeyUnlock && support === 'supported' && (
				<Button variant="secondary" onClick={() => void openVaultPasskeyWindow(account.id, 'unlock')}>{i18n.t('vaultPasskey.unlock')}</Button>
			)}
			{onGenerate && <Button variant="link" onClick={onGenerate} autoFocus={focusGenerate}>{i18n.t('generator.generatePasswordLink')}</Button>}
			{logOut.confirming
				? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
				: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>{i18n.t('common.logOut')}</Button>}
		</form>
	)
}
