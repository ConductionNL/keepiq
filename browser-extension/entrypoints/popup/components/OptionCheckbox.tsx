import { useId } from 'react'

interface Props {
	label: string
	/** Visible text when the label is too long for a compact row, e.g. "A-Z"; `label` stays the accessible name. */
	short?: string
	checked: boolean
	onChange: (checked: boolean) => void
	/** Refuses changes; `aria-disabled` rather than `disabled`, so it stays in the tab order and its reason is heard. */
	disabled?: boolean
	/** Why the organisation fixed this option; refuses changes and shows the reason. */
	lockedBy?: string
	/** The id of the reason it refuses changes, shown once for a row of checkboxes; refuses changes like `disabled`. */
	reasonId?: string
	/** A note under the option, such as a warning. */
	hint?: string
}

export function OptionCheckbox({ label, short, checked, onChange, disabled = false, lockedBy, reasonId, hint }: Props) {
	const id = useId()
	const inert = disabled || lockedBy !== undefined || reasonId !== undefined
	const describedBy = [reasonId ?? (lockedBy ? `${id}-locked` : undefined), hint ? `${id}-hint` : undefined].filter(Boolean).join(' ')
	return (
		<>
			<label className="check">
				<input
					type="checkbox"
					id={id}
					className="check__input"
					checked={checked}
					aria-label={short ? label : undefined}
					aria-disabled={inert || undefined}
					aria-describedby={describedBy || undefined}
					onChange={(event) => !inert && onChange(event.target.checked)}
				/>
				{short ?? label}
			</label>
			{lockedBy && !reasonId && <p className="field__hint" id={`${id}-locked`}>{lockedBy}</p>}
			{hint && <p className="field__hint" id={`${id}-hint`}>{hint}</p>}
		</>
	)
}
