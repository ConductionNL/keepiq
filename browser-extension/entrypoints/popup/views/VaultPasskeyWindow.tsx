import { useEffect, useState, type FormEvent } from 'react'
import { i18n } from '#i18n'
import { toBase64 } from '@/src/crypto'
import type { AccountSummary } from '@/src/messages'
import { enrolPasskey, unlockWithPasskey } from '@/src/vault/passkey-unlock'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Loading } from '../components/Loading'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import { sendMessage } from '../hooks/useMessage'
import { ceremonyDeps, ceremonyErrorText, Refused, supportText, usePasskeySupport, type VaultPasskeyMode } from '../vault-passkey'

interface Props {
	accountId: string
	mode: VaultPasskeyMode
}

/** The vault's own fingerprint or face unlock, enrolled or used in an extension window (extension-biometric-unlock). */
export function VaultPasskeyWindow({ accountId, mode }: Props) {
	const support = usePasskeySupport()
	const [account, setAccount] = useState<AccountSummary | null>(null)
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const [done, setDone] = useState(false)
	const [masterPassword, setMasterPassword] = useState('')
	const title = mode === 'enrol' ? i18n.t('vaultPasskey.enrolTitle') : i18n.t('titles.unlock')

	useEffect(() => {
		document.title = title
		void sendMessage({ kind: 'vault.status', passive: true }).then((result) => {
			if (result.ok) setAccount(result.state.accounts.find((a) => a.id === accountId) ?? null)
			else setError(errorText(result.code, ''))
		})
	}, [accountId, title])

	const host = account?.host ?? ''

	async function run(ceremony: () => Promise<void>) {
		setBusy(true)
		setError(null)
		try {
			await ceremony()
		} catch (failure) {
			setError(ceremonyErrorText(failure, host))
		} finally {
			setBusy(false)
		}
	}

	const unlock = () => run(async () => {
		const raw = await unlockWithPasskey(ceremonyDeps(accountId))
		if (!raw) {
			setError(errorText('passkey_failed', host))
			return
		}
		const unlockKey = toBase64(raw)
		raw.fill(0)
		const result = await sendMessage({ kind: 'vault.unlock', accountId, method: { type: 'unlockKey', unlockKey } })
		if (!result.ok) throw new Refused(result.code)
		window.close()
	})

	function enrol(event: FormEvent) {
		event.preventDefault()
		void run(async () => {
			await enrolPasskey(ceremonyDeps(accountId), masterPassword, i18n.t('vaultPasskey.label'))
			setMasterPassword('')
			setDone(true)
		})
	}

	const unsupported = support === null ? null : supportText(support)
	let body
	if (support === null || !account) body = error ? <ErrorBanner>{error}</ErrorBanner> : <Loading />
	else if (unsupported) body = <p>{unsupported}</p>
	else if (done) body = <p role="status">{i18n.t('vaultPasskey.enrolDone')}</p>
	else if (mode === 'enrol') {
		body = (
			<form className="stack" onSubmit={enrol}>
				<p>{i18n.t('vaultPasskey.enrolIntro')}</p>
				<TextField label={i18n.t('unlock.masterPassword')} type="password" value={masterPassword} onChange={setMasterPassword} autoFocus autoComplete="current-password" />
				<ErrorBanner>{error}</ErrorBanner>
				<Button type="submit" busy={busy} disabled={!masterPassword}>{i18n.t('vaultPasskey.enrolSubmit')}</Button>
			</form>
		)
	} else {
		body = (
			<div className="stack">
				<p>{i18n.t('vaultPasskey.unlockIntro')}</p>
				<ErrorBanner>{error}</ErrorBanner>
				<Button autoFocus busy={busy} onClick={() => void unlock()}>{i18n.t('vaultPasskey.unlock')}</Button>
			</div>
		)
	}

	const closeLabel = done ? i18n.t('vaultPasskey.close') : mode === 'enrol' ? i18n.t('common.cancel') : i18n.t('unlock.useMasterPassword')

	return (
		<main className="popup popup--popout">
			<Header title={title} active={null} busy={busy} />
			<div className="popup__body stack">
				{body}
				{/* The master password stays available in the popup, so this window only steps aside. */}
				<Button variant="secondary" onClick={() => window.close()}>{closeLabel}</Button>
			</div>
		</main>
	)
}
