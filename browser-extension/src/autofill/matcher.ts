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
	const target = matchTarget(itemUrl, rawPageUrl)
	if (!target) return false
	if (rule === 'regex' && isPattern(target.url)) return matchesRegex(target.url, target.page.href)
	const item = webUrl(target.url)
	if (!item) return false
	// A rule this version does not know matches nothing, as a `switch` without it did.
	return RULE_MATCHES.get(rule)?.(item, target.page) as boolean
}

/** The item's url, trimmed, and the page's, when both are there. */
function matchTarget(itemUrl: string | null, rawPageUrl: string): { url: string; page: URL } | null {
	const url = itemUrl?.trim()
	const page = pageUrl(rawPageUrl)
	return url && page ? { url, page } : null
}

const sameBaseDomain = (item: URL, page: URL) => baseDomain(item.href) === baseDomain(page.href)

/** How each rule compares an item's url with the page's; under `regex` an ordinary url compares as under `baseDomain`. */
const RULE_MATCHES = new Map<UriMatch, (item: URL, page: URL) => boolean>([
	['regex', sameBaseDomain],
	['baseDomain', sameBaseDomain],
	['host', (item, page) => item.host === page.host],
	['startsWith', (item, page) => page.href.startsWith(item.href)],
	['exact', (item, page) => page.href === item.href],
	['never', () => false],
])

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
	return byLastUsed(found, lastUsed)
}

export function byLastUsed<T extends { id: string; name: string }>(rows: T[], lastUsed: Readonly<Record<string, number>>): T[] {
	const used = (id: string) => (Object.hasOwn(lastUsed, id) ? lastUsed[id]! : 0)
	// Stable, so rows used equally long ago keep their name order.
	return sortItems(rows).sort((a, b) => used(b.id) - used(a.id))
}
