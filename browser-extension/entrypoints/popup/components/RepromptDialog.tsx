import { useRef, useState } from 'react'
import { flushSync } from 'react-dom'
import { i18n } from '#i18n'
import { errorText } from '../errors'
import { ConfirmDialog } from './ConfirmDialog'
import { TextField } from './TextField'

interface Props {
	/** The last password sent did not open the key. */
	wrong: boolean
	onSubmit: (masterPassword: string) => void
	onCancel: () => void
}

/**
 * Asks for the master password before a flagged item fills; the background checks it, so a wrong one fills nothing.
 *
 * @spec openspec/changes/crypto-item-reprompt
 */
export function RepromptDialog({ wrong, onSubmit, onCancel }: Props) {
	const [masterPassword, setMasterPassword] = useState('')
	const [error, setError] = useState<string | null>(wrong ? errorText('invalid_master_password', '') : null)
	const field = useRef<HTMLInputElement>(null)

	function submit() {
		if (masterPassword === '') {
			// The error renders first, so the field names it when focus lands.
			flushSync(() => setError(i18n.t('settings.masterPasswordRequired')))
			field.current?.focus()
			return
		}
		onSubmit(masterPassword)
	}

	return (
		<ConfirmDialog title={i18n.t('item.repromptTitle')} actions={[{ label: i18n.t('item.fill'), onClick: submit }]} onCancel={onCancel}>
			<div onKeyDown={(event) => {
				if (event.key !== 'Enter' || !(event.target instanceof HTMLInputElement)) return
				event.preventDefault()
				submit()
			}}>
				<TextField ref={field} label={i18n.t('unlock.masterPassword')} type="password" value={masterPassword} onChange={setMasterPassword} error={error} announce hint={i18n.t('item.repromptHelp')} autoComplete="current-password" />
			</div>
		</ConfirmDialog>
	)
}
