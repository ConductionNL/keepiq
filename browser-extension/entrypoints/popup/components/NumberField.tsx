import { useId, useState } from 'react'

interface Props {
	label: string
	/** Names the input without showing the label, e.g. beside a slider that shows it. */
	hideLabel?: boolean
	value: number
	min: number
	max: number
	onChange: (value: number) => void
	disabled?: boolean
	hint?: string
	/** The id of a description rendered elsewhere, e.g. the slider's lock reason beside a hidden label. */
	describedBy?: string
}

/** A whole number in a range; a value being typed outside it waits, and blur shows the last valid one. */
export function NumberField({ label, hideLabel = false, value, min, max, onChange, disabled, hint, describedBy }: Props) {
	const id = useId()
	const [draft, setDraft] = useState<string | null>(null)

	function onInput(text: string) {
		setDraft(text)
		const number = Number(text)
		if (text !== '' && Number.isInteger(number) && number >= min && number <= max && number !== value) onChange(number)
	}

	const input = (
		<input
			id={id}
			type="number"
			inputMode="numeric"
			className="number-field__input"
			min={min}
			max={max}
			step={1}
			value={draft ?? String(value)}
			disabled={disabled}
			aria-label={hideLabel ? label : undefined}
			aria-describedby={describedBy ?? (hint && !hideLabel ? `${id}-hint` : undefined)}
			onChange={(event) => onInput(event.target.value)}
			onBlur={() => setDraft(null)}
		/>
	)
	if (hideLabel) return input
	return (
		<div className="number-field">
			<label className="number-field__label" htmlFor={id}>{label}</label>
			{input}
			{hint && <p className="field__hint number-field__hint" id={`${id}-hint`}>{hint}</p>}
		</div>
	)
}
