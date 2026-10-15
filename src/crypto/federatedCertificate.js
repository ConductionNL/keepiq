/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Verifies a federated recipient's certificate in the owner's browser
 * before anything is encrypted for it (sharing-federated-recipients D3,
 * task 2.3). The owner's server fetched the certificate and its CA chain
 * from the partner; neither server is trusted for this check:
 *
 * - the chain is the partner's intermediate, then its root, and the root
 *   must be the one whose SHA-256 fingerprint the administrator pinned,
 * - every certificate must be signed by the next one (issuer name and
 *   signature, PKCS#1 v1.5 or RSASSA-PSS with SHA-256), and each one above
 *   the recipient's must be a CA allowed to sign certificates,
 * - the recipient certificate's common name must be the cloud id the owner
 *   typed, and every certificate must be valid now.
 *
 * Only the structure needed for those checks is parsed; no ASN.1 library.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

const OID_COMMON_NAME = '2.5.4.3'
const OID_BASIC_CONSTRAINTS = '2.5.29.19'
const OID_KEY_USAGE = '2.5.29.15'
const OID_SHA256_RSA = '1.2.840.113549.1.1.11'
const OID_RSA_PSS = '1.2.840.113549.1.1.10'
const OID_SHA256 = '2.16.840.1.101.3.4.2.1'

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
 * @param {string} pem A PEM certificate.
 * @return {Uint8Array} Its DER bytes.
 */
function pemToDer(pem) {
	const match =
		/-----BEGIN CERTIFICATE-----([\s\S]+?)-----END CERTIFICATE-----/.exec(
			typeof pem === 'string' ? pem : '',
		)
	if (!match) {
		throw new FederatedCertificateError('malformed')
	}
	const raw = atob(match[1].replace(/[^A-Za-z0-9+/=]/g, ''))
	return Uint8Array.from(raw, (char) => char.charCodeAt(0))
}

/**
 * Read one DER element.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {number} offset Where the element starts.
 * @return {{tag: number, offset: number, start: number, end: number}}
 *   `offset` is the header start, `start` to `end` the value.
 */
function tlv(bytes, offset) {
	if (offset + 2 > bytes.length) {
		throw new FederatedCertificateError('malformed')
	}
	const tag = bytes[offset]
	let cursor = offset + 1
	let length = bytes[cursor++]
	if (length & 0x80) {
		const count = length & 0x7f
		if (count === 0 || count > 4) {
			throw new FederatedCertificateError('malformed')
		}
		length = 0
		for (let i = 0; i < count; i++) {
			length = length * 256 + bytes[cursor++]
		}
	}
	if (cursor + length > bytes.length) {
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
		const child = tlv(bytes, cursor)
		out.push(child)
		cursor = child.end
	}
	return out
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{start: number, end: number}} element An OID element.
 * @return {string} Dotted OID.
 */
function oid(bytes, element) {
	const parts = []
	let value = 0
	for (let i = element.start; i < element.end; i++) {
		value = value * 128 + (bytes[i] & 0x7f)
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
 * @param {number} tag The tag its one child must have.
 * @return {object} That child.
 */
function onlyChild(bytes, parent, tag) {
	const [child, extra] = exactChildren(bytes, parent)
	if (child?.tag !== tag || extra) {
		throw new FederatedCertificateError('malformed')
	}
	return child
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} element A BOOLEAN.
 * @return {boolean}
 */
function derBoolean(bytes, element) {
	if (element.tag !== 0x01 || element.end - element.start !== 1) {
		throw new FederatedCertificateError('malformed')
	}
	return bytes[element.start] !== 0
}

/**
 * @param {Uint8Array} bytes The buffer.
 * @param {{tag: number, start: number, end: number}} element A pathLenConstraint INTEGER.
 * @return {number}
 */
function derPathLen(bytes, element) {
	const length = element.end - element.start
	if (
		element.tag !== 0x02
		|| length < 1
		|| length > 4
		|| bytes[element.start] & 0x80
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
 * Whether a certificate may sign certificates, from its extensions (RFC 5280
 * 4.2): each at most once and none critical that is not read here; a
 * basicConstraints with cA set (4.2.1.9) and, when there is a keyUsage,
 * keyCertSign in it (4.2.1.3). An extension that does not decode is refused.
 *
 * @param {Uint8Array} bytes The buffer.
 * @param {object|undefined} block The TBS `[3]` extensions element, if any.
 * @return {{ca: boolean, pathLen: number|null}}
 */
function caConstraints(bytes, block) {
	let ca = false
	let keyCertSign = true
	let pathLen = null
	const seen = new Set()
	const extensions = block
		? exactChildren(bytes, onlyChild(bytes, block, 0x30))
		: []
	for (const extension of extensions) {
		if (extension.tag !== 0x30) {
			throw new FederatedCertificateError('malformed')
		}
		// Extension: extnID, critical BOOLEAN DEFAULT FALSE, extnValue OCTET STRING.
		const [id, ...rest] = exactChildren(bytes, extension)
		const value = rest.pop()
		if (id?.tag !== 0x06 || value?.tag !== 0x04 || rest.length > 1) {
			throw new FederatedCertificateError('malformed')
		}
		const critical = rest.length === 1 && derBoolean(bytes, rest[0])
		const name = oid(bytes, id)
		if (seen.has(name)) {
			throw new FederatedCertificateError('malformed')
		}
		seen.add(name)
		if (name === OID_BASIC_CONSTRAINTS) {
			const fields = exactChildren(bytes, onlyChild(bytes, value, 0x30))
			let next = 0
			if (fields[next]?.tag === 0x01) {
				ca = derBoolean(bytes, fields[next++])
			}
			if (fields[next]?.tag === 0x02) {
				pathLen = derPathLen(bytes, fields[next++])
			}
			if (next !== fields.length) {
				throw new FederatedCertificateError('malformed')
			}
		} else if (name === OID_KEY_USAGE) {
			const bits = onlyChild(bytes, value, 0x03)
			const length = bits.end - bits.start
			const unused = bytes[bits.start]
			if (length === 0 || unused > 7 || (length === 1 && unused !== 0)) {
				throw new FederatedCertificateError('malformed')
			}
			// After the unused-bits count, keyCertSign is bit 5 of the first
			// byte; when that byte is also the last, its unused bits are padding.
			const first = length > 1 ? bytes[bits.start + 1] : 0
			const mask = length === 2 ? (0xff << unused) & 0xff : 0xff
			keyCertSign = (first & mask & 0x04) !== 0
		} else if (critical) {
			throw new FederatedCertificateError('malformed')
		}
	}
	return { ca: ca && keyCertSign, pathLen: ca ? pathLen : null }
}

/**
 * The parts of one certificate the checks need.
 *
 * @param {string} pem A PEM certificate.
 * @return {object} der, tbs, signature algorithm, signature, issuer and
 *   subject (DER), validity, SPKI, common name and CA constraints.
 */
function parse(pem) {
	const der = pemToDer(pem)
	const certificate = tlv(der, 0)
	const [tbs, algorithm, signature] = children(der, certificate)
	if (!tbs || !algorithm || !signature || signature.tag !== 0x03) {
		throw new FederatedCertificateError('malformed')
	}
	const fields = exactChildren(der, tbs)
	const shift = fields[0]?.tag === 0xa0 ? 1 : 0
	const [, , issuer, validity, subject, spki, ...optional] = fields.slice(shift)
	if (!issuer || !validity || !subject || !spki) {
		throw new FederatedCertificateError('malformed')
	}
	// The unique ids and the extensions, each at most once and in order, so
	// no second extensions block goes unread.
	const ordered = optional.every(
		(field, i) =>
			[0x81, 0x82, 0xa3].includes(field.tag)
			&& (i === 0 || field.tag > optional[i - 1].tag),
	)
	if (!ordered) {
		throw new FederatedCertificateError('malformed')
	}
	const [notBefore, notAfter] = children(der, validity)
	let commonName = null
	for (const rdn of children(der, subject)) {
		for (const attribute of children(der, rdn)) {
			const [type, value] = children(der, attribute)
			if (type && value && oid(der, type) === OID_COMMON_NAME) {
				commonName = new TextDecoder().decode(
					der.subarray(value.start, value.end),
				)
			}
		}
	}
	const algorithmParts = children(der, algorithm)
	return {
		der,
		tbs: whole(der, tbs),
		algorithmOid: oid(der, algorithmParts[0]),
		algorithmParams: algorithmParts[1]
			? { bytes: der, element: algorithmParts[1] }
			: null,
		// A BIT STRING value starts with its unused-bits count, always 0 here.
		signature: der.subarray(signature.start + 1, signature.end),
		issuer: whole(der, issuer),
		subject: whole(der, subject),
		notBefore: time(der, notBefore),
		notAfter: time(der, notAfter),
		spki: whole(der, spki),
		commonName,
		...caConstraints(
			der,
			optional.find((field) => field.tag === 0xa3),
		),
	}
}

/**
 * The WebCrypto verify parameters for a signature algorithm, or null for
 * one Keepiq does not use.
 *
 * @param {object} cert A parsed certificate.
 * @return {{import: object, verify: object}|null}
 */
function algorithmOf(cert) {
	if (cert.algorithmOid === OID_SHA256_RSA) {
		const params = { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }
		return { import: params, verify: params }
	}
	if (cert.algorithmOid !== OID_RSA_PSS || !cert.algorithmParams) {
		return null
	}
	// RSASSA-PSS-params: [0] hash, [1] mask generation, [2] salt length.
	// Only SHA-256 with MGF1-SHA-256 is accepted; the default (SHA-1) is not.
	const { bytes, element } = cert.algorithmParams
	let hash = null
	let mgfHash = null
	let saltLength = 20
	for (const field of children(bytes, element)) {
		const inner = children(bytes, field)[0]
		if (field.tag === 0xa0) {
			hash = oid(bytes, children(bytes, inner)[0])
		} else if (field.tag === 0xa1) {
			const mgfParams = children(bytes, inner)[1]
			mgfHash = mgfParams ? oid(bytes, children(bytes, mgfParams)[0]) : null
		} else if (field.tag === 0xa2) {
			saltLength = 0
			for (let i = inner.start; i < inner.end; i++) {
				saltLength = saltLength * 256 + bytes[i]
			}
		}
	}
	if (hash !== OID_SHA256 || mgfHash !== OID_SHA256) {
		return null
	}
	return {
		import: { name: 'RSA-PSS', hash: 'SHA-256' },
		verify: { name: 'RSA-PSS', saltLength },
	}
}

/**
 * Whether `issuer` signed `cert`: names match and the signature verifies.
 *
 * @param {object} cert The certificate.
 * @param {object} issuer The certificate above it.
 * @return {Promise<boolean>}
 */
async function signedBy(cert, issuer) {
	if (!equalBytes(cert.issuer, issuer.subject)) {
		return false
	}
	const algorithm = algorithmOf(cert)
	if (!algorithm) {
		return false
	}
	try {
		const key = await crypto.subtle.importKey(
			'spki',
			issuer.spki,
			algorithm.import,
			false,
			['verify'],
		)
		return await crypto.subtle.verify(
			algorithm.verify,
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
 * user part stays as it is.
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
		.toLowerCase()
	return remote === '' ? '' : `${text.slice(0, at)}@${remote}`
}

/**
 * Verify a federated recipient's certificate against the pinned partner
 * root. Resolves with the certificate's fingerprint, for the owner to
 * compare with the recipient; rejects with a FederatedCertificateError.
 *
 * @param {object} input What the owner's server returned.
 * @param {string} input.certificate The recipient certificate (PEM).
 * @param {string[]} input.chain The partner's intermediate, then its root (PEM).
 * @param {string} input.partnerRootFingerprint The pinned root, lowercase hex SHA-256.
 * @param {string} input.cloudId The cloud id the owner entered.
 * @param {number} [input.now] The time to check validity at (ms), default now.
 * @return {Promise<{fingerprint: string}>} The recipient certificate's SHA-256, colon separated.
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export async function verifyFederatedCertificate({
	certificate,
	chain,
	partnerRootFingerprint,
	cloudId,
	now = Date.now(),
}) {
	if (!Array.isArray(chain) || chain.length === 0) {
		throw new FederatedCertificateError('untrusted_root')
	}
	// FederationRootService::localChain() serves exactly these two.
	if (chain.length !== CHAIN_LENGTH) {
		throw new FederatedCertificateError('bad_chain')
	}
	const certs = [certificate, ...chain].map(parse)
	const root = certs[certs.length - 1]
	const pinned = String(partnerRootFingerprint || '').toLowerCase()
	if (pinned === '' || (await sha256Hex(root.der)) !== pinned) {
		throw new FederatedCertificateError('untrusted_root')
	}
	for (let i = 0; i < certs.length - 1; i++) {
		if (!mayIssue(certs, i + 1) || !(await signedBy(certs[i], certs[i + 1]))) {
			throw new FederatedCertificateError('bad_chain')
		}
	}
	const leaf = certs[0]
	if (
		leaf.commonName === null
		|| canonicalCloudId(leaf.commonName) !== canonicalCloudId(cloudId)
	) {
		throw new FederatedCertificateError('name_mismatch')
	}
	const valid = (cert) =>
		now + CLOCK_SKEW_MS >= cert.notBefore && now <= cert.notAfter
	if (!certs.every(valid)) {
		throw new FederatedCertificateError('not_valid_now')
	}
	const hex = await sha256Hex(leaf.der)
	return { fingerprint: hex.match(/.{2}/g).join(':').toUpperCase() }
}
