import { IconButton } from './Button'
import { Icon } from './Icon'

export function SearchField({ value, onChange, disabled }: { value: string; onChange: (value: string) => void; disabled?: boolean }) {
	return (
		<div className="search">
			<Icon name="search" />
			<input className="search__input" type="search" aria-label="Search vault" placeholder="Search" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)} />
			{value && <IconButton icon="close" label="Clear search" onClick={() => onChange('')} />}
		</div>
	)
}
