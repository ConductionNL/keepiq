import { Appearance } from './Appearance'
import { SettingsIndex, type SettingsSection } from './SettingsIndex'

/** The Settings tab: the section list, or the open section. Shell owns which, for its title and back control. */
export function Settings({ section, onOpen }: { section: SettingsSection | null; onOpen: (section: SettingsSection) => void }) {
	return section === 'appearance' ? <Appearance /> : <SettingsIndex onOpen={onOpen} />
}
