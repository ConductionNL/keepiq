import { useId, useState, type TextareaHTMLAttributes } from 'react'
import { fieldAction } from '../i18n'
import { IconButton } from './Button'

interface Props extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange' | 'spellCheck'> {
	label: string
	value: string
	onChange: (value: string) => void
	error?: string | null
	/** For keys and certificates, where every character must be told apart. */
	mono?: boolean
	/** Dots until revealed, like a password field, as a text area cannot mask what it holds. An empty value has nothing to hide. */
	concealed?: boolean
}

export function TextArea({ label, value, onChange, error, rows = 4, mono = false, concealed = false, ...rest }: Props) {
	const id = useId()
	const [revealed, setRevealed] = useState(() => !concealed || value === '')
	const errorId = error ? `${id}-error` : undefined
	const labelElement = <label className="field__label" id={`${id}-label`} htmlFor={revealed ? id : undefined}>{label}</label>
	return (
		<div className="field">
			{concealed
				? (
					// The toggle keeps its place either way, so it keeps focus.
					<div className="field__head">
						{labelElement}
						<IconButton icon={revealed ? 'eyeOff' : 'eye'} label={fieldAction(revealed ? 'field.hide' : 'field.show', label)} aria-pressed={revealed} onClick={() => setRevealed((r) => !r)} />
					</div>
				)
				: labelElement}
			{revealed
				? (
					<textarea
						{...rest}
						{...(concealed && { autoCapitalize: 'off', autoCorrect: 'off' })}
						// As in TextField.
						spellCheck={false}
						id={id}
						className={`field__input field__input--area${mono ? ' field__input--mono' : ''}`}
						rows={rows}
						value={value}
						onChange={(event) => onChange(event.target.value)}
						aria-invalid={error ? true : undefined}
						aria-describedby={errorId}
					/>
				)
				// A group, not a textbox: it names the dots by the label without claiming to be a control one can type in.
				: <div className="field__box" role="group" aria-labelledby={`${id}-label`} aria-describedby={errorId}><span className="field__input field__masked">••••••••</span></div>}
			{error && <p className="field__error" id={errorId}>{error}</p>}
		</div>
	)
}
