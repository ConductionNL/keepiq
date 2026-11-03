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
