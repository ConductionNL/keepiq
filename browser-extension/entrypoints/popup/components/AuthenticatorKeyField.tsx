import { useEffect, useId, useRef, useState } from 'react'
import { i18n } from '#i18n'
import { scanVisibleTab, type ScanFailure } from '@/src/totp/qr-scan'
import type { AuthKeyMode } from '@/src/vault/fields'
import { isPopout } from '../hooks/useCurrentTab'
import { Button, IconButton } from './Button'
import { TextField } from './TextField'

interface Props {
	/** The row holds a key, which is never loaded into the form (Keepiq `login-one-time-codes`). */
	saved: boolean
	mode: AuthKeyMode
	/** A type that takes no new key: a saved one can only be removed. */
	removeOnly: boolean
	value: string
	error: string | null
	/** A clone of a login whose key is not cloned. */
	leftOut?: boolean
	onMode: (mode: AuthKeyMode) => void
	onValue: (value: string) => void
	/** Replaceable for tests, which have no tab to take a picture of. */
	scan?: typeof scanVisibleTab
	popout?: boolean
}

const SCAN_FAILURES: Record<ScanFailure, () => string> = {
	no_picture: () => i18n.t('authKey.noPicture'),
	no_qr: () => i18n.t('authKey.noQr'),
	not_totp: () => i18n.t('authKey.notTotp'),
}

export function AuthenticatorKeyField({ saved, mode, removeOnly, value, error, leftOut = false, onMode, onValue, scan = scanVisibleTab, popout = isPopout }: Props) {
	const id = useId()
	const [scanning, setScanning] = useState(false)
	const [scanned, setScanned] = useState<{ failure?: ScanFailure; read?: boolean }>({})
	const input = useRef<HTMLInputElement>(null)
	// Set by a choice, so the control that replaced the one chosen gets focus, never the page (as the web app's field).
	const focusAfter = useRef(false)

	useEffect(() => {
		if (!focusAfter.current) return
		focusAfter.current = false
		const target = mode === 'replace' ? input.current : document.getElementById(`${id}-${mode === 'remove' ? 'keep' : 'remove'}`)
		target?.focus()
	}, [mode, id])

	const choose = (next: AuthKeyMode) => {
		focusAfter.current = true
		setScanned({})
		onMode(next)
	}

	async function scanPage() {
		setScanning(true)
		setScanned({})
		const result = await scan()
		setScanning(false)
		if ('key' in result) {
			onValue(result.key)
			setScanned({ read: true })
		} else {
			setScanned({ failure: result.failure })
		}
	}

	const keep = <Button variant="link" id={`${id}-keep`} onClick={() => choose('keep')}>{i18n.t('authKey.keep')}</Button>

	if (saved && mode === 'keep') {
		return (
			<div className="field" role="group" aria-labelledby={`${id}-label`}>
				<span className="field__label" id={`${id}-label`}>{i18n.t('authKey.label')}</span>
				<div className="field__box">
					<span className="auth-key__saved">{i18n.t('authKey.saved')}</span>
					{/* A login's removal brings the input back, so a new key replaces it and an empty one removes it. */}
					<IconButton icon="trash" id={`${id}-remove`} label={i18n.t('authKey.remove')} onClick={() => choose(removeOnly ? 'remove' : 'replace')} />
				</div>
			</div>
		)
	}

	if (saved && mode === 'remove') {
		return (
			<div className="field" role="group" aria-labelledby={`${id}-label`}>
				<span className="field__label" id={`${id}-label`}>{i18n.t('authKey.label')}</span>
				<p className="field__hint">{i18n.t('authKey.removedOnSave')}</p>
				{keep}
			</div>
		)
	}

	const failure = scanned.failure && SCAN_FAILURES[scanned.failure]()
	return (
		<div className="stack stack--tight">
			<TextField
				ref={input}
				label={saved ? i18n.t('authKey.newLabel') : i18n.t('authKey.optionalLabel')}
				type="password"
				value={value}
				onChange={(next) => {
					setScanned({})
					onValue(next)
				}}
				hint={[saved ? i18n.t('authKey.replaceHelp') : i18n.t('authKey.help'), popout && i18n.t('authKey.popout')].filter(Boolean).join(' ')}
				error={failure || error}
				announce={!!failure}
				autoComplete="off"
				action={{ icon: 'scanQr', label: i18n.t('authKey.scan'), onClick: () => void scanPage(), disabled: popout || scanning }}
			/>
			{saved && <div className="auth-key__actions">{keep}</div>}
			{/* Present while empty, so screen readers announce what appears in it. */}
			<p className={scanned.read ? 'field__hint' : 'sr-only'} role="status">{scanned.read ? i18n.t('authKey.read') : ''}</p>
			{leftOut && <p className="field__hint">{i18n.t('authKey.notCloned')}</p>}
		</div>
	)
}
