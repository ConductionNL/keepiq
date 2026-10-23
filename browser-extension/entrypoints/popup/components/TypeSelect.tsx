import { i18n } from '#i18n'
import type { TypeMeta } from '@/src/messages'
import { Select } from './Select'

/** Passkeys come from a passkey ceremony, so they are offered only to show an existing one's type. */
export function TypeSelect({ types, value, onChange, disabled }: { types: TypeMeta[]; value: string; onChange: (typeId: string) => void; disabled?: boolean }) {
	const options = types.filter((type) => type.name !== 'passkey' || type.id === value).map((type) => ({ value: type.id, label: type.label }))
	return <Select label={i18n.t('form.type')} value={value} options={options} onChange={onChange} disabled={disabled} />
}
