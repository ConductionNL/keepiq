import { i18n } from '#i18n'
import { Icon } from '../../components/Icon'
import type { SettingsSection } from './SettingsIndex'

/** Bitwarden's Settings → Vault; ext-settings adds Sync now, Import, Export and Default item type. */
export function Vault({ onOpen }: { onOpen: (section: SettingsSection) => void }) {
	return (
		<ul className="card menu">
			<li>
				<button type="button" className="menu__item" onClick={() => onOpen('folders')}>
					<Icon name="folder" />
					<span className="menu__label">{i18n.t('settings.folders')}</span>
					<Icon name="chevronRight" />
				</button>
			</li>
		</ul>
	)
}
