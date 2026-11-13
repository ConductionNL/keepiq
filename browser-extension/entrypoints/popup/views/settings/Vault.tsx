import { useId, useState } from 'react'
import { i18n } from '#i18n'
import type { SyncStatus, VaultSnapshotReply } from '@/src/messages'
import { isNewItemType } from '@/src/vault/fields'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { Select } from '../../components/Select'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import { language } from '../../i18n'
import { useShell } from '../../shell-context'
import type { SettingsSection } from './SettingsIndex'

interface Props {
	onOpen: (section: SettingsSection) => void
	/** The vault tab's snapshot, for the cached types and the last sync; `undefined` while it loads. */
	snapshot: Extract<VaultSnapshotReply, { state: 'unlocked' }> | undefined
	/** The background did not answer that snapshot's read. */
	snapshotFailed: boolean
	/** Re-reads that snapshot, so New starts with a changed default type. */
	reload: () => void
}

/** Bitwarden's Settings → Vault; Import and Export open the Keepiq web app (ADR-003). */
type AccountSettings = ReturnType<typeof useAccountSettings>

export function Vault({ onOpen, snapshot, snapshotFailed, reload }: Props) {
	const id = useId()
	const account = useAccountSettings()
	const webAppUrl = snapshot?.webAppUrl ?? account.snapshot?.webAppUrl
	const unanswered = account.failed || (snapshotFailed && !snapshot)

	return (
		<div className="stack">
			<NotResponding shown={unanswered} />
			<ul className="card menu">
				<FoldersItem onOpen={onOpen} />
				<SyncItem id={id} sync={snapshot?.sync} />
				<WebAppItems id={id} webAppUrl={webAppUrl} exportDisabled={account.snapshot?.exportDisabled ?? false} />
			</ul>
			<DefaultTypeCard account={account} snapshot={snapshot} snapshotFailed={snapshotFailed} reload={reload} />
		</div>
	)
}

function NotResponding({ shown }: { shown: boolean }) {
	return shown ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : null
}

function FoldersItem({ onOpen }: Pick<Props, 'onOpen'>) {
	return (
		<li>
			<button type="button" className="menu__item" onClick={() => onOpen('folders')}>
				<Icon name="folder" />
				<span className="menu__label">{i18n.t('settings.folders')}</span>
				<Icon name="chevronRight" />
			</button>
		</li>
	)
}

interface DefaultTypeCardProps extends Pick<Props, 'snapshot' | 'snapshotFailed' | 'reload'> {
	account: AccountSettings
}

/** The default type once both the settings and the snapshot are in; the spinner while either still loads. */
function DefaultTypeCard({ account, snapshot, snapshotFailed, reload }: DefaultTypeCardProps) {
	const { snapshot: settings, failed, update } = account
	if (settings && snapshot) return <DefaultType settings={settings} types={snapshot.types} update={update} reload={reload} />
	return !failed && !snapshotFailed ? <Loading /> : null
}

function syncErrorOf(after: VaultSnapshotReply | undefined): string | null {
	const ended = after?.state === 'unlocked' ? after.sync : undefined
	if (!ended) return errorText('not_responding', '')
	if (ended.offline) return i18n.t('settings.syncOffline')
	return ended.lastError ? i18n.t('settings.syncFailed') : null
}

function lastSyncText(syncedAt: string | null): string {
	return syncedAt
		? i18n.t('settings.lastSync', { time: new Date(syncedAt).toLocaleString(language(), { dateStyle: 'short', timeStyle: 'short' }) })
		: i18n.t('settings.lastSyncNever')
}

interface SyncProps {
	id: string
	sync: SyncStatus | undefined
}

function SyncHint({ id, sync }: SyncProps) {
	return sync ? <span className="menu__hint" id={`${id}-sync`}>{lastSyncText(sync.syncedAt)}</span> : null
}

/** `id` is the page's, which the item's ids extend. */
function SyncItem({ id, sync }: SyncProps) {
	const { syncNow } = useShell()
	// Only the sync started here reports its failure here, not one a later re-read shows.
	const [syncError, setSyncError] = useState<string | null>(null)

	async function startSync() {
		if (sync?.syncing) return
		setSyncError(null)
		setSyncError(syncErrorOf(await syncNow()))
	}

	return (
		<li>
			{/* Not `disabled` while syncing, which would drop focus from it. */}
			<button type="button" className="menu__item" aria-busy={sync?.syncing ?? false} aria-labelledby={`${id}-sync-label`} aria-describedby={sync ? `${id}-sync` : undefined} onClick={() => void startSync()}>
				<Icon name="refresh" />
				<span className="menu__label">
					<span id={`${id}-sync-label`}>{i18n.t('vault.syncNow')}</span>
					<SyncHint id={id} sync={sync} />
				</span>
			</button>
			{syncError && <p className="field__error menu__error" role="alert">{syncError}</p>}
		</li>
	)
}

/** Nothing until the web app's address is known. */
function WebAppItems({ id, webAppUrl, exportDisabled }: { id: string; webAppUrl: string | undefined; exportDisabled: boolean }) {
	if (!webAppUrl) return null
	return (
		<>
			<li>
				<a className="menu__item" href={webAppUrl} target="_blank" rel="noreferrer">
					<Icon name="database" />
					<span className="menu__label">{i18n.t('settings.importItems')}</span>
					<Icon name="external" />
				</a>
			</li>
			<ExportItem id={id} webAppUrl={webAppUrl} disabled={exportDisabled} />
		</>
	)
}

function ExportItem({ id, webAppUrl, disabled }: { id: string; webAppUrl: string; disabled: boolean }) {
	return (
		<li>
			{disabled
				? (
					// `aria-disabled` keeps it focusable, so the reason can be read.
					<button type="button" className="menu__item" aria-disabled="true" aria-labelledby={`${id}-export-label`} aria-describedby={`${id}-export`}>
						<Icon name="database" />
						<span className="menu__label">
							<span id={`${id}-export-label`}>{i18n.t('settings.exportVault')}</span>
							<span className="menu__hint" id={`${id}-export`}>{i18n.t('settings.turnedOffByOrganisation')}</span>
						</span>
					</button>
				)
				: (
					<a className="menu__item" href={webAppUrl} target="_blank" rel="noreferrer">
						<Icon name="database" />
						<span className="menu__label">{i18n.t('settings.exportVault')}</span>
						<Icon name="external" />
					</a>
				)}
		</li>
	)
}

interface DefaultTypeProps {
	settings: NonNullable<ReturnType<typeof useAccountSettings>['snapshot']>
	types: Extract<VaultSnapshotReply, { state: 'unlocked' }>['types']
	update: ReturnType<typeof useAccountSettings>['update']
	reload: () => void
}

function DefaultType({ settings, types, update, reload }: DefaultTypeProps) {
	const usable = types.filter((type) => isNewItemType(type.name))
	const value = usable.some((type) => type.name === settings.values.defaultItemType) ? settings.values.defaultItemType : 'login'
	return (
		<div className="card form-card">
			<Select<string>
				label={i18n.t('settings.defaultItemType')}
				value={value}
				options={usable.map((type) => ({ value: type.name, label: type.label }))}
				onChange={(defaultItemType) => void update({ defaultItemType }).then((stored) => stored && reload())}
			/>
		</div>
	)
}
