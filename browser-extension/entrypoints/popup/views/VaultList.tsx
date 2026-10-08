import { useMemo, useRef, useState } from 'react'
import type { TypeMeta, VaultSnapshotReply } from '@/src/messages'
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
import { loginKey, useLazyLogins } from '../hooks/useDecryptedFields'
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
	const suggestions = useMemo(() => {
		const ids = new Set(snapshot.suggestionIds)
		return sorted.filter((item) => ids.has(item.id))
	}, [sorted, snapshot.suggestionIds])
	const rendered = useMemo(() => [shown, suggestions], [shown, suggestions])
	const logins = useLazyLogins(list, rendered)

	function clearFilters() {
		setSearch(NO_FILTER.search)
		setFolder(NO_FILTER.folder)
		setTypeName(null)
	}

	const firstSync = items === null
	const webApp = <a href={snapshot.webAppUrl} target="_blank" rel="noreferrer">Open the Keepiq web app</a>
	const allBlocked = sorted.length > 0 && sorted.every((item) => item.blocked)

	let body
	if (firstSync && !sync.syncing && sync.lastError) {
		body = (
			<EmptyState icon="alert" title="Could not load your vault">
				<Button variant="secondary" onClick={syncNow}>Try again</Button>
			</EmptyState>
		)
	} else if (firstSync) {
		body = <p className="loading" role="status"><span className="spinner" aria-hidden="true" />Syncing your vault</p>
	} else if (sorted.length === 0) {
		body = <EmptyState icon="vault" title="No items in your vault">{webApp}</EmptyState>
	} else if (shown.length === 0) {
		body = (
			<EmptyState icon="search" title="No items match">
				{search.trim() && <p className="empty__text">Search covers names and websites.</p>}
				<Button variant="secondary" onClick={clearFilters}>Clear filters</Button>
			</EmptyState>
		)
	} else {
		body = (
			<section className="section" aria-labelledby="items-title">
				<h2 className="section__title" id="items-title">Items</h2>
				<ul className="items">
					{shown.slice(0, MAX_ROWS).map((item) => <ItemCard key={item.id} item={item} type={typesById.get(item.typeId)} login={logins[loginKey(item)]} onOpen={onOpen} />)}
				</ul>
				{shown.length > MAX_ROWS && <p className="hint">Showing the first {MAX_ROWS}, refine your search</p>}
			</section>
		)
	}

	return (
		<div className="stack" ref={list}>
			{!firstSync && sync.offline && sync.syncedAt && (
				<Banner tone="warning" role="status" action={<Button variant="link" busy={sync.syncing} onClick={syncNow}>Sync now</Button>}>
					Offline. Last synced {relativeTime(sync.syncedAt)}
				</Banner>
			)}
			{!firstSync && !sync.offline && sync.lastError === 'busy' && <Banner tone="warning" role="status">Server busy, retrying later</Banner>}
			<div className="filters">
				<SearchField value={search} onChange={setSearch} disabled={firstSync} />
				<FolderSelect folders={folders} value={folder} onChange={setFolder} disabled={firstSync} />
				<TypeFilterChips types={types} value={typeName} onChange={setTypeName} disabled={firstSync} />
			</div>
			{allBlocked && (
				<Banner tone="error" role="status">
					All items are blocked{sorted[0]?.blockedReason ? `: ${sorted[0].blockedReason}` : ''}. {webApp}
				</Banner>
			)}
			{!firstSync && tab.host && <Suggestions host={tab.host} items={suggestions} types={typesById} logins={logins} onOpen={onOpen} />}
			{body}
		</div>
	)
}
