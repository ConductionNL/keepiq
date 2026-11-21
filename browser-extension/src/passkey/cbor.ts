/** The CBOR (RFC 8949) shapes WebAuthn needs, in CTAP2 canonical form: shortest heads, map keys sorted. */
import { concat } from './bytes'

export type CborValue = number | string | Uint8Array | Map<number | string, CborValue>

const UNSIGNED = 0
const NEGATIVE = 1
const BYTES = 2
const TEXT = 3
const MAP = 5

function head(major: number, value: number): number[] {
	if (!Number.isSafeInteger(value) || value < 0 || value > 0xffffffff) throw new RangeError(`CBOR value out of range: ${value}`)
	const type = major << 5
	if (value < 24) return [type | value]
	if (value < 0x100) return [type | 24, value]
	if (value < 0x10000) return [type | 25, value >> 8, value & 0xff]
	return [type | 26, value >>> 24, (value >> 16) & 0xff, (value >> 8) & 0xff, value & 0xff]
}

/** CTAP2 order: shorter encoded key first, then bytewise. */
function compareKeys(a: Uint8Array, b: Uint8Array): number {
	if (a.length !== b.length) return a.length - b.length
	const at = a.findIndex((byte, i) => byte !== b[i])
	return at === -1 ? 0 : a[at]! - b[at]!
}

export function encodeCbor(value: CborValue): Uint8Array {
	if (typeof value === 'number') return new Uint8Array(value < 0 ? head(NEGATIVE, -1 - value) : head(UNSIGNED, value))
	if (typeof value === 'string') {
		const bytes = new TextEncoder().encode(value)
		return concat(head(TEXT, bytes.length), bytes)
	}
	if (value instanceof Uint8Array) return concat(head(BYTES, value.length), value)
	const entries = [...value].map(([key, entry]) => [encodeCbor(key), encodeCbor(entry)] as const).sort(([a], [b]) => compareKeys(a, b))
	return concat(head(MAP, entries.length), ...entries.flat())
}
