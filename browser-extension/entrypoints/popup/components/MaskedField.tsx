import { useState, type ReactNode } from 'react'
import { useClipboard } from '../hooks/useClipboard'
import { fieldAction } from '../i18n'
import { IconButton } from './Button'

interface Props {
	label: string
	value: string
	/** Hidden until revealed; the value is not in the DOM meanwhile. */
	masked?: boolean
	/** What a masked value shows instead of dots, e.g. a card's last four digits. */
	maskedText?: string
	/** What the toast names, when not the label. */
	what?: string
	/** Extra actions before copy, e.g. Launch. */
	actions?: ReactNode
	copyable?: boolean
}

export function MaskedField({ label, value, masked = false, maskedText = '••••••••', what, actions, copyable = true }: Props) {
	const [revealed, setRevealed] = useState(false)
	const copy = useClipboard()
	const hidden = masked && !revealed
	return (
		<div className="field-row">
			<div className="field-row__text">
				<span className="field-row__label">{label}</span>
				<span className={`field-row__value${masked && revealed ? ' field-row__value--mono' : ''}`}>{hidden ? maskedText : value}</span>
			</div>
			{masked && <IconButton icon={revealed ? 'eyeOff' : 'eye'} label={fieldAction(revealed ? 'field.hide' : 'field.show', label)} aria-pressed={revealed} onClick={() => setRevealed(!revealed)} />}
			{actions}
			{copyable && <IconButton icon="copy" label={fieldAction('field.copy', label)} onClick={() => void copy(value, what ?? label)} />}
		</div>
	)
}
