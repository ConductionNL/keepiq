import { useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react'
import { i18n } from '#i18n'
import { scanVisibleTab, type ScanFailure } from '@/src/totp/qr-scan'
import type { AuthKeyMode } from '@/src/vault/fields'
import { isPopout } from '../hooks/useCurrentTab'
import { Button, IconButton } from './Button'
import type { IconName } from './Icon'
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

interface Scanned {
	failure?: ScanFailure
	read?: boolean
}

/** Props with their defaults applied. */
type Field = Required<Props>

type QrScan = ReturnType<typeof useQrScan>

interface KeyInputProps {
	field: Field
	qr: QrScan
	input: RefObject<HTMLInputElement | null>
	keep: ReactNode
}

interface ChoiceProps {
	id: string
	choose: (next: AuthKeyMode) => void
}

interface SavedProps extends ChoiceProps {
	removeOnly: boolean
}

function useQrScan(scan: typeof scanVisibleTab, onValue: (value: string) => void) {
	const [scanning, setScanning] = useState(false)
	const [scanned, setScanned] = useState<Scanned>({})
	async function scanPage() {
		setScanning(true)
		setScanned({})
		const result = await scan()
		setScanning(false)
		if ('key' in result) onValue(result.key)
		setScanned('key' in result ? { read: true } : { failure: result.failure })
	}
	const edit = (next: string) => {
		setScanned({})
		onValue(next)
	}
	return { scanning, scanned, failure: scanFailure(scanned), setScanned, scanPage, edit }
}

/** The id of the button that replaces the one chosen, for a choice that does not bring the input. */
function otherChoiceId(mode: AuthKeyMode, id: string) {
	return `${id}-${mode === 'remove' ? 'keep' : 'remove'}`
}

/** Focus for the control that replaced the one chosen, never the page (as the web app's field). Set by a choice. */
function useChoiceFocus(mode: AuthKeyMode, id: string) {
	const inputRef = useRef<HTMLInputElement>(null)
	const focusAfterRef = useRef(false)
	useEffect(() => {
		if (!focusAfterRef.current) return
		focusAfterRef.current = false
		const target = mode === 'replace' ? inputRef.current : document.getElementById(otherChoiceId(mode, id))
		target?.focus()
	}, [mode, id])
	const focusNext = () => {
		focusAfterRef.current = true
	}
	return { inputRef, focusNext }
}

function scanFailure(scanned: Scanned) {
	return scanned.failure && SCAN_FAILURES[scanned.failure]()
}

function keyHint(field: Field) {
	return [field.saved ? i18n.t('authKey.replaceHelp') : i18n.t('authKey.help'), field.popout && i18n.t('authKey.popout')].filter(Boolean).join(' ')
}

/** The label and hint, which say whether a new key replaces a saved one. */
function keyTexts(field: Field) {
	return { label: field.saved ? i18n.t('authKey.newLabel') : i18n.t('authKey.optionalLabel'), hint: keyHint(field) }
}

function scanAction(qr: QrScan, popout: boolean): { icon: IconName, label: string, onClick: () => void, disabled: boolean } {
	return { icon: 'scanQr', label: i18n.t('authKey.scan'), onClick: () => void qr.scanPage(), disabled: popout || qr.scanning }
}

function SavedKey({ id, removeOnly, choose }: SavedProps) {
	// A login's removal brings the input back, so a new key replaces it and an empty one removes it.
	const next = removeOnly ? 'remove' : 'replace'
	return (
		<div className="field" role="group" aria-labelledby={`${id}-label`}>
			<span className="field__label" id={`${id}-label`}>{i18n.t('authKey.label')}</span>
			<div className="field__box">
				<span className="auth-key__saved">{i18n.t('authKey.saved')}</span>
				<IconButton icon="trash" id={`${id}-remove`} label={i18n.t('authKey.remove')} onClick={() => choose(next)} />
			</div>
		</div>
	)
}

function RemovedKey({ id, keep }: { id: string, keep: ReactNode }) {
	return (
		<div className="field" role="group" aria-labelledby={`${id}-label`}>
			<span className="field__label" id={`${id}-label`}>{i18n.t('authKey.label')}</span>
			<p className="field__hint">{i18n.t('authKey.removedOnSave')}</p>
			{keep}
		</div>
	)
}

function KeyTextField({ field, qr, input }: KeyInputProps) {
	return (
		<TextField
			ref={input}
			{...keyTexts(field)}
			type="password"
			value={field.value}
			onChange={qr.edit}
			error={qr.failure || field.error}
			announce={!!qr.failure}
			autoComplete="off"
			action={scanAction(qr, field.popout)}
		/>
	)
}

function ScanStatus({ read }: Scanned) {
	return <p className={read ? 'field__hint' : 'sr-only'} role="status">{read ? i18n.t('authKey.read') : ''}</p>
}

function KeyInput(props: KeyInputProps) {
	const { field, qr, keep } = props
	const actions = field.saved && <div className="auth-key__actions">{keep}</div>
	const notCloned = field.leftOut && <p className="field__hint">{i18n.t('authKey.notCloned')}</p>
	return (
		<div className="stack stack--tight">
			<KeyTextField {...props} />
			{actions}
			{/* Present while empty, so screen readers announce what appears in it. */}
			<ScanStatus read={qr.scanned.read} />
			{notCloned}
		</div>
	)
}

function KeepButton({ id, choose }: ChoiceProps) {
	return <Button variant="link" id={`${id}-keep`} onClick={() => choose('keep')}>{i18n.t('authKey.keep')}</Button>
}

function withDefaults({ leftOut = false, popout = isPopout, scan = scanVisibleTab, ...rest }: Props) {
	return { ...rest, leftOut, popout, scan }
}

export function AuthenticatorKeyField(props: Props) {
	const field = withDefaults(props)
	const id = useId()
	const qr = useQrScan(field.scan, field.onValue)
	const { inputRef, focusNext } = useChoiceFocus(field.mode, id)
	const choose = (next: AuthKeyMode) => {
		focusNext()
		qr.setScanned({})
		field.onMode(next)
	}
	const keep = <KeepButton id={id} choose={choose} />
	const shown = field.saved ? field.mode : 'replace'
	if (shown === 'keep') return <SavedKey id={id} removeOnly={field.removeOnly} choose={choose} />
	if (shown === 'remove') return <RemovedKey id={id} keep={keep} />
	return <KeyInput field={field} qr={qr} input={inputRef} keep={keep} />
}
