import type { TypeMeta } from '@/src/messages'
import { Menu } from './Menu'

/** The fixed chips by type name; every other type in the snapshot goes under More. */
const CHIPS = [
	['login', 'Login'], ['card', 'Card'], ['identity', 'Identity'], ['note', 'Note'], ['totp', 'TOTP'], ['passkey', 'Passkey'],
] as const

/** `null` is All; otherwise a type name. */
export function TypeFilterChips({ types, value, onChange, disabled }: { types: TypeMeta[]; value: string | null; onChange: (value: string | null) => void; disabled?: boolean }) {
	const fixed = new Set<string>(CHIPS.map(([name]) => name))
	const more = types.filter((type) => !fixed.has(type.name)).sort((a, b) => a.label.localeCompare(b.label))
	const chosenMore = more.find((type) => type.name === value)

	const chip = (name: string | null, label: string) => (
		<button key={name ?? 'all'} type="button" className="chip" aria-pressed={value === name} disabled={disabled} onClick={() => onChange(name)}>
			{label}
		</button>
	)

	return (
		<div className="chips" role="group" aria-label="Type">
			{chip(null, 'All')}
			{CHIPS.map(([name, label]) => chip(name, label))}
			{more.length > 0 && (
				<Menu
					label="More types"
					text={chosenMore?.label ?? 'More'}
					className="chip"
					pressed={chosenMore !== undefined}
					disabled={disabled}
					items={more.map((type) => ({ label: type.label, onSelect: () => onChange(type.name) }))}
				/>
			)}
		</div>
	)
}
