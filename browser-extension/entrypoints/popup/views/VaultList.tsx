import { useMemo, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { BlockReason, ItemMeta, TypeMeta, VaultSnapshotReply } from '@/src/messages'
import { filterItems, NO_FILTER, sortItems, type FolderFilter } from '@/src/vault/list'
import { Button } from '../components/Button'
import { EmptyState } from '../components/EmptyState'
import { Banner } from '../components/ErrorBanner'
import { FolderSelect } from '../components/FolderSelect'
import { ItemCard } from '../components/ItemCard'
import { SearchField } from '../components/SearchField'
import { Suggestions } from '../components/Suggestions'
import { TypeFilterChips } from '../components/TypeFilterChips'
import type { CurrentTab } from '../hooks/useCurrentTab'
import { blockedText } from '../errors'
import { loginKey, useLazyLogins } from '../hooks/useDecryptedFields'
import { useFill } from '../hooks/useFill'
import { language } from '../i18n'
import { relativeTime } from '../relative-time'

/** No virtualisation yet; past this the list asks for a narrower search. */
const MAX_ROWS = 2000

type Unlocked = Extract<VaultSnapshotReply, { state: 'unlocked' }>

interface Props {
	snapshot: Unlocked
	tab: CurrentTab
	syncNow: () => void
	onOpen: (id: string) => void
}

export function VaultList({ snapshot, tab, syncNow, onOpen }: Props) {
	const [search, setSearch] = useState('')
	const [folder, setFolder] = useState<FolderFilter>('all')
	const [typeName, setTypeName] = useState<string | null>(null)
	const list = useRef<HTMLDivElement>(null)
	const { items, folders, types, sync } = snapshot

	const typesById = useMemo(() => new Map<string | null, TypeMeta>(types.map((type) => [type.id, type])), [types])
	const sorted = useMemo(() => sortItems(items ?? []), [items])
	const filter = useMemo(() => ({
		search,
		folder,
		typeIds: typeName === null ? null : new Set(types.filter((type) => type.name === typeName).map((type) => type.id)),
	}), [search, folder, typeName, types])
	const shown = useMemo(() => filterItems(sorted, filter), [sorted, filter])
	// The background's order (last used, then name), not re-sorted. It sends no blocked row; one that slips through is not filled.
	const suggestions = useMemo(() => {
		const byId = new Map((items ?? []).map((item) => [item.id, item]))
		return snapshot.suggestionIds.flatMap((id) => {
			const item = byId.get(id)
			return item && !item.blocked ? [item] : []
		})
	}, [items, snapshot.suggestionIds])
	const { fill, dialog } = useFill(tab)
	const rendered = useMemo(() => [shown, suggestions], [shown, suggestions])
	const logins = useLazyLogins(list, rendered)

	function clearFilters() {
		setSearch(NO_FILTER.search)
		setFolder(NO_FILTER.folder)
		setTypeName(null)
	}

	const firstSync = items === null
	const webApp = <a href={snapshot.webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a>
	const allBlocked = sorted.length > 0 && sorted.every((item) => item.blocked)
	let body
	if (firstSync) body = <FirstSync failed={!sync.syncing && Boolean(sync.lastError)} syncNow={syncNow} />
	else if (sorted.length === 0) body = <EmptyState icon="vault" title={i18n.t('vault.empty')}>{webApp}</EmptyState>
	else if (shown.length === 0) body = <NoMatch search={search} clearFilters={clearFilters} />
	else body = <ItemRows shown={shown} card={(item) => <ItemCard key={item.id} item={item} type={typesById.get(item.typeId)} login={logins[loginKey(item)]} onOpen={onOpen} />} />

	return (
		<div className="stack" ref={list}>
			{!firstSync && <SyncBanner sync={sync} syncNow={syncNow} />}
			<div className="filters">
				<SearchField value={search} onChange={setSearch} disabled={firstSync} />
				<FolderSelect folders={folders} value={folder} onChange={setFolder} disabled={firstSync} />
				<TypeFilterChips types={types} value={typeName} onChange={setTypeName} disabled={firstSync} />
			</div>
			{allBlocked && <AllBlocked reason={sorted[0]?.blockedReason} webApp={webApp} />}
			{!firstSync && <Suggestions host={tab.host} items={suggestions} types={typesById} logins={logins} onOpen={onOpen} onFill={fill} />}
			{body}
			{dialog}
		</div>
	)
}

function FirstSync({ failed, syncNow }: { failed: boolean; syncNow: () => void }) {
	if (!failed) return <p className="loading" role="status"><span className="spinner" aria-hidden="true" />{i18n.t('vault.syncing')}</p>
	return (
		<EmptyState icon="alert" title={i18n.t('vault.loadFailed')}>
			<Button variant="secondary" onClick={syncNow}>{i18n.t('vault.tryAgain')}</Button>
		</EmptyState>
	)
}

function NoMatch({ search, clearFilters }: { search: string; clearFilters: () => void }) {
	return (
		<EmptyState icon="search" title={i18n.t('vault.noMatch')}>
			{search.trim() && <p className="empty__text">{i18n.t('vault.searchCovers')}</p>}
			<Button variant="secondary" onClick={clearFilters}>{i18n.t('vault.clearFilters')}</Button>
		</EmptyState>
	)
}

function ItemRows({ shown, card }: { shown: ItemMeta[]; card: (item: ItemMeta) => ReactNode }) {
	return (
		<section className="section" aria-labelledby="items-title">
			<h2 className="section__title" id="items-title">{i18n.t('vault.items')}</h2>
			<ul className="items">
				{shown.slice(0, MAX_ROWS).map(card)}
			</ul>
			{shown.length > MAX_ROWS && <p className="hint">{i18n.t('vault.tooMany', { count: MAX_ROWS.toLocaleString(language()) })}</p>}
		</section>
	)
}

function AllBlocked({ reason, webApp }: { reason: BlockReason | undefined; webApp: ReactNode }) {
	return (
		<Banner tone="error" role="status">
			{i18n.t('vault.allBlocked')} {reason && <>{blockedText(reason)} </>}{webApp}
		</Banner>
	)
}

function SyncBanner({ sync, syncNow }: { sync: Unlocked['sync']; syncNow: () => void }) {
	if (sync.offline && sync.syncedAt) {
		return (
			<Banner tone="warning" role="status" action={<Button variant="link" busy={sync.syncing} onClick={syncNow}>{i18n.t('vault.syncNow')}</Button>}>
				{i18n.t('vault.offline', { time: relativeTime(sync.syncedAt) })}
			</Banner>
		)
	}
	if (!sync.offline && sync.lastError === 'busy') return <Banner tone="warning" role="status">{i18n.t('vault.busy')}</Banner>
	return null
}
