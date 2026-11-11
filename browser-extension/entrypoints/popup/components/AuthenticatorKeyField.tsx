import { useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react'
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

type Scanned = { failure?: ScanFailure; read?: boolean }

/** Scanning the tab for a QR code, and what the last scan found. */
function useKeyScan(scan: typeof scanVisibleTab, onValue: (value: string) => void) {
	const [scanning, setScanning] = useState(false)
	const [scanned, setScanned] = useState<Scanned>({})
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
	/** A typed or pasted key clears what the last scan said. */
	const onTyped = (next: string) => {
		setScanned({})
		onValue(next)
	}
	return { scanning, scanned, setScanned, scanPage, onTyped }
}

/** Set by a choice, so the control that replaced the one chosen gets focus, never the page (as the web app's field). */
function useFocusAfterChoice(mode: AuthKeyMode, id: string) {
	const inputRef = useRef<HTMLInputElement>(null)
	const focusAfterRef = useRef(false)
	useEffect(() => {
		if (!focusAfterRef.current) return
		focusAfterRef.current = false
		const target = mode === 'replace' ? inputRef.current : document.getElementById(`${id}-${mode === 'remove' ? 'keep' : 'remove'}`)
		target?.focus()
	}, [mode, id])
	const markChoice = () => {
		focusAfterRef.current = true
	}
	return { inputRef, markChoice }
}

interface SavedProps {
	id: string
	removeOnly: boolean
	choose: (next: AuthKeyMode) => void
}

function SavedKey({ id, removeOnly, choose }: SavedProps) {
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

function RemovedKey({ id, keep }: { id: string; keep: ReactNode }) {
	return (
		<div className="field" role="group" aria-labelledby={`${id}-label`}>
			<span className="field__label" id={`${id}-label`}>{i18n.t('authKey.label')}</span>
			<p className="field__hint">{i18n.t('authKey.removedOnSave')}</p>
			{keep}
		</div>
	)
}

/** What the key input shows besides the value it holds. */
interface KeyInputState {
	saved: boolean
	popout: boolean
	error: string | null
	leftOut: boolean
	scan: ReturnType<typeof useKeyScan>
	keep: ReactNode
}

interface KeyInputProps {
	state: KeyInputState
	props: Props
	inputRef: RefObject<HTMLInputElement | null>
}

function keyHint({ saved, popout }: KeyInputState): string {
	return [saved ? i18n.t('authKey.replaceHelp') : i18n.t('authKey.help'), popout && i18n.t('authKey.popout')].filter(Boolean).join(' ')
}

function scanAction({ scan, popout }: KeyInputState) {
	return { icon: 'scanQr' as const, label: i18n.t('authKey.scan'), onClick: () => void scan.scanPage(), disabled: popout || scan.scanning }
}

/** The input's label, help and error, the error being a failed scan's before the form's own. */
function fieldText(state: KeyInputState) {
	const { failure } = state.scan.scanned
	const failureText = failure && SCAN_FAILURES[failure]()
	return {
		label: state.saved ? i18n.t('authKey.newLabel') : i18n.t('authKey.optionalLabel'),
		hint: keyHint(state),
		error: failureText || state.error,
		announce: !!failureText,
	}
}

function KeyTextField({ state, props, inputRef }: KeyInputProps) {
	return (
		<TextField
			ref={inputRef}
			{...fieldText(state)}
			type="password"
			value={props.value}
			onChange={state.scan.onTyped}
			autoComplete="off"
			action={scanAction(state)}
		/>
	)
}

function KeyInput({ state, props, inputRef }: KeyInputProps) {
	const { read } = state.scan.scanned
	return (
		<div className="stack stack--tight">
			<KeyTextField state={state} props={props} inputRef={inputRef} />
			{state.saved && <div className="auth-key__actions">{state.keep}</div>}
			{/* Present while empty, so screen readers announce what appears in it. */}
			<p className={read ? 'field__hint' : 'sr-only'} role="status">{read ? i18n.t('authKey.read') : ''}</p>
			{state.leftOut && <p className="field__hint">{i18n.t('authKey.notCloned')}</p>}
		</div>
	)
}

function withDefaults(props: Props) {
	return { ...props, leftOut: props.leftOut ?? false, scan: props.scan ?? scanVisibleTab, popout: props.popout ?? isPopout }
}

export function AuthenticatorKeyField(given: Props) {
	const props = withDefaults(given)
	const { saved, mode } = props
	const id = useId()
	const scan = useKeyScan(props.scan, props.onValue)
	const { inputRef, markChoice } = useFocusAfterChoice(mode, id)

	const choose = (next: AuthKeyMode) => {
		markChoice()
		scan.setScanned({})
		props.onMode(next)
	}

	const keep = <Button variant="link" id={`${id}-keep`} onClick={() => choose('keep')}>{i18n.t('authKey.keep')}</Button>

	if (saved && mode === 'keep') return <SavedKey id={id} removeOnly={props.removeOnly} choose={choose} />
	if (saved && mode === 'remove') return <RemovedKey id={id} keep={keep} />
	return <KeyInput state={{ saved, popout: props.popout, error: props.error, leftOut: props.leftOut, scan, keep }} props={props} inputRef={inputRef} />
}
