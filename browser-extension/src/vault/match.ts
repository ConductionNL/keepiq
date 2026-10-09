import { getDomain } from 'tldts'
import { webUrl } from './url'

/**
 * The registrable domain by the public suffix list, so `login.example.co.uk` gives
 * `example.co.uk`. Private suffixes count too, so one `github.io` site never matches another.
 * IPs and single-label hosts match only themselves.
 */
export function baseDomain(url: string): string | null {
	const host = webUrl(url)?.hostname
	if (!host) return null
	return getDomain(host, { allowPrivateDomains: true }) ?? host
}

export function matchesBaseDomain(itemUrl: string | null, tabUrl: string): boolean {
	if (!itemUrl) return false
	const tab = baseDomain(tabUrl)
	return tab !== null && baseDomain(itemUrl) === tab
}

/** Only http(s) tabs get suggestions. */
export function suggestionIds(rows: Array<{ id: string; url: string | null }>, tabUrl: string | undefined): string[] {
	const tab = tabUrl ? baseDomain(tabUrl) : null
	if (tab === null) return []
	return rows.filter((row) => row.url !== null && baseDomain(row.url) === tab).map((row) => row.id)
}
