import { useState, type ReactNode } from 'react'
import { useClipboard } from '../hooks/useClipboard'
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

/** "Password" → "password", but "API key" and "BSN" keep their capitals. */
const inSentence = (label: string) => label.replace(/^[A-Z][a-z]/, (start) => start.toLowerCase())

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
			{masked && <IconButton icon={revealed ? 'eyeOff' : 'eye'} label={`${revealed ? 'Hide' : 'Show'} ${inSentence(label)}`} aria-pressed={revealed} onClick={() => setRevealed(!revealed)} />}
			{actions}
			{copyable && <IconButton icon="copy" label={`Copy ${inSentence(label)}`} onClick={() => void copy(value, what ?? label)} />}
		</div>
	)
}
