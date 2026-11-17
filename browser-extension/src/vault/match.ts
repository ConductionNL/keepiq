import { getDomain } from 'tldts'
import { registrableDomainOf } from '../../../src/shared/registrableDomain.js'
import { webUrl } from './url'

/**
 * The registrable domain by the public suffix list, so `login.example.co.uk` gives
 * `example.co.uk`. Private suffixes count too, so one `github.io` site never matches another.
 * IPs and single-label hosts match only themselves. The rule is shared with the web app's
 * password health report (src/shared/registrableDomain.js).
 */
export function baseDomain(url: string): string | null {
	return registrableDomainOf(webUrl(url)?.hostname, getDomain)
}
