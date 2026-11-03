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

/** A scheme, `https?` included. */
const SCHEME = /^[a-z][a-z\d+.-]*\??:\/\//i

function matchesRegex(pattern: string, page: string): boolean {
	try {
		// Sticky anchors a pattern that starts with a scheme, so it never matches inside another site's query.
		return new RegExp(pattern, SCHEME.test(pattern) ? 'iy' : 'i').test(page)
	} catch {
		return false
	}
}

const REGEX_SYNTAX = /[\^$*+()[\]{}|\\]/
const IPV6_HOST = /(^|@)\[[\da-f]*:[\da-f:.]*\]/i

/**
 * Default deny: an item url is a pattern only when it starts with `^`, has a `?` in its scheme (`https?://`) or regex
 * syntax in its host, so a plain URL with `(` or `+` in its path (`/wiki/Foo_(bar)`, `/c++/`) stays an address.
 */
function isPattern(url: string): boolean {
	if (url.startsWith('^') || /^[a-z][a-z\d+-]*\?[a-z\d+-]*:\/\//i.test(url)) return true
	const authority = url.replace(SCHEME, '').split(/[/?#]/, 1)[0]!
	return REGEX_SYNTAX.test(authority.replace(IPV6_HOST, '$1'))
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
