import { forwardRef, useId, useState, type InputHTMLAttributes } from 'react'

interface Props extends Omit<InputHTMLAttributes<HTMLInputElement>, 'onChange'> {
	label: string
	value: string
	onChange: (value: string) => void
	error?: string | null
}

/** A labelled input; `type="password"` gets a show/hide toggle that keeps the value. */
export const TextField = forwardRef<HTMLInputElement, Props>(function TextField({ label, value, onChange, error, type = 'text', ...rest }, ref) {
	const id = useId()
	const [revealed, setRevealed] = useState(false)
	const isPassword = type === 'password'
	return (
		<div className="field">
			<label className="field__label" htmlFor={id}>{label}</label>
			<div className="field__row">
				<input
					{...rest}
					ref={ref}
					id={id}
					className="field__input"
					type={isPassword && revealed ? 'text' : type}
					value={value}
					onChange={(event) => onChange(event.target.value)}
					aria-invalid={error ? true : undefined}
					aria-describedby={error ? `${id}-error` : undefined}
				/>
				{isPassword && (
					<button
						type="button"
						className="field__toggle"
						onClick={() => setRevealed((r) => !r)}
						aria-label={revealed ? `Hide ${label.toLowerCase()}` : `Show ${label.toLowerCase()}`}
						aria-pressed={revealed}
					>
						{revealed ? 'Hide' : 'Show'}
					</button>
				)}
			</div>
			{error && <p className="field__error" id={`${id}-error`}>{error}</p>}
		</div>
	)
})
