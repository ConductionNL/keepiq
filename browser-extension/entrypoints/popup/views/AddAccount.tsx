import { useState, type FormEvent } from 'react'
import { normalizeOrigin } from '@/src/accounts/normalize-origin'
import { Button } from '../components/Button'
import { ErrorBanner } from '../components/ErrorBanner'
import { TextField } from '../components/TextField'
import { errorText } from '../errors'
import type { Dispatch } from '../hooks/usePopupState'

/** Survives the popup closing during the permission prompt; the app password is never kept. */
function useRememberedField(name: string): [string, (value: string) => void] {
	const key = `addAccount.${name}`
	const [value, setValue] = useState(() => {
		try {
			return sessionStorage.getItem(key) ?? ''
		} catch {
			return ''
		}
	})
	return [value, (next) => {
		setValue(next)
		try {
			sessionStorage.setItem(key, next)
		} catch {
			// Storage blocked; the form still works, it just won't be restored.
		}
	}]
}

function forgetFields() {
	try {
		sessionStorage.removeItem('addAccount.serverUrl')
		sessionStorage.removeItem('addAccount.username')
	} catch {
		// Nothing to forget.
	}
}

interface Props {
	dispatch: Dispatch
	/** Set when opened from the account switcher. */
	onCancel?: () => void
	onAdded?: () => void
}

export function AddAccount({ dispatch, onCancel, onAdded }: Props) {
	const [serverUrl, setServerUrl] = useRememberedField('serverUrl')
	const [username, setUsername] = useRememberedField('username')
	const [appPassword, setAppPassword] = useState('')
	const [securityUrl, setSecurityUrl] = useState<string | null>(null)
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)

	function updateSecurityLink() {
		const normalized = normalizeOrigin(serverUrl)
		setSecurityUrl(normalized.ok ? `${normalized.origin}/index.php/settings/user/security` : null)
	}

	async function submit(event: FormEvent) {
		event.preventDefault()
		const normalized = normalizeOrigin(serverUrl)
		if (!normalized.ok) {
			setError(errorText(normalized.code, '', ''))
			return
		}
		// Must be the first await: the browser only prompts inside the click's user gesture.
		const granted = await browser.permissions.request({ origins: [`${normalized.origin}/*`] }).catch(() => false)
		if (!granted) {
			setError(errorText('permission_denied', normalized.host, ''))
			return
		}
		setBusy(true)
		setError(null)
		const result = await dispatch({ kind: 'accounts.add', serverUrl: normalized.origin, username, appPassword })
		setBusy(false)
		if (result.ok) {
			forgetFields()
			onAdded?.()
			return
		}
		setError(errorText(result.code, normalized.host, result.message))
		if (result.code === 'unauthorized') setAppPassword('')
	}

	return (
		<form className="stack" onSubmit={submit} noValidate>
			<TextField label="Server URL" value={serverUrl} onChange={setServerUrl} onBlur={updateSecurityLink} placeholder="cloud.example.org" autoFocus autoComplete="url" inputMode="url" />
			<TextField label="Username" value={username} onChange={setUsername} autoComplete="username" />
			<TextField label="App password" type="password" value={appPassword} onChange={setAppPassword} autoComplete="off" />
			<p className="hint">
				Create a dedicated app password under Nextcloud Settings, Security
				{securityUrl ? <>: <a href={securityUrl} target="_blank" rel="noreferrer">open security settings</a></> : null}.
				Never enter your Nextcloud login password here.
			</p>
			<ErrorBanner>{error}</ErrorBanner>
			<Button type="submit" busy={busy} disabled={!serverUrl.trim() || !username.trim() || !appPassword}>Add account</Button>
			{onCancel && <Button variant="link" onClick={onCancel}>Cancel</Button>}
		</form>
	)
}
