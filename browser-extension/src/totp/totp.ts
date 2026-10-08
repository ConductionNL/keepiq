/**
 * RFC 6238 codes from a `totp` item's decrypted `key`, ported from the web app's
 * `src/totp/totp.js` so a seed that works in Keepiq works here. Runs in the popup;
 * the seed and HMAC key never leave the caller's memory.
 */

export type TotpAlgorithm = 'SHA1' | 'SHA256' | 'SHA512'

export interface TotpParams {
	secret: string
	algorithm: TotpAlgorithm
	digits: 6 | 8
	period: number
	issuer: string | null
	account: string | null
}

const HASHES: Record<TotpAlgorithm, string> = { SHA1: 'SHA-1', SHA256: 'SHA-256', SHA512: 'SHA-512' }
const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

export class InvalidTotpSeed extends Error {}

/** RFC 4648 base32; padding and whitespace ignored, case-insensitive. */
export function base32Decode(input: string): Uint8Array<ArrayBuffer> {
	const clean = input.toUpperCase().replace(/=+$/, '').replace(/\s+/g, '')
	if (clean === '') throw new InvalidTotpSeed('Empty base32 secret')
	let bits = 0
	let value = 0
	const out: number[] = []
	for (const char of clean) {
		const index = BASE32.indexOf(char)
		if (index === -1) throw new InvalidTotpSeed('Invalid base32 character')
		value = ((value << 5) | index) >>> 0
		bits += 5
		if (bits >= 8) {
			bits -= 8
			out.push((value >>> bits) & 0xff)
		}
	}
	if (out.length === 0) throw new InvalidTotpSeed('Base32 secret too short')
	return new Uint8Array(out)
}

function algorithmOf(raw: string | null): TotpAlgorithm {
	if (!raw) return 'SHA1'
	const token = raw.toUpperCase()
	if (!(token in HASHES)) throw new InvalidTotpSeed(`Unsupported TOTP algorithm: ${token}`)
	return token as TotpAlgorithm
}

function positiveInt(raw: string | null): number | null {
	const n = Number.parseInt(raw ?? '', 10)
	return Number.isFinite(n) && n > 0 ? n : null
}

/** An `otpauth://totp/...` URI or a bare base32 secret; HOTP and anything else throw `InvalidTotpSeed`. */
export function parseTotpSeed(raw: string): TotpParams {
	const value = raw.trim()
	if (value === '') throw new InvalidTotpSeed('Empty TOTP seed')
	if (!/^otpauth:\/\//i.test(value)) {
		base32Decode(value)
		return { secret: value.replace(/\s+/g, '').toUpperCase(), algorithm: 'SHA1', digits: 6, period: 30, issuer: null, account: null }
	}
	let url: URL
	try {
		url = new URL(value)
	} catch {
		throw new InvalidTotpSeed('Malformed otpauth URI')
	}
	if (url.host.toLowerCase() !== 'totp') throw new InvalidTotpSeed('Not an otpauth://totp URI')
	const secret = url.searchParams.get('secret')
	if (!secret) throw new InvalidTotpSeed('otpauth URI has no secret')
	base32Decode(secret)

	let label: string
	try {
		label = decodeURIComponent(url.pathname.replace(/^\//, ''))
	} catch {
		label = url.pathname.replace(/^\//, '')
	}
	let account: string | null = label || null
	let issuer = url.searchParams.get('issuer')
	if (label.includes(':')) {
		const [labelIssuer = '', ...rest] = label.split(':')
		issuer ||= labelIssuer.trim() || null
		account = rest.join(':').trim() || null
	}
	return {
		secret: secret.replace(/\s+/g, '').toUpperCase(),
		algorithm: algorithmOf(url.searchParams.get('algorithm')),
		digits: positiveInt(url.searchParams.get('digits')) === 8 ? 8 : 6,
		period: positiveInt(url.searchParams.get('period')) ?? 30,
		issuer: issuer || null,
		account,
	}
}

export async function generateCode(params: Pick<TotpParams, 'secret' | 'algorithm' | 'digits' | 'period'>, now = Date.now()): Promise<string> {
	const counter = Math.floor(now / 1000 / params.period)
	const counterBytes = new Uint8Array(8)
	let rest = counter
	for (let i = 7; i >= 0; i--) {
		counterBytes[i] = rest & 0xff
		rest = Math.floor(rest / 256)
	}
	const key = await crypto.subtle.importKey('raw', base32Decode(params.secret), { name: 'HMAC', hash: HASHES[params.algorithm] }, false, ['sign'])
	const hmac = new Uint8Array(await crypto.subtle.sign('HMAC', key, counterBytes))
	// RFC 4226 dynamic truncation.
	const offset = hmac[hmac.length - 1]! & 0x0f
	const binary = ((hmac[offset]! & 0x7f) << 24) | (hmac[offset + 1]! << 16) | (hmac[offset + 2]! << 8) | hmac[offset + 3]!
	return String(binary % 10 ** params.digits).padStart(params.digits, '0')
}

export function secondsRemaining(period: number, now = Date.now()): number {
	return period - (Math.floor(now / 1000) % period)
}
