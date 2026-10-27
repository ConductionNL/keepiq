import type { UriMatch } from '@/src/messages'
import { sortItems } from '@/src/vault/list'
import { baseDomain } from '@/src/vault/match'
import { webUrl } from '@/src/vault/url'

/** A page URL as the browser reports it; unlike an item url, a bare host is not one. */
function pageUrl(raw: string): URL | null {
	try {
		const url = new URL(raw)
		return (url.protocol === 'https:' || url.protocol === 'http:') && url.hostname ? url : null
	} catch {
		return null
	}
}

function matchesRegex(pattern: string, page: string): boolean {
	try {
		return new RegExp(pattern, 'i').test(page)
	} catch {
		return false
	}
}

/** Bitwarden's URI match detection, with one global rule since a Keepiq item has one url. */
export function matches(itemUrl: string | null, rawPageUrl: string, rule: UriMatch): boolean {
	const pattern = itemUrl?.trim()
	const page = pageUrl(rawPageUrl)
	if (!pattern || !page) return false
	if (rule === 'regex') return matchesRegex(pattern, page.href)
	const item = webUrl(pattern)
	if (!item) return false
	switch (rule) {
		case 'baseDomain': return baseDomain(item.href) === baseDomain(page.href)
		case 'host': return item.host === page.host
		case 'startsWith': return page.href.startsWith(item.href)
		case 'exact': return page.href === item.href
		case 'never': return false
	}
}

export interface CandidateRow {
	id: string
	name: string
	url: string | null
	typeId: string | null
	blocked: boolean
}

/** The `login` rows matching the page, last used first, then by name. `lastUsed` is item id to epoch ms. */
export function candidatesFor<T extends CandidateRow>(
	page: string | undefined,
	vault: { secrets: readonly T[]; types: ReadonlyArray<{ id: string; name: string }> },
	rule: UriMatch,
	lastUsed: Readonly<Record<string, number>>,
): T[] {
	if (page === undefined) return []
	const loginTypes = new Set(vault.types.filter((type) => type.name === 'login').map((type) => type.id))
	const found = vault.secrets.filter((row) => !row.blocked && row.typeId !== null && loginTypes.has(row.typeId) && matches(row.url, page, rule))
	const used = (id: string) => (Object.hasOwn(lastUsed, id) ? lastUsed[id]! : 0)
	// Stable, so rows used equally long ago keep their name order.
	return sortItems(found).sort((a, b) => used(b.id) - used(a.id))
}
