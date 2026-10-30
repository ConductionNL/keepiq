import { useId, useState } from 'react'
import { i18n } from '#i18n'
import type { VaultSnapshotReply } from '@/src/messages'
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
export function Vault({ onOpen, snapshot, snapshotFailed, reload }: Props) {
	const id = useId()
	const { syncNow } = useShell()
	const { snapshot: settings, failed, update } = useAccountSettings()
	// Only the sync started here reports its failure here, not one a later re-read shows.
	const [syncError, setSyncError] = useState<string | null>(null)
	const sync = snapshot?.sync
	const webAppUrl = snapshot?.webAppUrl ?? settings?.webAppUrl

	async function startSync() {
		if (sync?.syncing) return
		setSyncError(null)
		const after = await syncNow()
		const ended = after?.state === 'unlocked' ? after.sync : undefined
		if (!ended) setSyncError(errorText('not_responding', ''))
		else if (ended.offline) setSyncError(i18n.t('settings.syncOffline'))
		else if (ended.lastError) setSyncError(i18n.t('settings.syncFailed'))
	}

	return (
		<div className="stack">
			{(failed || (snapshotFailed && !snapshot)) && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<ul className="card menu">
				<li>
					<button type="button" className="menu__item" onClick={() => onOpen('folders')}>
						<Icon name="folder" />
						<span className="menu__label">{i18n.t('settings.folders')}</span>
						<Icon name="chevronRight" />
					</button>
				</li>
				<li>
					{/* Not `disabled` while syncing, which would drop focus from it. */}
					<button type="button" className="menu__item" aria-busy={sync?.syncing ?? false} aria-labelledby={`${id}-sync-label`} aria-describedby={sync ? `${id}-sync` : undefined} onClick={() => void startSync()}>
						<Icon name="refresh" />
						<span className="menu__label">
							<span id={`${id}-sync-label`}>{i18n.t('vault.syncNow')}</span>
							{sync && (
								<span className="menu__hint" id={`${id}-sync`}>
									{sync.syncedAt
										? i18n.t('settings.lastSync', { time: new Date(sync.syncedAt).toLocaleString(language(), { dateStyle: 'short', timeStyle: 'short' }) })
										: i18n.t('settings.lastSyncNever')}
								</span>
							)}
						</span>
					</button>
					{syncError && <p className="field__error menu__error" role="alert">{syncError}</p>}
				</li>
				{webAppUrl && (
					<li>
						<a className="menu__item" href={webAppUrl} target="_blank" rel="noreferrer">
							<Icon name="database" />
							<span className="menu__label">{i18n.t('settings.importItems')}</span>
							<Icon name="external" />
						</a>
					</li>
				)}
				{webAppUrl && (
					<li>
						{settings?.exportDisabled
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
				)}
			</ul>
			{settings && snapshot
				? <DefaultType settings={settings} types={snapshot.types} update={update} reload={reload} />
				: !failed && !snapshotFailed && <Loading />}
		</div>
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
