import { useId, type TextareaHTMLAttributes } from 'react'

interface Props extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'onChange'> {
	label: string
	value: string
	onChange: (value: string) => void
	error?: string | null
	/** For keys and certificates, where every character must be told apart. */
	mono?: boolean
}

export function TextArea({ label, value, onChange, error, rows = 4, mono = false, ...rest }: Props) {
	const id = useId()
	return (
		<div className="field">
			<label className="field__label" htmlFor={id}>{label}</label>
			<textarea
				{...rest}
				id={id}
				className={`field__input field__input--area${mono ? ' field__input--mono' : ''}`}
				rows={rows}
				value={value}
				onChange={(event) => onChange(event.target.value)}
				aria-invalid={error ? true : undefined}
				aria-describedby={error ? `${id}-error` : undefined}
			/>
			{error && <p className="field__error" id={`${id}-error`}>{error}</p>}
		</div>
	)
}
