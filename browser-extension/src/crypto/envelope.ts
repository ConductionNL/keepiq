import { fromBase64, toBase64 } from './base64'

export const ENVELOPE_VERSION = 1
export const SALT_LENGTH = 16
export const IV_LENGTH = 12
const TAG_LENGTH = 16
const HEADER_LENGTH = 4 + SALT_LENGTH + IV_LENGTH

export interface Envelope {
	salt: Uint8Array<ArrayBuffer>
	iv: Uint8Array<ArrayBuffer>
	ciphertextWithTag: Uint8Array<ArrayBuffer>
}

/** `[uint32 BE version][salt][iv][ciphertext || tag]`, base64 (ADR-003). */
export function decodeEnvelope(base64: string): Envelope {
	const raw = fromBase64(base64)
	if (raw.byteLength < HEADER_LENGTH + TAG_LENGTH) throw new Error('Envelope too short')
	const version = new DataView(raw.buffer).getUint32(0, false)
	if (version !== ENVELOPE_VERSION) throw new Error(`Unsupported envelope version: ${version}`)
	return {
		salt: raw.slice(4, 4 + SALT_LENGTH),
		iv: raw.slice(4 + SALT_LENGTH, HEADER_LENGTH),
		ciphertextWithTag: raw.slice(HEADER_LENGTH),
	}
}

export function encodeEnvelope({ salt, iv, ciphertextWithTag }: Envelope): string {
	const bytes = new Uint8Array(4 + salt.byteLength + iv.byteLength + ciphertextWithTag.byteLength)
	new DataView(bytes.buffer).setUint32(0, ENVELOPE_VERSION, false)
	bytes.set(salt, 4)
	bytes.set(iv, 4 + salt.byteLength)
	bytes.set(ciphertextWithTag, 4 + salt.byteLength + iv.byteLength)
	return toBase64(bytes)
}
