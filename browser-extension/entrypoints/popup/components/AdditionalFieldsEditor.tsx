import { useState } from 'react'
import { i18n } from '#i18n'
import { customField, hasLineBreak, keepLineBreaks, type CustomField, type FormErrors } from '@/src/vault/fields'
import { fieldErrorText } from '../errors'
import { Button, IconButton } from './Button'
import { Icon } from './Icon'
import { TextArea } from './TextArea'
import { TextField } from './TextField'

interface Props {
	fields: CustomField[]
	/** The errors to show now, keyed as `validate` keys them. */
	errors: FormErrors
	onChange: (fields: CustomField[]) => void
}

/** Only an opened value can hold a line break, as the single-line input takes none. */
function FieldValue({ label, value, error, onChange }: { label: string; value: string; error: string | null | undefined; onChange: (value: string) => void }) {
	const [original] = useState(value)
	return hasLineBreak(original)
		? <TextArea label={label} value={value} onChange={(typed) => onChange(keepLineBreaks(typed, original))} error={error} mono concealed />
		: <TextField label={label} type="password" value={value} onChange={onChange} error={error} autoComplete="off" />
}

/** Values are masked until revealed; the blob stores no field type, so that is view state only. */
export function AdditionalFieldsEditor({ fields, errors, onChange }: Props) {
	const update = (id: number, patch: Partial<CustomField>) => onChange(fields.map((field) => field.id === id ? { ...field, ...patch } : field))
	const error = (path: string) => errors[path] && fieldErrorText(errors[path])
	return (
		<div className="custom-fields">
			{fields.map((field) => (
				<div key={field.id} className="custom-field">
					<div className="custom-field__inputs">
						<TextField label={i18n.t('form.fieldName')} value={field.name} onChange={(name) => update(field.id, { name })} error={error(`field.${field.id}.name`)} autoComplete="off" verbatim />
						<FieldValue label={field.name.trim() || i18n.t('form.fieldValue')} value={field.value} onChange={(value) => update(field.id, { value })} error={error(`field.${field.id}.value`)} />
					</div>
					<IconButton
						icon="trash"
						label={field.name.trim() ? i18n.t('form.removeField', { name: field.name.trim() }) : i18n.t('form.removeUnnamed')}
						onClick={() => onChange(fields.filter((other) => other.id !== field.id))}
					/>
				</div>
			))}
			<Button variant="link" onClick={() => onChange([...fields, customField()])}><Icon name="plus" />{i18n.t('form.addField')}</Button>
		</div>
	)
}
