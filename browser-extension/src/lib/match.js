/**
 * Origin / registrable-domain matching for autofill candidate selection.
 *
 * Matching runs over the UNENCRYPTED `url`/`name` index fields only (ADR-003
 * §"URL matching on the unencrypted index fields") — never over decrypted
 * values. A locked extension can therefore still list candidate names/URLs
 * without decrypting anything.
 *
 * v1 uses a conservative registrable-domain (eTLD+1 approximation) match plus a
 * substring fallback on the human `name`, always requiring explicit user
 * selection before any fill (anti-phishing).
 */

// A small multi-label public-suffix set covering the common cases. This is an
// approximation, not the full PSL — a wrong guess only widens/narrows the
// candidate list the user must still pick from; it never causes a silent fill.
const MULTI_LABEL_SUFFIXES = new Set([
	'co.uk',
	'org.uk',
	'gov.uk',
	'ac.uk',
	'co.jp',
	'or.jp',
	'ne.jp',
	'com.au',
	'net.au',
	'org.au',
	'com.br',
	'co.nz',
	'co.za',
	'com.mx',
	'co.in',
	'gov.nl', // Dutch government (the primary audience)
])

/**
 * Extract the hostname from a URL or bare host string; '' if unparseable.
 * @param input
 */
export function hostOf(input) {
	if (!input) return ''
	const value = String(input).trim()
	try {
		// Accept bare hosts by giving the URL parser a scheme.
		const withScheme = /^[a-z]+:\/\//i.test(value) ? value : 'https://' + value
		return new URL(withScheme).hostname.toLowerCase()
	} catch {
		return ''
	}
}

/**
 * The registrable domain (eTLD+1 approximation) of a hostname.
 * @param host
 */
export function registrableDomain(host) {
	const h = hostOf(host)
	if (!h || h.indexOf('.') === -1) return h
	const parts = h.split('.')
	const lastTwo = parts.slice(-2).join('.')
	const lastThree = parts.slice(-3).join('.')
	if (parts.length >= 3 && MULTI_LABEL_SUFFIXES.has(lastTwo)) {
		return lastThree
	}
	return lastTwo
}

/**
 * Score how well a stored secret (with plaintext `url`/`name`) matches a target
 * origin. Higher is better; 0 means no match.
 *
 * @param {{ url?: string, name?: string }} secret
 * @param {string} targetHost The active tab hostname (or URL)
 * @return {number}
 */
export function matchScore(secret, targetHost) {
	const target = hostOf(targetHost)
	if (!target) return 0
	const targetReg = registrableDomain(target)

	const secretHost = hostOf(secret.url)
	if (secretHost) {
		if (secretHost === target) return 100 // exact host
		if (registrableDomain(secretHost) === targetReg && targetReg) return 80 // same site
	}

	// Fallback: the human name mentions the registrable domain or its label.
	const name = (secret.name || '').toLowerCase()
	if (targetReg && name.includes(targetReg)) return 40
	const label = targetReg.split('.')[0]
	if (label && label.length >= 3 && name.includes(label)) return 20

	return 0
}

/**
 * Filter + rank secrets for a target origin. Only secrets with a positive score
 * are returned, best first. The caller still requires explicit user selection.
 *
 * @param {Array<{ url?: string, name?: string }>} secrets
 * @param {string} targetHost
 * @return {Array<object>} matching secrets, best-first, each with `_score`
 */
export function matchSecrets(secrets, targetHost) {
	return (secrets || [])
		.map((s) => ({ ...s, _score: matchScore(s, targetHost) }))
		.filter((s) => s._score > 0)
		.sort((a, b) => b._score - a._score)
}
