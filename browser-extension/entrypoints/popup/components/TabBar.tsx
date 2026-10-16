import { i18n } from '#i18n'
import type { PopupTab } from '@/src/messages'
import { Icon, type IconName } from './Icon'

export const TABS: ReadonlyArray<{ id: PopupTab; icon: IconName }> = [
	{ id: 'vault', icon: 'vault' },
	{ id: 'generator', icon: 'refresh' },
	{ id: 'send', icon: 'send' },
	{ id: 'settings', icon: 'settings' },
]

export function tabLabel(tab: PopupTab): string {
	switch (tab) {
		case 'vault': return i18n.t('tabs.vault')
		case 'generator': return i18n.t('tabs.generator')
		case 'send': return i18n.t('tabs.send')
		case 'settings': return i18n.t('tabs.settings')
	}
}

export function TabBar({ active, onSelect }: { active: PopupTab; onSelect: (tab: PopupTab) => void }) {
	return (
		<nav className="tabbar" aria-label={i18n.t('tabs.label')}>
			{TABS.map((tab) => (
				<button key={tab.id} type="button" className="tabbar__tab" aria-current={tab.id === active ? 'page' : undefined} onClick={() => onSelect(tab.id)}>
					<Icon name={tab.icon} size={20} />
					<span>{tabLabel(tab.id)}</span>
				</button>
			))}
		</nav>
	)
}
