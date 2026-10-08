import type { FolderMeta, ItemMeta } from '@/src/messages'
import { webUrl } from './url'

const collator = new Intl.Collator(undefined, { sensitivity: 'base' })

/** By name, case-insensitive and locale-aware; the id keeps ties stable across renders. */
export function sortItems<T extends Pick<ItemMeta, 'id' | 'name'>>(items: T[]): T[] {
	return [...items].sort((a, b) => collator.compare(a.name, b.name) || (a.id < b.id ? -1 : a.id > b.id ? 1 : 0))
}

/** `all` and `none` besides a folder id; a folder shows its own items, not its descendants'. */
export type FolderFilter = 'all' | 'none' | string

export interface ListFilter {
	search: string
	folder: FolderFilter
	/** Type ids the active chip covers, `null` for All. */
	typeIds: ReadonlySet<string | null> | null
}

export const NO_FILTER: ListFilter = { search: '', folder: 'all', typeIds: null }

/** Search covers the plaintext name and url only; the rest is ciphertext (ADR-003). */
export function filterItems<T extends Pick<ItemMeta, 'name' | 'url' | 'folderId' | 'typeId'>>(items: T[], filter: ListFilter): T[] {
	const query = filter.search.trim().toLowerCase()
	return items.filter((item) => {
		if (filter.folder === 'none' ? item.folderId !== null : filter.folder !== 'all' && item.folderId !== filter.folder) return false
		if (filter.typeIds && !filter.typeIds.has(item.typeId)) return false
		return query === '' || item.name.toLowerCase().includes(query) || (item.url ?? '').toLowerCase().includes(query)
	})
}

export interface FlatFolder {
	id: string
	name: string
	depth: number
}

/** Depth-first, children by name; folders whose parent is missing count as roots. */
export function flattenFolders(folders: FolderMeta[]): FlatFolder[] {
	const ids = new Set(folders.map((f) => f.id))
	const children = new Map<string | null, FolderMeta[]>()
	for (const folder of folders) {
		const parent = folder.parentId !== null && ids.has(folder.parentId) ? folder.parentId : null
		children.set(parent, [...(children.get(parent) ?? []), folder])
	}
	const out: FlatFolder[] = []
	const seen = new Set<string>()
	const walk = (parent: string | null, depth: number) => {
		for (const folder of sortItems(children.get(parent) ?? [])) {
			if (seen.has(folder.id)) continue
			seen.add(folder.id)
			out.push({ id: folder.id, name: folder.name, depth })
			walk(folder.id, depth + 1)
		}
	}
	walk(null, 0)
	return out
}

/** "Work / Clients", or `null` without a folder. */
export function folderPath(folders: FolderMeta[], folderId: string | null): string | null {
	const byId = new Map(folders.map((f) => [f.id, f]))
	const names: string[] = []
	const seen = new Set<string>()
	for (let id = folderId; id !== null && !seen.has(id);) {
		const folder = byId.get(id)
		if (!folder) break
		seen.add(id)
		names.unshift(folder.name)
		id = folder.parentId
	}
	return names.length > 0 ? names.join(' / ') : null
}

/** What Launch opens, by the rules in `url.ts`. */
export function launchUrl(url: string | null): string | null {
	return webUrl(url)?.href ?? null
}
