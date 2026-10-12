import { i18n } from '#i18n'
import { Icon, type IconName } from '../../components/Icon'

export type SettingsSection = 'accountSecurity' | 'autofill' | 'notifications' | 'vault' | 'appearance' | 'about'

/** Bitwarden's order (ADR-001); ext-settings fills in the rest. */
const SECTIONS: { id: SettingsSection; icon: IconName }[] = [
	{ id: 'accountSecurity', icon: 'shield' },
	{ id: 'autofill', icon: 'key' },
	{ id: 'notifications', icon: 'bell' },
	{ id: 'vault', icon: 'vault' },
	{ id: 'appearance', icon: 'contrast' },
	{ id: 'about', icon: 'info' },
]

const BUILT: ReadonlySet<SettingsSection> = new Set(['appearance'])

export function sectionLabel(section: SettingsSection): string {
	switch (section) {
		case 'accountSecurity': return i18n.t('settings.accountSecurity')
		case 'autofill': return i18n.t('settings.autofill')
		case 'notifications': return i18n.t('settings.notifications')
		case 'vault': return i18n.t('settings.vault')
		case 'appearance': return i18n.t('settings.appearance')
		case 'about': return i18n.t('settings.about')
	}
}

export function SettingsIndex({ onOpen }: { onOpen: (section: SettingsSection) => void }) {
	const later = i18n.t('common.later')
	return (
		<ul className="card menu">
			{SECTIONS.map(({ id, icon }) => {
				const built = BUILT.has(id)
				return (
					<li key={id}>
						{/* `aria-disabled` keeps it focusable, so the reason can be read. */}
						<button type="button" className="menu__item" aria-disabled={built ? undefined : true} title={built ? undefined : later} onClick={built ? () => onOpen(id) : undefined}>
							<Icon name={icon} />
							<span className="menu__label">{sectionLabel(id)}</span>
							<Icon name="chevronRight" />
						</button>
					</li>
				)
			})}
		</ul>
	)
}
