/**
 * A port of the web app's `src/passkey/passkey.js` and `loginPasskeys.js`: the canonical credential of Keepiq's
 * `passkey-item-type` and a login's `passkeys` field, a JSON array of them. Their JSON is the stored format, so
 * the field order below is part of it.
 */

export interface PasskeyCredential {
	credentialId: string
	rpId: string
	rpName: string
	userName: string
	userDisplayName: string
	userHandle: string
	privateKey: string
	algorithm: number
	counter: number
	transports: string[]
	createdAt: string
}

const REQUIRED = ['credentialId', 'rpId', 'privateKey'] as const

const text = (value: unknown) => (value != null ? String(value) : '')
const filled = (value: unknown): value is string => typeof value === 'string' && value !== ''
const integer = (value: unknown, fallback: number) => (Number.isInteger(value) ? (value as number) : fallback)

/** Defaults only the Keepiq extension fields, never credential material; null when a required field is missing. */
export function buildPasskeyCredential(input: unknown): PasskeyCredential | null {
	if (input == null || typeof input !== 'object') return null
	const raw = input as Record<string, unknown>
	for (const field of REQUIRED) {
		if (typeof raw[field] !== 'string' || raw[field] === '') return null
	}
	return {
		credentialId: String(raw.credentialId),
		rpId: String(raw.rpId),
		rpName: text(raw.rpName),
		userName: text(raw.userName),
		userDisplayName: text(raw.userDisplayName),
		userHandle: text(raw.userHandle),
		privateKey: String(raw.privateKey),
		algorithm: integer(raw.algorithm, -7),
		counter: integer(raw.counter, 0),
		transports: Array.isArray(raw.transports) ? raw.transports.map(String) : [],
		createdAt: filled(raw.createdAt) ? raw.createdAt : new Date().toISOString(),
	}
}

export function serializePasskey(input: unknown): string | null {
	const canonical = buildPasskeyCredential(input)
	return canonical === null ? null : JSON.stringify(canonical)
}

export function parsePasskey(json: unknown): PasskeyCredential | null {
	if (typeof json !== 'string' || json.trim() === '') return null
	try {
		return buildPasskeyCredential(JSON.parse(json))
	} catch {
		return null
	}
}

export function storedArray(json: unknown): unknown[] | null {
	if (typeof json !== 'string' || json.trim() === '') return null
	try {
		const parsed: unknown = JSON.parse(json)
		return Array.isArray(parsed) ? parsed : null
	} catch {
		return null
	}
}

const isEmpty = (json: string | null | undefined) => json == null || json.trim() === ''

const isObject = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value)

/** The object entries of a decrypted `passkeys` value with their stored position; others are skipped here but kept in storage. */
export function passkeyEntries(json: unknown): { index: number; credential: Record<string, unknown> }[] {
	return (storedArray(json) ?? []).flatMap((credential, index) => (isObject(credential) ? [{ index, credential }] : []))
}

/** Every other entry kept as stored; '' when nothing remains, and a value that is not an array unchanged. */
export function withoutPasskey(json: string, index: number): string {
	const stored = storedArray(json)
	if (stored === null) return json
	const remaining = stored.filter((_entry, i) => i !== index)
	return remaining.length > 0 ? JSON.stringify(remaining) : ''
}

/**
 * The value with `credential` appended, or put in the place of the entry at `replace`. Null when the stored value
 * is not an array or `replace` names no entry, since saving would lose what is there.
 */
export function withPasskey(json: string | null | undefined, credential: PasskeyCredential, replace?: number): string | null {
	const stored = isEmpty(json) ? [] : storedArray(json)
	if (stored === null) return null
	const canonical = buildPasskeyCredential(credential)
	if (canonical === null) return null
	if (replace === undefined) return JSON.stringify([...stored, canonical])
	if (!Number.isInteger(replace) || replace < 0 || replace >= stored.length) return null
	return JSON.stringify(stored.map((entry, i) => (i === replace ? canonical : entry)))
}

/** The entry at `index` with only its `counter` changed, so fields a newer client added survive. */
export function withCounter(json: string, index: number, counter: number): string | null {
	const stored = storedArray(json)
	const entry = stored?.[index]
	if (!stored || !isObject(entry) || !Number.isInteger(counter) || counter < 0) return null
	return JSON.stringify(stored.map((other, i) => (i === index ? { ...entry, counter } : other)))
}
