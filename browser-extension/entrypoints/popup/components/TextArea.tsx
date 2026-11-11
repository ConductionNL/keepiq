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

/** What the parts of one text area share. */
interface AreaState {
	id: string
	label: string
	revealed: boolean
	errorId: string | undefined
}

function AreaLabel({ area }: { area: AreaState }) {
	return <label className="field__label" id={`${area.id}-label`} htmlFor={area.revealed ? area.id : undefined}>{area.label}</label>
}

interface HeadProps {
	area: AreaState
	/** Present when concealed: shows or hides the value. */
	onToggle: (() => void) | null
}

function AreaHead({ area, onToggle }: HeadProps) {
	if (!onToggle) return <AreaLabel area={area} />
	return (
		// The toggle keeps its place either way, so it keeps focus.
		<div className="field__head">
			<AreaLabel area={area} />
			<IconButton icon={area.revealed ? 'eyeOff' : 'eye'} label={fieldAction(area.revealed ? 'field.hide' : 'field.show', area.label)} aria-pressed={area.revealed} onClick={onToggle} />
		</div>
	)
}

/** The text area's own props, with the field's label and error taken out. */
interface AreaInput {
	attributes: Omit<Props, 'label' | 'value' | 'onChange' | 'error' | 'rows' | 'mono' | 'concealed'>
	value: string
	onChange: (value: string) => void
	rows: number
	mono: boolean
	concealed: boolean
	invalid: boolean
}

interface BodyProps {
	area: AreaState
	input: AreaInput
}

const areaClass = (mono: boolean) => `field__input field__input--area${mono ? ' field__input--mono' : ''}`

function ShownArea({ area, input }: BodyProps) {
	return (
		<textarea
			{...input.attributes}
			{...(input.concealed && { autoCapitalize: 'off', autoCorrect: 'off' })}
			// As in TextField.
			spellCheck={false}
			id={area.id}
			className={areaClass(input.mono)}
			rows={input.rows}
			value={input.value}
			onChange={(event) => input.onChange(event.target.value)}
			aria-invalid={input.invalid ? true : undefined}
			aria-describedby={area.errorId}
		/>
	)
}

function AreaBody({ area, input }: BodyProps) {
	if (area.revealed) return <ShownArea area={area} input={input} />
	// A group, not a textbox: it names the dots by the label without claiming to be a control one can type in.
	return <div className="field__box" role="group" aria-labelledby={`${area.id}-label`} aria-describedby={area.errorId}><span className="field__input field__masked">••••••••</span></div>
}

/** Separates the field's label and error from what goes onto the text area, with its defaults. */
function splitProps({ label, value, onChange, error, rows = 4, mono = false, concealed = false, ...rest }: Props) {
	const input: AreaInput = { attributes: rest, value, onChange, rows, mono, concealed, invalid: !!error }
	return { label, error, input }
}

export function TextArea(props: Props) {
	const { label, error, input } = splitProps(props)
	const id = useId()
	const [revealed, setRevealed] = useState(() => !input.concealed || input.value === '')
	const area: AreaState = { id, label, revealed, errorId: error ? `${id}-error` : undefined }
	return (
		<div className="field">
			<AreaHead area={area} onToggle={input.concealed ? () => setRevealed((r) => !r) : null} />
			<AreaBody area={area} input={input} />
			{error && <p className="field__error" id={area.errorId}>{error}</p>}
		</div>
	)
}
