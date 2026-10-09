import { i18n } from '#i18n'
import { IconButton } from './Button'
import { Icon } from './Icon'

export function SearchField({ value, onChange, disabled }: { value: string; onChange: (value: string) => void; disabled?: boolean }) {
	return (
		<div className="search">
			<Icon name="search" />
			<input className="search__input" type="search" aria-label={i18n.t('filters.searchLabel')} placeholder={i18n.t('filters.search')} value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />
			{value && <IconButton icon="close" label={i18n.t('filters.clearSearch')} onClick={() => onChange('')} />}
		</div>
	)
}
