import type { UsernameOptions } from './options'
import { randomInt, type Generated, type RandomInt } from './random'

const TAG_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789'
const TAG_LENGTH = 8

export type UsernameFailure = 'needs_email' | 'needs_domain' | 'no_website'

export type UsernameRequest = Partial<UsernameOptions> & { website?: string | null }

function label({ mode = 'random', website }: UsernameRequest, rand: RandomInt): string | null {
	if (mode === 'website') {
		const host = String(website ?? '').trim().toLowerCase()
		return host === '' ? null : host
	}
	let tag = ''
	for (let i = 0; i < TAG_LENGTH; i++) tag += TAG_CHARS[rand(0, TAG_CHARS.length - 1)]
	return tag
}

/** One word from the full list (hyphenated words are no problem here), capitalised and with four digits by default. */
export function randomWordUsername({ capitalize = true, includeNumber = true }: UsernameRequest, list: readonly string[], rand: RandomInt = randomInt): string {
	let word = list[rand(0, list.length - 1)]!
	if (capitalize) word = word[0]!.toUpperCase() + word.slice(1)
	return includeNumber ? word + String(rand(0, 9999)).padStart(4, '0') : word
}

/** `user+label@example.com`. */
export function plusAddressedEmail(email: string, request: UsernameRequest, rand: RandomInt = randomInt): Generated<UsernameFailure> {
	const at = email.lastIndexOf('@')
	if (at <= 0 || at === email.length - 1) return { ok: false, failure: 'needs_email' }
	const tag = label(request, rand)
	return tag === null ? { ok: false, failure: 'no_website' } : { ok: true, value: `${email.slice(0, at)}+${tag}${email.slice(at)}` }
}

/** `label@catch-all-domain`. */
export function catchAllEmail(domain: string, request: UsernameRequest, rand: RandomInt = randomInt): Generated<UsernameFailure> {
	const clean = domain.trim().replace(/^@/, '')
	if (clean === '' || !clean.includes('.')) return { ok: false, failure: 'needs_domain' }
	const tag = label(request, rand)
	return tag === null ? { ok: false, failure: 'no_website' } : { ok: true, value: `${tag}@${clean}` }
}

export function generateUsername(request: UsernameRequest, list: readonly string[], rand: RandomInt = randomInt): Generated<UsernameFailure> {
	if (request.type === 'plus') return plusAddressedEmail(request.email ?? '', request, rand)
	if (request.type === 'catchall') return catchAllEmail(request.domain ?? '', request, rand)
	return { ok: true, value: randomWordUsername(request, list, rand) }
}
