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

interface Props {
	account: AccountSummary
	notice?: NoticeCode | null
	dispatch: Dispatch
	/** Opens the generator, which needs no unlock. */
	onGenerate?: () => void
	/** Back from the generator: focus returns to its link rather than the master password. */
	focusGenerate?: boolean
}

type SecretEntry = ReturnType<typeof useSecretEntry>

interface FieldProps {
	entry: SecretEntry
	/** The generator link takes focus instead, coming back from the generator. */
	focusElsewhere: boolean
}

function SecretField({ entry, focusElsewhere }: FieldProps) {
	const { secret, setSecret, field } = entry
	if (entry.withPin) {
		return <TextField key="pin" ref={field} label={i18n.t('unlock.pin')} type="password" value={secret} onChange={setSecret} autoFocus={!focusElsewhere} autoComplete="off" />
	}
	return <TextField key="password" ref={field} label={i18n.t('unlock.masterPassword')} type="password" value={secret} onChange={setSecret} autoFocus={entry.switched || !focusElsewhere} autoComplete="current-password" hint={entry.afterRestart ? i18n.t('settings.pinAfterRestart') : undefined} />
}

interface LogOutProps {
	account: AccountSummary
	dispatch: Dispatch
}

function LogOutRow({ account, dispatch }: LogOutProps) {
	const logOut = useLogOutConfirm()
	return logOut.confirming
		? <LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
		: <Button variant="link" onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>{i18n.t('common.logOut')}</Button>
}

interface ActionsProps {
	entry: SecretEntry
	generate: Pick<Props, 'onGenerate' | 'focusGenerate'>
}

function EntryActions({ entry, generate: { onGenerate, focusGenerate } }: ActionsProps) {
	return (
		<>
			<ErrorBanner>{entry.error}</ErrorBanner>
			{entry.withPin && <Button variant="link" onClick={entry.switchToMasterPassword}>{i18n.t('unlock.useMasterPassword')}</Button>}
			<Button type="submit" busy={entry.busy} disabled={!entry.secret}>{i18n.t('unlock.submit')}</Button>
			{onGenerate && <Button variant="link" onClick={onGenerate} autoFocus={focusGenerate}>{i18n.t('generator.generatePasswordLink')}</Button>}
		</>
	)
}

export function Unlock({ account, notice = null, dispatch, onGenerate, focusGenerate = false }: Props) {
	const entry = useSecretEntry(account)

	function submit(event: FormEvent) {
		event.preventDefault()
		void entry.submit((method) => dispatch({ kind: 'vault.unlock', accountId: account.id, method }))
	}

	return (
		<form className="stack" onSubmit={submit}>
			<Identity account={account} />
			{notice && <Banner tone="warning" role="status">{noticeText(notice)}</Banner>}
			<SecretField entry={entry} focusElsewhere={focusGenerate && !!onGenerate} />
			<EntryActions entry={entry} generate={{ onGenerate, focusGenerate }} />
			<LogOutRow account={account} dispatch={dispatch} />
		</form>
	)
}
