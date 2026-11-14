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

interface LabelProps {
	id: string
	label: string
	revealed: boolean
}

interface HeadProps extends LabelProps {
	onToggle: () => void
}

interface AreaInputProps extends Omit<Props, 'label' | 'error'> {
	id: string
	errorId: string | undefined
	invalid: boolean
}

function AreaLabel({ id, label, revealed }: LabelProps) {
	return <label className="field__label" id={`${id}-label`} htmlFor={revealed ? id : undefined}>{label}</label>
}

function AreaInput({ id, errorId, invalid, onChange, mono, concealed, ...rest }: AreaInputProps) {
	return (
		<textarea
			{...rest}
			{...(concealed && { autoCapitalize: 'off', autoCorrect: 'off' })}
			// As in TextField.
			spellCheck={false}
			id={id}
			className={`field__input field__input--area${mono ? ' field__input--mono' : ''}`}
			onChange={(event) => onChange(event.target.value)}
			aria-invalid={invalid ? true : undefined}
			aria-describedby={errorId}
		/>
	)
}

function ConcealedHead({ onToggle, ...labelProps }: HeadProps) {
	const { label, revealed } = labelProps
	return (
		// The toggle keeps its place either way, so it keeps focus.
		<div className="field__head">
			<AreaLabel {...labelProps} />
			<IconButton icon={revealed ? 'eyeOff' : 'eye'} label={fieldAction(revealed ? 'field.hide' : 'field.show', label)} aria-pressed={revealed} onClick={onToggle} />
		</div>
	)
}

/** A group, not a textbox: it names the dots by the label without claiming to be a control one can type in. */
function MaskedArea({ id, errorId }: Pick<AreaInputProps, 'id' | 'errorId'>) {
	return <div className="field__box" role="group" aria-labelledby={`${id}-label`} aria-describedby={errorId}><span className="field__input field__masked">••••••••</span></div>
}

function AreaError({ error, errorId }: Pick<Props, 'error'> & Pick<AreaInputProps, 'errorId'>) {
	return error ? <p className="field__error" id={errorId}>{error}</p> : null
}

export function TextArea({ label, value, onChange, error, rows = 4, mono = false, concealed = false, ...rest }: Props) {
	const id = useId()
	const [revealed, setRevealed] = useState(() => !concealed || value === '')
	const errorId = error ? `${id}-error` : undefined
	const labelProps = { id, label, revealed }
	const toggle = () => setRevealed((r) => !r)
	const head = concealed ? <ConcealedHead {...labelProps} onToggle={toggle} /> : <AreaLabel {...labelProps} />
	const body = revealed
		? <AreaInput {...rest} rows={rows} value={value} onChange={onChange} mono={mono} concealed={concealed} id={id} errorId={errorId} invalid={Boolean(error)} />
		: <MaskedArea id={id} errorId={errorId} />
	return (
		<div className="field">
			{head}
			{body}
			<AreaError error={error} errorId={errorId} />
		</div>
	)
}
