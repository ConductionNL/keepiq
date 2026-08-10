/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Minimal client-side X.509 parser (certificate-lifecycle §5.2).
 *
 * Stored certificate secrets are ciphertext to the server (ADR-003) —
 * only the owner's browser, after unlocking, can read the PEM. This
 * module extracts the NON-SECRET display fields (subject, issuer,
 * serial, validity, fingerprint) from a decrypted PEM entirely
 * client-side, so they can be submitted as inventory metadata. It never
 * touches private-key material and parses only the certificate
 * structure it needs — no external ASN.1 library.
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-metadata-parsing-split-by-pem-readability
 */

/** Common DN attribute OIDs → short labels. */
const DN_OIDS = {
	'2.5.4.3': 'CN',
	'2.5.4.6': 'C',
	'2.5.4.7': 'L',
	'2.5.4.8': 'ST',
	'2.5.4.10': 'O',
	'2.5.4.11': 'OU',
	'1.2.840.113549.1.9.1': 'emailAddress',
}

/**
 * Decode a PEM certificate body to DER bytes.
 *
 * @param {string} pem The PEM text.
 * @return {Uint8Array|null} DER bytes, or null when no certificate block exists.
 */
function pemToDer(pem) {
	const match = /-----BEGIN CERTIFICATE-----([\s\S]+?)-----END CERTIFICATE-----/.exec(pem || '')
	if (!match) {
		return null
	}
	try {
		const b64 = match[1].replace(/[^A-Za-z0-9+/=]/g, '')
		const raw = atob(b64)
		const bytes = new Uint8Array(raw.length)
		for (let i = 0; i < raw.length; i++) {
			bytes[i] = raw.charCodeAt(i)
		}
		return bytes
	} catch (e) {
		return null
	}
}

/**
 * Read one DER TLV at an offset.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {number} offset Start offset.
 * @return {{tag: number, start: number, end: number, headerEnd: number}} TLV bounds
 *   (start/end delimit the VALUE bytes).
 */
function readTlv(bytes, offset) {
	const tag = bytes[offset]
	let cursor = offset + 1
	let length = bytes[cursor]
	cursor += 1
	if (length & 0x80) {
		const lengthBytes = length & 0x7f
		length = 0
		for (let i = 0; i < lengthBytes; i++) {
			length = (length * 256) + bytes[cursor]
			cursor += 1
		}
	}
	return { tag, start: cursor, end: cursor + length, headerEnd: cursor }
}

/**
 * List the child TLVs of a constructed TLV.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} tlv The parent TLV.
 * @return {Array<{tag: number, start: number, end: number}>} Children in order.
 */
function children(bytes, tlv) {
	const out = []
	let cursor = tlv.start
	while (cursor < tlv.end) {
		const child = readTlv(bytes, cursor)
		out.push(child)
		cursor = child.end
	}
	return out
}

/**
 * Decode an OID value to dotted notation.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} tlv The OID TLV.
 * @return {string} Dotted OID.
 */
function decodeOid(bytes, tlv) {
	const parts = []
	let value = 0
	for (let i = tlv.start; i < tlv.end; i++) {
		value = (value * 128) + (bytes[i] & 0x7f)
		if ((bytes[i] & 0x80) === 0) {
			if (parts.length === 0) {
				parts.push(Math.floor(value / 40), value % 40)
			} else {
				parts.push(value)
			}
			value = 0
		}
	}
	return parts.join('.')
}

/**
 * Decode a printable/UTF8 string TLV.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} tlv The string TLV.
 * @return {string} The decoded text.
 */
function decodeString(bytes, tlv) {
	return new TextDecoder().decode(bytes.subarray(tlv.start, tlv.end))
}

/**
 * Render an X.501 Name (RDNSequence) as a readable DN string.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} nameTlv The Name TLV.
 * @return {string} e.g. "CN=example.org, O=Example".
 */
function decodeName(bytes, nameTlv) {
	const parts = []
	for (const rdnSet of children(bytes, nameTlv)) {
		for (const atv of children(bytes, rdnSet)) {
			const [oidTlv, valueTlv] = children(bytes, atv)
			if (!oidTlv || !valueTlv) {
				continue
			}
			const oid = decodeOid(bytes, oidTlv)
			parts.push(`${DN_OIDS[oid] || oid}=${decodeString(bytes, valueTlv)}`)
		}
	}
	return parts.join(', ')
}

/**
 * Decode an ASN.1 Time (UTCTime or GeneralizedTime) to ISO-8601.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{tag: number, start: number, end: number}} tlv The Time TLV.
 * @return {string|null} ISO timestamp, or null.
 */
function decodeTime(bytes, tlv) {
	const text = decodeString(bytes, tlv)
	let iso = null
	if (tlv.tag === 0x17 && /^\d{12}Z?$/.test(text.replace('Z', '') + '')) {
		// UTCTime YYMMDDHHMMSSZ — RFC 5280: YY < 50 → 20YY, else 19YY.
		const yy = parseInt(text.slice(0, 2), 10)
		const century = yy < 50 ? '20' : '19'
		iso = `${century}${text.slice(0, 2)}-${text.slice(2, 4)}-${text.slice(4, 6)}T${text.slice(6, 8)}:${text.slice(8, 10)}:${text.slice(10, 12)}Z`
	} else if (tlv.tag === 0x18) {
		// GeneralizedTime YYYYMMDDHHMMSSZ.
		iso = `${text.slice(0, 4)}-${text.slice(4, 6)}-${text.slice(6, 8)}T${text.slice(8, 10)}:${text.slice(10, 12)}:${text.slice(12, 14)}Z`
	}
	if (iso === null || isNaN(new Date(iso).getTime())) {
		return null
	}
	return iso
}

/**
 * Render an INTEGER TLV as uppercase hex.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} tlv The INTEGER TLV.
 * @return {string} Hex serial without leading zero byte.
 */
function decodeSerialHex(bytes, tlv) {
	let hex = ''
	for (let i = tlv.start; i < tlv.end; i++) {
		hex += bytes[i].toString(16).padStart(2, '0')
	}
	return hex.replace(/^00/, '').toUpperCase()
}

/**
 * Parse the non-secret display fields of a PEM certificate.
 *
 * @param {string} pem The decrypted PEM text (never sent anywhere by this module).
 * @return {Promise<object|null>} {subject, issuer, serial, fingerprintSha256,
 *   notBefore, notAfter} or null when the input is not a certificate.
 */
export async function parseCertificatePem(pem) {
	const der = pemToDer(pem)
	if (!der) {
		return null
	}
	try {
		// Certificate ::= SEQUENCE { tbsCertificate, signatureAlgorithm, signature }
		const certificate = readTlv(der, 0)
		const [tbs] = children(der, certificate)
		const tbsChildren = children(der, tbs)
		let index = 0
		// [0] EXPLICIT version — optional.
		if (tbsChildren[0] && tbsChildren[0].tag === 0xa0) {
			index = 1
		}
		const serialTlv = tbsChildren[index]
		const issuerTlv = tbsChildren[index + 2]
		const validityTlv = tbsChildren[index + 3]
		const subjectTlv = tbsChildren[index + 4]
		const [notBeforeTlv, notAfterTlv] = children(der, validityTlv)

		const digest = await crypto.subtle.digest('SHA-256', der)
		const fingerprint = Array.from(new Uint8Array(digest))
			.map((b) => b.toString(16).padStart(2, '0'))
			.join('')

		return {
			subject: decodeName(der, subjectTlv),
			issuer: decodeName(der, issuerTlv),
			serial: decodeSerialHex(der, serialTlv),
			fingerprintSha256: `sha256:${fingerprint}`,
			notBefore: decodeTime(der, notBeforeTlv),
			notAfter: decodeTime(der, notAfterTlv),
		}
	} catch (e) {
		return null
	}
}
