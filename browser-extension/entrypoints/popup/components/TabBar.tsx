import type { PopupTab } from '@/src/messages'
import { Icon, type IconName } from './Icon'

export const TABS: ReadonlyArray<{ id: PopupTab; label: string; icon: IconName }> = [
	{ id: 'vault', label: 'Vault', icon: 'vault' },
	{ id: 'generator', label: 'Generator', icon: 'refresh' },
	{ id: 'send', label: 'Send', icon: 'send' },
	{ id: 'settings', label: 'Settings', icon: 'settings' },
]

export function TabBar({ active, onSelect }: { active: PopupTab; onSelect: (tab: PopupTab) => void }) {
	return (
		<nav className="tabbar" aria-label="Main">
			{TABS.map((tab) => (
				<button key={tab.id} type="button" className="tabbar__tab" aria-current={tab.id === active ? 'page' : undefined} onClick={() => onSelect(tab.id)}>
					<Icon name={tab.icon} size={20} />
					<span>{tab.label}</span>
				</button>
			))}
		</nav>
	)
}
