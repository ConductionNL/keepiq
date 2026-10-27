import type { AccountSummary, VaultSnapshotReply } from '@/src/messages'
import { Loading } from '../../components/Loading'
import { About } from './About'
import { AccountSecurity } from './AccountSecurity'
import { Appearance } from './Appearance'
import { Autofill } from './Autofill'
import { ExcludedDomains } from './ExcludedDomains'
import { FolderManager } from './FolderManager'
import { Notifications } from './Notifications'
import { SettingsIndex, type SettingsSection } from './SettingsIndex'
import { Vault } from './Vault'

interface Props {
	section: SettingsSection | null
	onOpen: (section: SettingsSection) => void
	/** The account the popup shows, for Log out. */
	account: AccountSummary
	/** The vault tab's snapshot, for the folder manager and the Vault section; `undefined` while it loads. */
	snapshot: Extract<VaultSnapshotReply, { state: 'unlocked' }> | undefined
	/** The background did not answer the snapshot read. */
	snapshotFailed: boolean
	/** Re-reads that snapshot. */
	reload: () => void
}

/** The Settings tab: the section list, or the open section. Shell owns which, for its title and back control. */
export function Settings({ section, onOpen, account, snapshot, snapshotFailed, reload }: Props) {
	switch (section) {
		case 'accountSecurity': return <AccountSecurity account={account} />
		case 'autofill': return <Autofill />
		case 'notifications': return <Notifications onOpen={onOpen} />
		case 'excludedDomains': return <ExcludedDomains />
		case 'vault': return <Vault onOpen={onOpen} snapshot={snapshot} snapshotFailed={snapshotFailed} reload={reload} />
		case 'folders': return snapshot ? <FolderManager snapshot={snapshot} /> : <Loading />
		case 'appearance': return <Appearance />
		case 'about': return <About />
		default: return <SettingsIndex onOpen={onOpen} />
	}
}
