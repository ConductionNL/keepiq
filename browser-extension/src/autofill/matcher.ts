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

const REGEX_SYNTAX = /[\^$*+()[\]{}|\\]/
/** Up to and including an IPv6 host's brackets, after any scheme and userinfo. */
const IPV6_HOST = /^((?:[a-z][a-z\d+.-]*:\/\/)?(?:[^/?#@[]*@)?)\[[\da-f]*:[\da-f:.]*\]/i

/**
 * Default deny: only regex syntax before the query or fragment, where `+` and the like are ordinary, makes an item url
 * a pattern; so does a `?` in its scheme (`https?://`).
 */
function isPattern(url: string): boolean {
	if (/^[a-z][a-z\d+-]*\?[a-z\d+-]*:\/\//i.test(url)) return true
	return REGEX_SYNTAX.test(url.split(/[?#]/, 1)[0]!.replace(IPV6_HOST, '$1'))
}

/**
 * Bitwarden's URI match detection, with one global rule since a Keepiq item has one url. Under `regex` any other url
 * is ordinary and never runs as a pattern: unanchored, `https://bank.com` would match `https://bank.com.evil.net/`.
 */
export function matches(itemUrl: string | null, rawPageUrl: string, rule: UriMatch): boolean {
	const url = itemUrl?.trim()
	const page = pageUrl(rawPageUrl)
	if (!url || !page) return false
	if (rule === 'regex' && isPattern(url)) return matchesRegex(url, page.href)
	const item = webUrl(url)
	if (!item) return false
	switch (rule) {
		case 'regex':
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
