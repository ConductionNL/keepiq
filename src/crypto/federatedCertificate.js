/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Verifies a federated recipient's certificate in the owner's browser
 * before anything is encrypted for it (sharing-federated-recipients D3,
 * task 2.3). The owner's server fetched the certificate and its CA chain
 * from the partner. The partner and the network are not trusted for this
 * check; the owner's server is, as it supplies the pinned root and serves
 * this code:
 *
 * - the chain is the partner's intermediate, then its root, and the root
 *   must be the one whose SHA-256 fingerprint the administrator pinned; a
 *   self-issued certificate in the intermediate's place is refused,
 * - every certificate must be signed by the next one (issuer name and
 *   signature, PKCS#1 v1.5 or RSASSA-PSS with SHA-256), and each one above
 *   the recipient's must be a CA allowed to sign certificates, while the
 *   recipient's own must not be marked CA,
 * - the recipient certificate's common name must be the cloud id the owner
 *   typed, and every certificate must be valid now.
 *
 * Only the structure needed for those checks is parsed; no ASN.1 library.
 * Each certificate must be exactly one PEM block, and the caller encrypts to
 * the public key returned here, never to the certificate text again.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import { decodeOid } from './oid.js'
import { decodePem } from './pem.js'

const OID_COMMON_NAME = '2.5.4.3'
const OID_BASIC_CONSTRAINTS = '2.5.29.19'
const OID_KEY_USAGE = '2.5.29.15'
const OID_NAME_CONSTRAINTS = '2.5.29.30'
const OID_SHA256_RSA = '1.2.840.113549.1.1.11'
const OID_RSA_PSS = '1.2.840.113549.1.1.10'
const OID_MGF1 = '1.2.840.113549.1.1.8'
const OID_SHA256 = '2.16.840.1.101.3.4.2.1'
const UTF8_STRING = 0x0c
/** PrintableString, TeletexString and IA5String. */
const ASCII_STRINGS = new Set([0x13, 0x14, 0x16])

/** Strict, and a leading byte order mark is kept as text, so it names no one. */
const utf8 = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true })

/** A partner's chain: its intermediate, then its root. */
const CHAIN_LENGTH = 2

/**
 * How far a certificate's notBefore may lie ahead of the owner's clock, so a
 * freshly renewed partner intermediate passes a clock running slightly
 * behind. notAfter is strict.
 */
const CLOCK_SKEW_MS = 5 * 60 * 1000

/** Why a certificate was refused. */
export class FederatedCertificateError extends Error {
	/**
	 * @param {string} reason `malformed`, `untrusted_root`, `bad_chain`,
	 *   `name_mismatch` or `not_valid_now`.
	 */
	constructor(reason) {
		super(reason)
		this.name = 'FederatedCertificateError'
		this.reason = reason
	}
}

/**
 * @param {string} pem Exactly one PEM certificate, nothing else.
 * @return {Uint8Array} Its DER bytes.
 */
function pemToDer(pem) {
	try {
		return decodePem(pem, ['CERTIFICATE']).der
	} catch {
		throw new FederatedCertificateError('malformed')
	}
}

/**
 * The value of a long-form length. DER uses the long form only past 127, in
 * as few bytes as it takes (X.690 10.1), so anything else is malformed.
 *
 * @param {Uint8Array} octets The length bytes after the count.
 * @return {number}
 */
function longFormLength(octets) {
	const length = octets.reduce((sum, octet) => sum * 256 + octet, 0)
	if (length < 0x80 || octets[0] === 0) {
		throw new FederatedCertificateError('malformed')
	}
	return length
}

/**
 * Read one DER element.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {number} offset Where the element starts.
 * @param {number} [limit] Where its parent ends.
 * @return {{tag: number, offset: number, start: number, end: number}}
 *   `offset` is the header start, `start` to `end` the value.
 */
function tlv(bytes, offset, limit = bytes.length) {
	if (offset + 2 > limit) {
		throw new FederatedCertificateError('malformed')
	}
	const tag = bytes[offset]
	let cursor = offset + 1
	let length = bytes[cursor++]
	if (length & 0x80) {
		const count = length & 0x7f
		if (count === 0 || count > 4 || cursor + count > limit) {
			throw new FederatedCertificateError('malformed')
		}
		length = longFormLength(bytes.subarray(cursor, cursor + count))
		cursor += count
	}
	if (cursor + length > limit) {
		throw new FederatedCertificateError('malformed')
	}
	return { tag, offset, start: cursor, end: cursor + length }
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} parent A constructed element.
 * @return {Array<object>} Its children.
 */
function children(bytes, parent) {
	const out = []
	for (let cursor = parent.start; cursor < parent.end;) {
		const child = tlv(bytes, cursor, parent.end)
		out.push(child)
		cursor = child.end
	}
	return out
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}|undefined} element An OID element.
 * @return {string} Dotted OID.
 */
function oid(bytes, element) {
	if (element?.tag !== 0x06) {
		throw new FederatedCertificateError('malformed')
	}
	return decodeOid(bytes, element)
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{offset: number, end: number}} element Any element.
 * @return {Uint8Array} The whole element, header included.
 */
function whole(bytes, element) {
	return bytes.subarray(element.offset, element.end)
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} element A UTCTime or GeneralizedTime.
 * @return {number} Milliseconds since the epoch.
 */
function time(bytes, element) {
	const text = new TextDecoder().decode(bytes.subarray(element.start, element.end))
	const match = /^(\d{2}|\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/.exec(text)
	if (!match) {
		throw new FederatedCertificateError('malformed')
	}
	let year = Number(match[1])
	if (match[1].length === 2) {
		year += year < 50 ? 2000 : 1900
	}
	return Date.UTC(
		year,
		Number(match[2]) - 1,
		Number(match[3]),
		Number(match[4]),
		Number(match[5]),
		Number(match[6]),
	)
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} parent A constructed element.
 * @return {Array<object>} Its children, which must fill it exactly.
 */
function exactChildren(bytes, parent) {
	const out = children(bytes, parent)
	if ((out.length > 0 ? out[out.length - 1].end : parent.start) !== parent.end) {
		throw new FederatedCertificateError('malformed')
	}
	return out
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} parent A constructed element.
 * @param {number} [tag] The tag its one child must have, if any.
 * @return {object} That child.
 */
function onlyChild(bytes, parent, tag) {
	const [child, extra] = exactChildren(bytes, parent)
	if (!child || extra || (tag !== undefined && child.tag !== tag)) {
		throw new FederatedCertificateError('malformed')
	}
	return child
}

/**
 * A BOOLEAN DEFAULT FALSE that is present. DER encodes TRUE as 0xFF only
 * (X.690 11.1) and leaves a value at its default out (11.5), so a present
 * flag is 0xFF or malformed.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} element A BOOLEAN.
 * @return {boolean} Always true.
 */
function derPresentFlag(bytes, element) {
	if (
		element.tag !== 0x01
		|| element.end - element.start !== 1
		|| bytes[element.start] !== 0xff
	) {
		throw new FederatedCertificateError('malformed')
	}
	return true
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} element A small
 *   non-negative INTEGER, such as a pathLenConstraint or a PSS salt length.
 * @return {number}
 */
function derUint(bytes, element) {
	const length = element.end - element.start
	if (
		element.tag !== 0x02
		|| length < 1
		|| length > 4
		|| bytes[element.start] & 0x80
		|| (length > 1
			&& bytes[element.start] === 0
			&& !(bytes[element.start + 1] & 0x80))
	) {
		throw new FederatedCertificateError('malformed')
	}
	let value = 0
	for (let i = element.start; i < element.end; i++) {
		value = value * 256 + bytes[i]
	}
	return value
}

/**
 * The extensions in a TBS `[3]` block, or none without one.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object|undefined} block The TBS `[3]` extensions element, if any.
 * @return {Array<object>}
 */
function extensionsOf(bytes, block) {
	return block ? exactChildren(bytes, onlyChild(bytes, block, 0x30)) : []
}

/**
 * The children of an Extension SEQUENCE: extnID, the optional critical flag
 * and extnValue last.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} extension One extension.
 * @return {{id: object|undefined, value: object|undefined, rest: Array<object>}}
 */
function extensionFields(bytes, extension) {
	if (extension.tag !== 0x30) {
		throw new FederatedCertificateError('malformed')
	}
	const [id, ...rest] = exactChildren(bytes, extension)
	return { id, value: rest.pop(), rest }
}

/**
 * One extension decoded. Extension: extnID, critical BOOLEAN DEFAULT FALSE,
 * extnValue OCTET STRING.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} extension One extension.
 * @return {{critical: boolean, name: string, value: object}}
 */
function decodeExtension(bytes, extension) {
	const { id, value, rest } = extensionFields(bytes, extension)
	if (id?.tag !== 0x06 || value?.tag !== 0x04 || rest.length > 1) {
		throw new FederatedCertificateError('malformed')
	}
	return {
		critical: rest.length === 1 && derPresentFlag(bytes, rest[0]),
		name: oid(bytes, id),
		value,
	}
}

/**
 * The cA flag and pathLenConstraint of a basicConstraints value (RFC 5280
 * 4.2.1.9), both optional and in that order.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object} value The extnValue OCTET STRING.
 * @return {{ca: boolean, pathLen: number|null}}
 */
function basicConstraintsOf(bytes, value) {
	const fields = exactChildren(bytes, onlyChild(bytes, value, 0x30))
	let next = 0
	let ca = false
	let pathLen = null
	if (fields[next]?.tag === 0x01) {
		ca = derPresentFlag(bytes, fields[next++])
	}
	if (fields[next]?.tag === 0x02) {
		pathLen = derUint(bytes, fields[next++])
	}
	if (next !== fields.length) {
		throw new FederatedCertificateError('malformed')
	}
	return { ca, pathLen }
}

/**
 * The BIT STRING of a keyUsage value, checked: at least the unused-bits
 * count, at most 7 unused bits, and none without a byte to pad.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object} value The extnValue OCTET STRING.
 * @return {{start: number, length: number, unused: number}}
 */
function keyUsageBits(bytes, value) {
	const bits = onlyChild(bytes, value, 0x03)
	const length = bits.end - bits.start
	const unused = bytes[bits.start]
	if (length === 0 || unused > 7 || (length === 1 && unused !== 0)) {
		throw new FederatedCertificateError('malformed')
	}
	return { start: bits.start, length, unused }
}

/**
 * Whether a keyUsage value sets keyCertSign (RFC 5280 4.2.1.3). After the
 * unused-bits count, keyCertSign is bit 5 of the first byte; when that byte
 * is also the last, its unused bits are padding.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object} value The extnValue OCTET STRING.
 * @return {boolean}
 */
function keyCertSignOf(bytes, value) {
	const { start, length, unused } = keyUsageBits(bytes, value)
	const first = length > 1 ? bytes[start + 1] : 0
	const mask = length === 2 ? (0xff << unused) & 0xff : 0xff
	return (first & mask & 0x04) !== 0
}

/**
 * Read one extension into the run: each at most once, basicConstraints and
 * keyUsage read, nameConstraints refused (they are not enforced here, and RFC
 * 5280 4.2.1.10 requires them critical), any other refused when critical.
 *
 * @param {{bytes: Uint8Array, seen: Set<string>, ca: boolean, keyCertSign: boolean, pathLen: number|null}} run
 *   The extensions read so far.
 * @param {{tag: number, start: number, end: number}} extension One extension.
 * @return {void}
 */
function readExtension(run, extension) {
	const { critical, name, value } = decodeExtension(run.bytes, extension)
	if (run.seen.has(name)) {
		throw new FederatedCertificateError('malformed')
	}
	run.seen.add(name)
	if (name === OID_BASIC_CONSTRAINTS) {
		Object.assign(run, basicConstraintsOf(run.bytes, value))
	} else if (name === OID_KEY_USAGE) {
		run.keyCertSign = keyCertSignOf(run.bytes, value)
	} else if (name === OID_NAME_CONSTRAINTS || critical) {
		throw new FederatedCertificateError('malformed')
	}
}

/**
 * Whether a certificate may sign certificates, from its extensions (RFC 5280
 * 4.2): each at most once and none critical that is not read here; a
 * basicConstraints with cA set (4.2.1.9) and, when there is a keyUsage,
 * keyCertSign in it (4.2.1.3), and whether cA is set at all. An extension
 * that does not decode is refused.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object|undefined} block The TBS `[3]` extensions element, if any.
 * @return {{ca: boolean, markedCa: boolean, pathLen: number|null}}
 */
function caConstraints(bytes, block) {
	const run = {
		bytes,
		seen: new Set(),
		ca: false,
		keyCertSign: true,
		pathLen: null,
	}
	for (const extension of extensionsOf(bytes, block)) {
		readExtension(run, extension)
	}
	return {
		ca: run.ca && run.keyCertSign,
		markedCa: run.ca,
		pathLen: run.ca ? run.pathLen : null,
	}
}

/**
 * Refuse a certificate as malformed unless a structural check holds.
 *
 * @param {boolean} holds The check.
 * @return {void}
 */
function malformedUnless(holds) {
	if (!holds) {
		throw new FederatedCertificateError('malformed')
	}
}

/**
 * The three parts of a certificate: the TBS and the signature algorithm,
 * both SEQUENCEs, and the signature.
 *
 * @param {Uint8Array} der The certificate (DER).
 * @return {{tbs: object, algorithm: object, signature: object}}
 */
function certificateParts(der) {
	const certificate = tlv(der, 0)
	const [tbs, algorithm, signature, extra] = children(der, certificate)
	malformedUnless(tbs?.tag === 0x30 && algorithm?.tag === 0x30)
	// A BIT STRING value starts with its unused-bits count, which a
	// signature never has.
	malformedUnless(
		signature?.tag === 0x03 && !extra && der[signature.start] === 0,
	)
	return { tbs, algorithm, signature }
}

/**
 * The fields of a TBS certificate after the optional version: the signed
 * algorithm, issuer, validity, subject and SPKI, all present, then the
 * optional unique ids and extensions.
 *
 * @param {Uint8Array} der The certificate (DER).
 * @param {object} tbs The TBS element.
 * @return {object} signed, issuer, validity, subject, spki and optional.
 */
function tbsFields(der, tbs) {
	const fields = exactChildren(der, tbs)
	const shift = fields[0]?.tag === 0xa0 ? 1 : 0
	const [, signed, issuer, validity, subject, spki, ...optional] =
		fields.slice(shift)
	malformedUnless(hasNamesAndKey({ signed, issuer, validity, subject, spki }))
	return { signed, issuer, validity, subject, spki, optional }
}

/**
 * @param {object} fields The required TBS fields.
 * @return {boolean} Whether all are present and the SPKI is a SEQUENCE.
 */
function hasNamesAndKey(fields) {
	return Boolean(
		fields.signed
		&& fields.issuer
		&& fields.validity
		&& fields.subject
		&& fields.spki?.tag === 0x30,
	)
}

/**
 * Whether the unique ids and the extensions come each at most once and in
 * order, so no second extensions block goes unread.
 *
 * @param {Array<object>} optional The TBS fields after the SPKI.
 * @return {boolean}
 */
function inTagOrder(optional) {
	return optional.every(
		(field, i) =>
			[0x81, 0x82, 0xa3].includes(field.tag)
			&& (i === 0 || field.tag > optional[i - 1].tag),
	)
}

/**
 * The parts of one certificate the checks need.
 *
 * @param {string} pem A PEM certificate.
 * @return {object} der, tbs, signature algorithm, signature, issuer and
 *   subject (DER), validity, SPKI and CA constraints.
 */
function parse(pem) {
	const der = pemToDer(pem)
	const parts = certificateParts(der)
	Object.assign(parts, tbsFields(der, parts.tbs))
	// RFC 5280 4.1.1.2: the signature is checked with the algorithm it covers.
	malformedUnless(
		equalBytes(whole(der, parts.signed), whole(der, parts.algorithm)),
	)
	malformedUnless(inTagOrder(parts.optional))
	const [notBefore, notAfter] = children(der, parts.validity)
	return {
		...signedPartsOf(der, parts),
		...namesAndKeyOf(der, { ...parts, notBefore, notAfter }),
		...caConstraints(
			der,
			parts.optional.find((field) => field.tag === 0xa3),
		),
	}
}

/**
 * @param {Uint8Array} der The certificate (DER).
 * @param {object} parts Its parts.
 * @return {object} der, tbs, the verify algorithm and the signature bytes.
 */
function signedPartsOf(der, parts) {
	return {
		der,
		tbs: whole(der, parts.tbs),
		algorithm: algorithmOf(der, parts.algorithm),
		signature: der.subarray(parts.signature.start + 1, parts.signature.end),
	}
}

/**
 * @param {Uint8Array} der The certificate (DER).
 * @param {object} parts Its parts, with the validity's two times.
 * @return {object} issuer, subject, notBefore, notAfter and spki.
 */
function namesAndKeyOf(der, parts) {
	return {
		issuer: whole(der, parts.issuer),
		subject: whole(der, parts.subject),
		notBefore: time(der, parts.notBefore),
		notAfter: time(der, parts.notAfter),
		spki: whole(der, parts.spki),
	}
}

/**
 * The WebCrypto verify parameters for a signature algorithm, or null for
 * one Keepiq does not use: only SHA-256, and for PSS only with
 * MGF1-SHA-256, since the PSS default is SHA-1. Parameters that do not
 * decode are malformed.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} algorithm An AlgorithmIdentifier.
 * @return {{import: object, verify: object}|null}
 */
function algorithmOf(bytes, algorithm) {
	const [id, params, extra] = exactChildren(bytes, algorithm)
	if (extra) {
		throw new FederatedCertificateError('malformed')
	}
	const name = oid(bytes, id)
	if (name === OID_SHA256_RSA) {
		const pkcs1 = { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }
		return { import: pkcs1, verify: pkcs1 }
	}
	if (name !== OID_RSA_PSS || !params) {
		return null
	}
	return pssAlgorithm(pssParams(bytes, params))
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {object} element An AlgorithmIdentifier, which must be a SEQUENCE.
 * @return {string} Its OID.
 */
function algorithmIdOf(bytes, element) {
	return oid(bytes, exactChildren(bytes, sequence(element))[0])
}

/**
 * @param {object} run The RSASSA-PSS-params read so far.
 * @param {object} inner The [0] field's value: the hash algorithm.
 * @return {void}
 */
function readPssHash(run, inner) {
	run.hash = algorithmIdOf(run.bytes, inner)
}

/**
 * @param {object} run The RSASSA-PSS-params read so far.
 * @param {object} inner The [1] field's value: the mask generation function.
 * @return {void}
 */
function readPssMask(run, inner) {
	const [maskId, maskParams] = exactChildren(run.bytes, sequence(inner))
	run.mask = oid(run.bytes, maskId)
	run.maskHash = maskParams ? algorithmIdOf(run.bytes, maskParams) : null
}

/**
 * @param {object} run The RSASSA-PSS-params read so far.
 * @param {object} inner The [2] field's value: the salt length.
 * @return {void}
 */
function readPssSaltLength(run, inner) {
	run.saltLength = derUint(run.bytes, inner)
}

/**
 * @param {object} run The RSASSA-PSS-params read so far.
 * @param {object} inner The [3] field's value: the trailer field, which must be 1.
 * @return {void}
 */
function readPssTrailer(run, inner) {
	malformedUnless(derUint(run.bytes, inner) === 1)
}

/** How each RSASSA-PSS-params field is read, by its context tag. */
const PSS_FIELDS = new Map([
	[0xa0, readPssHash],
	[0xa1, readPssMask],
	[0xa2, readPssSaltLength],
	[0xa3, readPssTrailer],
])

/**
 * RSASSA-PSS-params, each field at most once and in order.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object} params The parameters element.
 * @return {{hash: ?string, mask: ?string, maskHash: ?string, saltLength: number}}
 */
function pssParams(bytes, params) {
	const run = { bytes, hash: null, mask: null, maskHash: null, saltLength: 20 }
	let previous = 0
	for (const field of exactChildren(bytes, sequence(params))) {
		malformedUnless(field.tag > previous)
		previous = field.tag
		readPssField(run, field)
	}
	return run
}

/**
 * Read one RSASSA-PSS-params field into the run; an unknown one is malformed.
 *
 * @param {object} run The parameters read so far.
 * @param {object} field The field.
 * @return {void}
 */
function readPssField(run, field) {
	const inner = onlyChild(run.bytes, field)
	const read = PSS_FIELDS.get(field.tag)
	malformedUnless(read !== undefined)
	read(run, inner)
}

/**
 * @param {object} params The RSASSA-PSS-params read.
 * @return {{import: object, verify: object}|null} The verify parameters,
 *   or null unless the hash and the mask's hash are both SHA-256.
 */
function pssAlgorithm(params) {
	if (
		params.hash !== OID_SHA256
		|| params.mask !== OID_MGF1
		|| params.maskHash !== OID_SHA256
	) {
		return null
	}
	return {
		import: { name: 'RSA-PSS', hash: 'SHA-256' },
		verify: { name: 'RSA-PSS', saltLength: params.saltLength },
	}
}

/**
 * @param {{tag: number}|undefined} element Any element.
 * @return {object} The element, which must be a SEQUENCE.
 */
function sequence(element) {
	if (element?.tag !== 0x30) {
		throw new FederatedCertificateError('malformed')
	}
	return element
}

/**
 * The leaf's one common name, which must be text this decodes exactly: a
 * second one could name someone else to a reader that takes the other.
 * TeletexString and IA5String are read as OpenSSL's `string_mask` may write
 * a cloud id, only as printable ASCII, since other bytes read differently
 * per decoder. A CA's common name is never read; its name bytes and the pin
 * identify it.
 *
 * @param {Uint8Array} subject The subject Name (DER).
 * @return {string|null}
 */
function commonNameOf(subject) {
	const run = { subject, commonName: null }
	for (const rdn of children(subject, tlv(subject, 0))) {
		for (const attribute of children(subject, rdn)) {
			readCommonName(run, attribute)
		}
	}
	return run.commonName
}

/**
 * Take one name attribute's common name into the run, refusing a second one
 * and one that is not text this decodes exactly; other attributes are
 * passed over.
 *
 * @param {{subject: Uint8Array, commonName: ?string}} run The name read so far.
 * @param {object} attribute An AttributeTypeAndValue.
 * @return {void}
 */
function readCommonName(run, attribute) {
	const [type, value] = children(run.subject, attribute)
	if (!type || !value || oid(run.subject, type) !== OID_COMMON_NAME) {
		return
	}
	const text = run.subject.subarray(value.start, value.end)
	malformedUnless(run.commonName === null && isExactText(value, text))
	run.commonName = utf8.decode(text)
}

/**
 * @param {{tag: number}} value A string element.
 * @param {Uint8Array} text Its bytes.
 * @return {boolean} Whether it is UTF8String, or an ASCII string type
 *   holding only printable ASCII.
 */
function isExactText(value, text) {
	return value.tag === UTF8_STRING || isPrintableAscii(value, text)
}

/**
 * @param {{tag: number}} value A string element.
 * @param {Uint8Array} text Its bytes.
 * @return {boolean}
 */
function isPrintableAscii(value, text) {
	return (
		ASCII_STRINGS.has(value.tag)
		&& text.every((byte) => byte >= 0x20 && byte <= 0x7e)
	)
}

/**
 * Whether `issuer` signed `cert`: names match and the signature verifies.
 *
 * @param {object} cert The certificate.
 * @param {object} issuer The certificate above it.
 * @return {Promise<boolean>}
 */
async function signedBy(cert, issuer) {
	if (!cert.algorithm || !equalBytes(cert.issuer, issuer.subject)) {
		return false
	}
	try {
		const key = await crypto.subtle.importKey(
			'spki',
			issuer.spki,
			cert.algorithm.import,
			false,
			['verify'],
		)
		return await crypto.subtle.verify(
			cert.algorithm.verify,
			key,
			cert.signature,
			cert.tbs,
		)
	} catch {
		return false
	}
}

/**
 * Whether the certificate at `index` may sign the one below it: a CA allowed
 * to sign certificates, with no more intermediates below it than its
 * pathLenConstraint. Self-issued intermediates do not count (RFC 5280 6.1.4).
 *
 * @param {Array<object>} certs The parsed chain, recipient first.
 * @param {number} index The issuer's position, at least 1.
 * @return {boolean}
 */
function mayIssue(certs, index) {
	const issuer = certs[index]
	const below = certs
		.slice(1, index)
		.filter((cert) => !equalBytes(cert.issuer, cert.subject)).length
	return issuer.ca && (issuer.pathLen === null || below <= issuer.pathLen)
}

/**
 * @param {Uint8Array} a Bytes.
 * @param {Uint8Array} b Bytes.
 * @return {boolean}
 */
function equalBytes(a, b) {
	return a.length === b.length && a.every((byte, i) => byte === b[i])
}

/**
 * @param {Uint8Array} der DER bytes.
 * @return {Promise<string>} Lowercase hex SHA-256.
 */
async function sha256Hex(der) {
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', der))
	return Array.from(digest, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/**
 * A cloud id as `user@host[:port][/path]`: Nextcloud writes the user's own
 * cloud id on an http instance with the scheme (`bob@http://host`), which
 * is the common name of their certificate, while the owner types `bob@host`.
 * The scheme and a trailing slash are dropped and the host lowercased; the
 * user part and the path stay as they are.
 *
 * @param {string} cloudId A cloud id.
 * @return {string} The canonical form, or '' without `user@remote`.
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export function canonicalCloudId(cloudId) {
	const text = String(cloudId ?? '')
	const at = text.lastIndexOf('@')
	if (at <= 0) {
		return ''
	}
	const remote = text
		.slice(at + 1)
		.replace(/^https?:\/\//i, '')
		.replace(/\/+$/, '')
	const host = remote.split('/', 1)[0]
	return remote === ''
		? ''
		: `${text.slice(0, at)}@${host.toLowerCase()}${remote.slice(host.length)}`
}

/**
 * Verify a federated recipient's certificate against the pinned partner
 * root. Resolves with the certificate's fingerprint, for the owner to
 * compare with the recipient, and its public key, the only key to encrypt
 * for; rejects with a FederatedCertificateError.
 *
 * @param {object} input What the owner's server returned.
 * @param {string} input.certificate The recipient certificate (PEM).
 * @param {string[]} input.chain The partner's intermediate, then its root (PEM).
 * @param {string} input.partnerRootFingerprint The pinned root, lowercase hex SHA-256.
 * @param {string} input.cloudId The cloud id the owner entered.
 * @param {number} [input.now] The time to check validity at (ms), default now.
 * @return {Promise<{fingerprint: string, spki: Uint8Array}>} The recipient
 *   certificate's SHA-256, colon separated, and its SubjectPublicKeyInfo (DER).
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export async function verifyFederatedCertificate({
	certificate,
	chain,
	partnerRootFingerprint,
	cloudId,
	now = Date.now(),
}) {
	assertPartnerChain(chain)
	const { certs, commonName } = parseChain(certificate, chain)
	await assertPinnedRoot(certs[certs.length - 1], partnerRootFingerprint)
	assertIntermediateFirst(certs)
	await assertEachLinkSigned(certs)
	assertNamesRecipient(commonName, cloudId)
	assertValidAt(certs, now)
	return recipientKeyOf(certs[0])
}

/**
 * The chain must be the partner's intermediate, then its root:
 * FederationRootService::localChain() serves exactly these two.
 *
 * @param {Array<string>} chain The chain (PEM).
 * @return {void}
 */
function assertPartnerChain(chain) {
	if (!Array.isArray(chain) || chain.length === 0) {
		throw new FederatedCertificateError('untrusted_root')
	}
	if (chain.length !== CHAIN_LENGTH) {
		throw new FederatedCertificateError('bad_chain')
	}
}

/**
 * Parse the recipient certificate and its chain, and read the recipient's
 * common name; anything that does not decode is malformed.
 *
 * @param {string} certificate The recipient certificate (PEM).
 * @param {Array<string>} chain The chain (PEM).
 * @return {{certs: Array<object>, commonName: ?string}}
 */
function parseChain(certificate, chain) {
	try {
		const certs = [certificate, ...chain].map(parse)
		return { certs, commonName: commonNameOf(certs[0].subject) }
	} catch (e) {
		throw e instanceof FederatedCertificateError
			? e
			: new FederatedCertificateError('malformed')
	}
}

/**
 * @param {object} root The chain's last certificate.
 * @param {string} partnerRootFingerprint The pinned root, hex SHA-256.
 * @return {Promise<void>} Rejects unless the root is the pinned one.
 */
async function assertPinnedRoot(root, partnerRootFingerprint) {
	const pinned = String(partnerRootFingerprint || '').toLowerCase()
	if (pinned === '' || (await sha256Hex(root.der)) !== pinned) {
		throw new FederatedCertificateError('untrusted_root')
	}
}

/**
 * The first link must be the partner's intermediate, not its root again,
 * and the recipient's certificate no CA.
 *
 * @param {Array<object>} certs The parsed chain, recipient first.
 * @return {void}
 */
function assertIntermediateFirst(certs) {
	if (equalBytes(certs[1].issuer, certs[1].subject) || certs[0].markedCa) {
		throw new FederatedCertificateError('bad_chain')
	}
}

/**
 * @param {Array<object>} certs The parsed chain, recipient first.
 * @return {Promise<void>} Rejects unless every certificate was signed by
 *   the next one, which may issue it.
 */
async function assertEachLinkSigned(certs) {
	for (let i = 0; i < certs.length - 1; i++) {
		if (!mayIssue(certs, i + 1) || !(await signedBy(certs[i], certs[i + 1]))) {
			throw new FederatedCertificateError('bad_chain')
		}
	}
}

/**
 * The recipient certificate's common name must be the cloud id the owner
 * entered; two names without `user@remote` both canonicalise to ''.
 *
 * @param {?string} commonName The recipient certificate's common name.
 * @param {string} cloudId The cloud id the owner entered.
 * @return {void}
 */
function assertNamesRecipient(commonName, cloudId) {
	const expected = canonicalCloudId(cloudId)
	if (
		expected === ''
		|| commonName === null
		|| canonicalCloudId(commonName) !== expected
	) {
		throw new FederatedCertificateError('name_mismatch')
	}
}

/**
 * @param {Array<object>} certs The parsed chain.
 * @param {number} now The time to check validity at (ms).
 * @return {void}
 */
function assertValidAt(certs, now) {
	const valid = (cert) =>
		now + CLOCK_SKEW_MS >= cert.notBefore && now <= cert.notAfter
	if (!certs.every(valid)) {
		throw new FederatedCertificateError('not_valid_now')
	}
}

/**
 * @param {object} leaf The verified recipient certificate.
 * @return {Promise<{fingerprint: string, spki: Uint8Array}>}
 */
async function recipientKeyOf(leaf) {
	const hex = await sha256Hex(leaf.der)
	return {
		fingerprint: hex.match(/.{2}/g).join(':').toUpperCase(),
		spki: leaf.spki.slice(),
	}
}
