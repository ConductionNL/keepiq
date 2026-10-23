import { forwardRef, useId, useState, type InputHTMLAttributes, type ReactNode } from 'react'
import { fieldAction } from '../i18n'
import { IconButton } from './Button'
import type { IconName } from './Icon'

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange' | 'spellCheck'> {
	label: string
	value: string
	onChange: (value: string) => void
	hint?: ReactNode
	error?: string | null
	/** No capitals or corrections from a phone keyboard, e.g. for a username or URL. A password field always is. */
	verbatim?: boolean
	/** An icon button after the input, e.g. to generate its value. */
	action?: { icon: IconName; label: string; onClick: (button: HTMLButtonElement) => void }
}

/** A labelled input; `type="password"` gets a show/hide toggle that keeps the value. */
export const TextField = forwardRef<HTMLInputElement, Props>(function TextField({ label, value, onChange, hint, error, verbatim = false, action, type = 'text', ...rest }, ref) {
	const id = useId()
	const [revealed, setRevealed] = useState(false)
	const isPassword = type === 'password'
	const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(' ')
	return (
		<div className="field">
			<label className="field__label" htmlFor={id}>{label}</label>
			<div className="field__box">
				<input
					{...rest}
					{...((verbatim || isPassword) && { autoCapitalize: 'off', autoCorrect: 'off' })}
					// Every field here holds vault or account data, which a browser's spelling service may send to Google or Microsoft; a revealed password is plain text to it.
					spellCheck={false}
					ref={ref}
					id={id}
					className="field__input"
					type={isPassword && revealed ? 'text' : type}
					value={value}
					onChange={(event) => onChange(event.target.value)}
					aria-invalid={error ? true : undefined}
					aria-describedby={describedBy || undefined}
				/>
				{isPassword && (
					<IconButton
						icon={revealed ? 'eyeOff' : 'eye'}
						label={fieldAction(revealed ? 'field.hide' : 'field.show', label)}
						onClick={() => setRevealed((r) => !r)}
						aria-pressed={revealed}
					/>
				)}
				{action && <IconButton icon={action.icon} label={action.label} onClick={(event) => action.onClick(event.currentTarget)} />}
			</div>
			{hint && <p className="field__hint" id={`${id}-hint`}>{hint}</p>}
			{error && <p className="field__error" id={`${id}-error`}>{error}</p>}
		</div>
	)
})
