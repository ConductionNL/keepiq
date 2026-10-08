import { i18n } from '#i18n'
import type { TypeMeta } from '@/src/messages'
import { language } from '../i18n'
import { Menu } from './Menu'

/** The fixed chips by type name; every other type in the snapshot goes under More. */
const CHIPS = ['login', 'card', 'identity', 'note', 'totp', 'passkey'] as const

function chipLabel(name: (typeof CHIPS)[number]): string {
	switch (name) {
		case 'login': return i18n.t('filters.login')
		case 'card': return i18n.t('filters.card')
		case 'identity': return i18n.t('filters.identity')
		case 'note': return i18n.t('filters.note')
		case 'totp': return i18n.t('filters.totp')
		case 'passkey': return i18n.t('filters.passkey')
	}
}

/** `null` is All; otherwise a type name. */
export function TypeFilterChips({ types, value, onChange, disabled }: { types: TypeMeta[]; value: string | null; onChange: (value: string | null) => void; disabled?: boolean }) {
	const fixed = new Set<string>(CHIPS)
	const more = types.filter((type) => !fixed.has(type.name)).sort((a, b) => a.label.localeCompare(b.label, language()))
	const chosenMore = more.find((type) => type.name === value)

	const chip = (name: string | null, label: string) => (
		<button key={name ?? 'all'} type="button" className="chip" aria-pressed={value === name} disabled={disabled} onClick={() => onChange(name)}>
			{label}
		</button>
	)

	return (
		<div className="chips" role="group" aria-label={i18n.t('filters.type')}>
			{chip(null, i18n.t('filters.all'))}
			{CHIPS.map((name) => chip(name, chipLabel(name)))}
			{more.length > 0 && (
				<Menu
					label={i18n.t('filters.moreTypes')}
					text={chosenMore?.label ?? i18n.t('filters.more')}
					className="chip"
					pressed={chosenMore !== undefined}
					disabled={disabled}
					items={more.map((type) => ({ label: type.label, onSelect: () => onChange(type.name) }))}
				/>
			)}
		</div>
	)
}
