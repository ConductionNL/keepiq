import { baseDomain } from '@/src/vault/match'

export type DomainResult = { ok: true; domain: string } | { ok: false; error: 'empty' | 'unreadable' | 'duplicate' }

/** A host or pasted URL as the base domain ext-autofill excludes by; `baseDomain` gives a bare host its `https://`. */
export function excludedDomain(list: readonly string[], input: string): DomainResult {
	const text = input.trim().toLowerCase()
	if (!text) return { ok: false, error: 'empty' }
	const domain = baseDomain(text)
	if (!domain) return { ok: false, error: 'unreadable' }
	return list.includes(domain) ? { ok: false, error: 'duplicate' } : { ok: true, domain }
}
