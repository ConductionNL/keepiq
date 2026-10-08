import { useEffect, useRef, useState, type FormEvent } from 'react'
import { i18n } from '#i18n'
import { normalizeServerUrl } from '@/src/accounts/normalize-server-url'
import type { ErrorCode } from '@/src/messages'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'
import { rich, SLOT } from '../i18n'

const DRAFT = 'addAccountDraft'
type Draft = { serverUrl: string; username: string }
type Field = 'serverUrl' | 'username' | 'appPassword'

/**
 * Survives the popup closing during the permission prompt: a reopened popup is a
 * new page, so this lives in `storage.session`. The app password is never kept.
 */
function useDraft(): [Draft, (patch: Partial<Draft>) => void, () => void] {
	const [draft, setDraft] = useState<Draft>({ serverUrl: '', username: '' })
	const typed = useRef(false)
	useEffect(() => {
		browser.storage.session?.get(DRAFT).then((stored) => {
			const saved = stored[DRAFT] as Draft | undefined
			if (saved && !typed.current) setDraft(saved)
		}).catch(() => {})
	}, [])
	function update(patch: Partial<Draft>) {
		typed.current = true
		const next = { ...draft, ...patch }
		setDraft(next)
		browser.storage.session?.set({ [DRAFT]: next }).catch(() => {})
	}
	function forget() {
		browser.storage.session?.remove(DRAFT).catch(() => {})
	}
	return [draft, update, forget]
}

/** The field the user has to change after each failure. */
function fieldFor(code: ErrorCode): Field {
	if (code === 'unauthorized') return 'appPassword'
	if (code === 'duplicate') return 'username'
	return 'serverUrl'
}

interface Props {
	dispatch: Dispatch
	onAdded?: () => void
}

export function AddAccount({ dispatch, onAdded }: Props) {
	const [{ serverUrl, username }, setDraft, forgetDraft] = useDraft()
	const [appPassword, setAppPassword] = useState('')
	const serverUrlField = useRef<HTMLInputElement>(null)
	const usernameField = useRef<HTMLInputElement>(null)
	const appPasswordField = useRef<HTMLInputElement>(null)
	const [securityUrl, setSecurityUrl] = useState<string | null>(null)
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)

	function updateSecurityLink() {
		const normalized = normalizeServerUrl(serverUrl)
		setSecurityUrl(normalized.ok ? `${normalized.serverUrl}/index.php/settings/user/security` : null)
	}

	function fail(code: ErrorCode, host: string) {
		setError(errorText(code, host))
		const field = { serverUrl: serverUrlField, username: usernameField, appPassword: appPasswordField }[fieldFor(code)]
		field.current?.focus()
	}

	async function submit(event: FormEvent) {
		event.preventDefault()
		const normalized = normalizeServerUrl(serverUrl)
		if (!normalized.ok) {
			fail(normalized.code, '')
			return
		}
		// Must be the first await: the browser only prompts inside the click's user gesture.
		const granted = await browser.permissions.request({ origins: [`${normalized.origin}/*`] }).catch(() => false)
		if (!granted) {
			fail('permission_denied', normalized.host)
			return
		}
		setBusy(true)
		setError(null)
		const result = await dispatch({ kind: 'accounts.add', serverUrl: normalized.serverUrl, username, appPassword })
		setBusy(false)
		if (result.ok) {
			forgetDraft()
			onAdded?.()
			return
		}
		if (result.code === 'unauthorized') setAppPassword('')
		fail(result.code, normalized.host)
	}

	return (
		<form className="stack" onSubmit={submit} noValidate>
			<TextField ref={serverUrlField} label={i18n.t('addAccount.serverUrl')} value={serverUrl} onChange={(value) => setDraft({ serverUrl: value })} onBlur={updateSecurityLink} placeholder={i18n.t('addAccount.serverUrlPlaceholder')} autoFocus autoComplete="url" inputMode="url" />
			<TextField ref={usernameField} label={i18n.t('addAccount.username')} value={username} onChange={(value) => setDraft({ username: value })} autoComplete="username" />
			<TextField
				ref={appPasswordField}
				label={i18n.t('common.appPassword')}
				type="password"
				value={appPassword}
				onChange={setAppPassword}
				autoComplete="off"
				hint={securityUrl
					? rich(i18n.t('addAccount.hintWithLink', { link: SLOT }), <a href={securityUrl} target="_blank" rel="noreferrer">{i18n.t('addAccount.securityLink')}</a>)
					: i18n.t('addAccount.hint')}
			/>
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!serverUrl.trim() || !username.trim() || !appPassword}>{i18n.t('common.addAccount')}</Button>
		</form>
	)
}
