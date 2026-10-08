import { forwardRef, useId, useState, type InputHTMLAttributes, type ReactNode } from 'react'
import { IconButton } from './Button'

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> {
	label: string
	value: string
	onChange: (value: string) => void
	hint?: ReactNode
	error?: string | null
}

/** A labelled input; `type="password"` gets a show/hide toggle that keeps the value. */
export const TextField = forwardRef<HTMLInputElement, Props>(function TextField({ label, value, onChange, hint, error, type = 'text', ...rest }, ref) {
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
						label={revealed ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
						onClick={() => setRevealed((r) => !r)}
						aria-pressed={revealed}
					/>
				)}
			</div>
			{hint && <p className="field__hint" id={`${id}-hint`}>{hint}</p>}
			{error && <p className="field__error" id={`${id}-error`}>{error}</p>}
		</div>
	)
})
