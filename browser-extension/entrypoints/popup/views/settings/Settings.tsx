import type { VaultSnapshotReply } from '@/src/messages'
import { Loading } from '../../components/Loading'
import { Appearance } from './Appearance'
import { FolderManager } from './FolderManager'
import { SettingsIndex, type SettingsSection } from './SettingsIndex'
import { Vault } from './Vault'

interface Props {
	section: SettingsSection | null
	onOpen: (section: SettingsSection) => void
	/** The folder manager renders from it; `undefined` while it loads. */
	snapshot: Extract<VaultSnapshotReply, { state: 'unlocked' }> | undefined
}

/** The Settings tab: the section list, or the open section. Shell owns which, for its title and back control. */
export function Settings({ section, onOpen, snapshot }: Props) {
	switch (section) {
		case 'appearance': return <Appearance />
		case 'vault': return <Vault onOpen={onOpen} />
		case 'folders': return snapshot ? <FolderManager snapshot={snapshot} /> : <Loading />
		default: return <SettingsIndex onOpen={onOpen} />
	}
}
