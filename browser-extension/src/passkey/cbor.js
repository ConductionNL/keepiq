/**
 * Minimal CBOR encoder — just the subset WebAuthn needs (unsigned/negative
 * ints, byte/text strings, arrays, maps). Used to build the attestation object
 * and the COSE public key (extension-passkey-provider §2.2). No decoder is
 * needed: the extension only produces these structures.
 */

function encodeType(major, value) {
	if (value < 24) return Uint8Array.of((major << 5) | value)
	if (value < 0x100) return Uint8Array.of((major << 5) | 24, value)
	if (value < 0x10000) return Uint8Array.of((major << 5) | 25, value >> 8, value & 0xff)
	return Uint8Array.of(
		(major << 5) | 26,
		(value >>> 24) & 0xff, (value >>> 16) & 0xff, (value >>> 8) & 0xff, value & 0xff,
	)
}

function concat(chunks) {
	let len = 0
	for (const c of chunks) len += c.length
	const out = new Uint8Array(len)
	let off = 0
	for (const c of chunks) {
		out.set(c, off)
		off += c.length
	}
	return out
}

/**
 * Encode a JS value to CBOR. Supported: number (int), bigint-free; Uint8Array
 * (byte string); string (text); Array; Map (preserves key order); plain object
 * is NOT supported — use a Map so key order + integer keys are explicit.
 *
 * @param {*} value
 * @return {Uint8Array}
 */
export function cborEncode(value) {
	if (typeof value === 'number' && Number.isInteger(value)) {
		return value >= 0 ? encodeType(0, value) : encodeType(1, -value - 1)
	}
	if (value instanceof Uint8Array) {
		return concat([encodeType(2, value.length), value])
	}
	if (typeof value === 'string') {
		const bytes = new TextEncoder().encode(value)
		return concat([encodeType(3, bytes.length), bytes])
	}
	if (Array.isArray(value)) {
		return concat([encodeType(4, value.length), ...value.map(cborEncode)])
	}
	if (value instanceof Map) {
		const parts = [encodeType(5, value.size)]
		for (const [k, v] of value.entries()) {
			parts.push(cborEncode(k), cborEncode(v))
		}
		return concat(parts)
	}
	throw new Error('cbor: unsupported value ' + typeof value)
}

/**
 * Convert a WebCrypto raw ECDSA signature (r‖s, 64 bytes for P-256) to the
 * ASN.1 DER encoding WebAuthn ES256 assertions require.
 *
 * @param {Uint8Array} raw 64-byte r‖s
 * @return {Uint8Array} DER SEQUENCE(INTEGER r, INTEGER s)
 */
export function rawEcdsaToDer(raw) {
	const half = raw.length / 2
	const derInt = (bytes) => {
		let i = 0
		while (i < bytes.length - 1 && bytes[i] === 0) i++
		let v = bytes.slice(i)
		if (v[0] & 0x80) {
			const padded = new Uint8Array(v.length + 1)
			padded.set(v, 1)
			v = padded
		}
		return concat([Uint8Array.of(0x02, v.length), v])
	}
	const r = derInt(raw.slice(0, half))
	const s = derInt(raw.slice(half))
	const body = concat([r, s])
	return concat([Uint8Array.of(0x30, body.length), body])
}
