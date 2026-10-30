import { i18n } from '#i18n'
import { Icon, type IconName } from '../../components/Icon'

export type SettingsSection = 'accountSecurity' | 'autofill' | 'notifications' | 'vault' | 'appearance' | 'about' | 'folders' | 'excludedDomains'

/** Pages inside a section, which back returns to. */
export const SECTION_PARENT: Partial<Record<SettingsSection, SettingsSection>> = { folders: 'vault', excludedDomains: 'notifications' }

/** Bitwarden's order (ADR-001). */
const SECTIONS: { id: Exclude<SettingsSection, 'folders' | 'excludedDomains'>; icon: IconName }[] = [
	{ id: 'accountSecurity', icon: 'shield' },
	{ id: 'autofill', icon: 'key' },
	{ id: 'notifications', icon: 'bell' },
	{ id: 'vault', icon: 'vault' },
	{ id: 'appearance', icon: 'contrast' },
	{ id: 'about', icon: 'info' },
]

export function sectionLabel(section: SettingsSection): string {
	switch (section) {
		case 'accountSecurity': return i18n.t('settings.accountSecurity')
		case 'autofill': return i18n.t('settings.autofill')
		case 'notifications': return i18n.t('settings.notifications')
		case 'vault': return i18n.t('settings.vault')
		case 'appearance': return i18n.t('settings.appearance')
		case 'about': return i18n.t('settings.about')
		case 'folders': return i18n.t('settings.folders')
		case 'excludedDomains': return i18n.t('settings.excludedDomains')
	}
}

export function SettingsIndex({ onOpen }: { onOpen: (section: SettingsSection) => void }) {
	return (
		<ul className="card menu">
			{SECTIONS.map(({ id, icon }) => (
				<li key={id}>
					<button type="button" className="menu__item" onClick={() => onOpen(id)}>
						<Icon name={icon} />
						<span className="menu__label">{sectionLabel(id)}</span>
						<Icon name="chevronRight" />
					</button>
				</li>
			))}
		</ul>
	)
}
