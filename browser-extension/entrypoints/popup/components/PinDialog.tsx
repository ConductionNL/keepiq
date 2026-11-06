import { useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { i18n } from '#i18n'
import { PIN_MIN_LENGTH } from '@/src/messages'
import { errorText } from '../errors'
import { request } from '../hooks/useMessage'
import { useShell } from '../shell-context'
import { ConfirmDialog } from './ConfirmDialog'
import { TextField } from './TextField'

type Errors = Partial<Record<'masterPassword' | 'pin' | 'again', string>>

/** Turns on Unlock with PIN; the master password proves it is the vault's owner. */
export function PinDialog({ onDone, onCancel }: { onDone: () => void; onCancel: () => void }) {
	const { accountId, refreshState } = useShell()
	const [masterPassword, setMasterPassword] = useState('')
	const [pin, setPin] = useState('')
	const [again, setAgain] = useState('')
	const [errors, setErrors] = useState<Errors>({})
	const [failure, setFailure] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const masterPasswordField = useRef<HTMLInputElement>(null)
	const pinField = useRef<HTMLInputElement>(null)
	const againField = useRef<HTMLInputElement>(null)
	const tooShort = i18n.t('errors.pinTooShort', { count: PIN_MIN_LENGTH })

	/** The error renders first, so the field names it when focus lands. */
	function refuse(field: keyof Errors, text: string) {
		flushSync(() => setErrors({ [field]: text }))
		const input = field === 'masterPassword' ? masterPasswordField : field === 'pin' ? pinField : againField
		input.current?.focus()
	}

	async function save() {
		if (busy) return
		if (!masterPassword) return refuse('masterPassword', i18n.t('settings.masterPasswordRequired'))
		// Characters, not UTF-16 units, as `enablePin` counts them.
		if ([...pin].length < PIN_MIN_LENGTH) return refuse('pin', tooShort)
		if (pin !== again) return refuse('again', i18n.t('settings.pinMismatch'))
		setErrors({})
		setFailure(null)
		setBusy(true)
		const reply = await request({ kind: 'pin.set', accountId, pin, masterPassword })
		setBusy(false)
		if (reply?.ok) return onDone()
		switch (reply?.code) {
			case 'locked':
				refreshState()
				return onCancel()
			case 'pin_too_short': return refuse('pin', tooShort)
			case 'invalid_master_password':
				setMasterPassword('')
				return refuse('masterPassword', errorText('invalid_master_password', ''))
			default: return setFailure(errorText('not_responding', ''))
		}
	}

	return (
		<ConfirmDialog title={i18n.t('settings.pin')} actions={[{ label: i18n.t('settings.pinTurnOn'), onClick: () => void save(), busy }]} onCancel={onCancel} error={failure}>
			{/* Enter in a field saves; a form of several fields without a submit button would not. */}
			<div className="stack stack--tight" onKeyDown={(event) => {
				if (event.key !== 'Enter' || !(event.target instanceof HTMLInputElement)) return
				event.preventDefault()
				void save()
			}}>
				<TextField ref={masterPasswordField} label={i18n.t('unlock.masterPassword')} type="password" value={masterPassword} onChange={setMasterPassword} error={errors.masterPassword} announce hint={i18n.t('settings.pinAfterRestart')} autoComplete="current-password" />
				<TextField ref={pinField} label={i18n.t('unlock.pin')} type="password" value={pin} onChange={setPin} error={errors.pin} announce hint={i18n.t('settings.pinAdvice')} autoComplete="off" />
				<TextField ref={againField} label={i18n.t('settings.pinAgain')} type="password" value={again} onChange={setAgain} error={errors.again} announce autoComplete="off" />
			</div>
		</ConfirmDialog>
	)
}
