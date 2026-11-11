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
	/** Shown where `lockedBy` would be, but leaves the option changeable. */
	note?: string
	/** The id of the reason it refuses changes, shown once for a row of checkboxes; refuses changes like `disabled`. */
	reasonId?: string
	/** A note under the option, such as a warning. */
	hint?: string
}

/** Refuses changes: disabled, locked by the organisation, or pointing at a row's shared reason. */
function isInert({ disabled = false, lockedBy, reasonId }: Props): boolean {
	return disabled || lockedBy !== undefined || reasonId !== undefined
}

/** The id of the reason shown for this option: the row's shared one, or its own when it has a reason. */
function reasonRef(id: string, { reasonId, lockedBy, note }: Props): string | undefined {
	return reasonId ?? ((lockedBy ?? note) ? `${id}-reason` : undefined)
}

function describedByOf(id: string, props: Props): string | undefined {
	return [reasonRef(id, props), props.hint ? `${id}-hint` : undefined].filter(Boolean).join(' ') || undefined
}

interface NotesProps {
	id: string
	reason: string | undefined
	reasonId: string | undefined
	hint: string | undefined
}

function OptionNotes({ id, reason, reasonId, hint }: NotesProps) {
	return (
		<>
			{reason && !reasonId && <p className="field__hint" id={`${id}-reason`}>{reason}</p>}
			{hint && <p className="field__hint" id={`${id}-hint`}>{hint}</p>}
		</>
	)
}

interface InputProps {
	id: string
	props: Props
}

/** The accessible name, refusal and description of the checkbox. */
function ariaOf(id: string, props: Props) {
	return {
		'aria-label': props.short ? props.label : undefined,
		'aria-disabled': isInert(props) || undefined,
		'aria-describedby': describedByOf(id, props),
	}
}

function CheckboxInput({ id, props }: InputProps) {
	const inert = isInert(props)
	return (
		<input
			type="checkbox"
			id={id}
			className="check__input"
			checked={props.checked}
			{...ariaOf(id, props)}
			onChange={(event) => !inert && props.onChange(event.target.checked)}
		/>
	)
}

export function OptionCheckbox(props: Props) {
	const { label, short, lockedBy, note, reasonId, hint } = props
	const id = useId()
	return (
		<>
			<label className="check">
				<CheckboxInput id={id} props={props} />
				{short ?? label}
			</label>
			<OptionNotes id={id} reason={lockedBy ?? note} reasonId={reasonId} hint={hint} />
		</>
	)
}
