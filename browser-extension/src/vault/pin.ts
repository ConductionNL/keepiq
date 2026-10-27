/**
 * A PIN's two copies (ADR-002): the private key wrapped under the PIN, in session storage
 * only, and the seal in `storage.local`, which a master-password unlock after a restart
 * opens to rebuild the first. Unlock's attempt rules are in `unlock.ts`.
 */
import { decodeEnvelope, deriveUnlockKey, encodeEnvelope, fromBase64, toBase64 } from '@/src/crypto'
import { IV_LENGTH, SALT_LENGTH } from '@/src/crypto/envelope'
import { sessionValues } from './key-store'

export const pinKey = (accountId: string) => `pin.${accountId}`

export interface PinRecord {
	/** The PKCS#8 bytes under a key derived from the PIN. */
	envelope: string
	/** The suite the key came from, so a PIN never outlives a changed vault key. */
	suiteId: string
	unlockKeyEpoch: number
	/** Wrong PINs in a row, counted before each unwrap. */
	failures: number
}

export class WrongPin extends Error {}

export async function wrapPin(pin: string, pkcs8: Uint8Array<ArrayBuffer>, suite: { id: string; unlockKeyEpoch: number }): Promise<PinRecord> {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const key = await deriveUnlockKey(pin, salt)
	const ciphertextWithTag = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, pkcs8))
	return { envelope: encodeEnvelope({ salt, iv, ciphertextWithTag }), suiteId: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch, failures: 0 }
}

/** Throws `WrongPin` when the GCM tag does not verify. */
export async function unwrapPin(record: PinRecord, pin: string): Promise<Uint8Array<ArrayBuffer>> {
	const { salt, iv, ciphertextWithTag } = decodeEnvelope(record.envelope)
	const key = await deriveUnlockKey(pin, salt)
	try {
		return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertextWithTag))
	} catch {
		throw new WrongPin()
	}
}

/** The PIN in `storage.local`, so it survives a restart; only a key derived from the private key opens it (ADR-002). */
export interface PinSeal {
	v: 1
	salt: string
	iv: string
	ciphertext: string
	suiteId: string
	unlockKeyEpoch: number
}

export const pinSealKey = (accountId: string) => `pinSeal.${accountId}`
const SEAL_INFO = new TextEncoder().encode('keepiq pin seal v1')

type SuiteRef = { id: string; unlockKeyEpoch: number }

/** HKDF over the private key, never stored: whoever can write storage but not open the key cannot make a seal that opens. */
async function sealKey(pkcs8: Uint8Array<ArrayBuffer>, salt: Uint8Array<ArrayBuffer>): Promise<CryptoKey> {
	const material = await crypto.subtle.importKey('raw', pkcs8, 'HKDF', false, ['deriveKey'])
	return crypto.subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt, info: SEAL_INFO }, material, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
}

const sealAad = (accountId: string, suite: SuiteRef) => new TextEncoder().encode(JSON.stringify([1, accountId, suite.id, suite.unlockKeyEpoch]))

/** Padded to whole blocks behind a 2-byte length, so the ciphertext does not tell a PIN's length; every PIN up to 62 UTF-8 bytes fills one. */
const PAD_BLOCK = 64

function pad(pin: string): Uint8Array<ArrayBuffer> {
	const text = new TextEncoder().encode(pin)
	const padded = new Uint8Array(Math.ceil((text.length + 2) / PAD_BLOCK) * PAD_BLOCK)
	new DataView(padded.buffer).setUint16(0, text.length)
	padded.set(text, 2)
	return padded
}

/** Throws on a length that does not fit or text that is not UTF-8. */
function unpad(padded: Uint8Array<ArrayBuffer>): string {
	const length = new DataView(padded.buffer).getUint16(0)
	if (padded.length % PAD_BLOCK !== 0 || length > padded.length - 2) throw new Error('Bad padding')
	return new TextDecoder('utf-8', { fatal: true }).decode(padded.subarray(2, 2 + length))
}

export async function sealPin(accountId: string, pin: string, pkcs8: Uint8Array<ArrayBuffer>, suite: SuiteRef): Promise<PinSeal> {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = await crypto.subtle.encrypt(
		{ name: 'AES-GCM', iv, additionalData: sealAad(accountId, suite) }, await sealKey(pkcs8, salt), pad(pin),
	)
	return { v: 1, salt: toBase64(salt), iv: toBase64(iv), ciphertext: toBase64(new Uint8Array(ciphertext)), suiteId: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch }
}

/** The PIN, or `null` for a seal that does not open with this key and AAD, or names another suite or epoch than `suite`. */
export async function openSeal(accountId: string, seal: unknown, pkcs8: Uint8Array<ArrayBuffer>, suite: SuiteRef): Promise<string | null> {
	const s = seal as Partial<PinSeal> | null
	if (typeof s !== 'object' || s === null || s.v !== 1 || s.suiteId !== suite.id || s.unlockKeyEpoch !== suite.unlockKeyEpoch) return null
	try {
		const key = await sealKey(pkcs8, fromBase64(s.salt!))
		const pin = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: fromBase64(s.iv!), additionalData: sealAad(accountId, suite) }, key, fromBase64(s.ciphertext!))
		return unpad(new Uint8Array(pin))
	} catch {
		return null
	}
}

function isRecord(value: unknown): value is PinRecord {
	const r = value as Partial<PinRecord> | null
	return typeof r === 'object' && r !== null && typeof r.envelope === 'string' && typeof r.suiteId === 'string'
		&& Number.isInteger(r.unlockKeyEpoch) && Number.isInteger(r.failures) && r.failures! >= 0
}

/** A damaged record reads as no PIN. */
export async function readPin(accountId: string): Promise<PinRecord | null> {
	const stored = await sessionValues.get(pinKey(accountId))
	if (stored === undefined) return null
	try {
		const record: unknown = JSON.parse(stored)
		return isRecord(record) ? record : null
	} catch {
		return null
	}
}

export async function hasPin(accountId: string): Promise<boolean> {
	return (await readPin(accountId)) !== null
}

const generations = new Map<string, number>()

/** Changes on every `forgetPin`, so a write that started before one can tell it must not land. */
export function pinGeneration(accountId: string): number {
	return generations.get(accountId) ?? 0
}

/** Writes nothing, and returns false, when the PIN was forgotten since `generation` was read. */
export async function storePin(accountId: string, record: PinRecord, generation: number): Promise<boolean> {
	if (pinGeneration(accountId) !== generation) return false
	// Issued in the same tick as the check, so a later forget's remove lands after it.
	await sessionValues.set({ [pinKey(accountId)]: JSON.stringify(record) })
	return true
}

/** Both copies or neither, under the same guard as `storePin`. */
export async function storePinCopies(accountId: string, record: PinRecord, seal: PinSeal, generation: number): Promise<boolean> {
	if (pinGeneration(accountId) !== generation) return false
	await Promise.all([sessionValues.set({ [pinKey(accountId)]: JSON.stringify(record) }), browser.storage.local.set({ [pinSealKey(accountId)]: seal })])
	return true
}

/** As stored, unchecked; only `openSeal` vouches for it. */
export async function readSeal(accountId: string): Promise<unknown> {
	return (await browser.storage.local.get(pinSealKey(accountId)))[pinSealKey(accountId)]
}

export async function hasSeal(accountId: string): Promise<boolean> {
	return (await readSeal(accountId)) !== undefined
}

export async function deleteSeal(accountId: string): Promise<void> {
	await browser.storage.local.remove(pinSealKey(accountId))
}

/** Both copies; their removals start in one tick, so no write guarded by the old generation lands between them. */
export async function forgetPin(accountId: string): Promise<void> {
	generations.set(accountId, pinGeneration(accountId) + 1)
	await Promise.all([sessionValues.remove([pinKey(accountId)]), deleteSeal(accountId)])
}
