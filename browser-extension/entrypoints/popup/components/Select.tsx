import { useId } from 'react'
import { Icon } from './Icon'

export interface SelectOption<T extends string> {
	value: T
	label: string
}

interface Props<T extends string> {
	label: string
	value: T
	options: SelectOption<T>[]
	onChange: (value: T) => void
	disabled?: boolean
}

export function Select<T extends string>({ label, value, options, onChange, disabled }: Props<T>) {
	const id = useId()
	return (
		<div className="field">
			<label className="field__label" htmlFor={id}>{label}</label>
			<div className="select">
				<select id={id} className="select__input" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value as T)}>
					{options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
				</select>
				<Icon name="chevronDown" />
			</div>
		</div>
	)
}
