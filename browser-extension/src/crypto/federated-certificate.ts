/** A port of the Keepiq web app's `src/crypto/federatedCertificate.js`. Both servers are trusted, the pin arriving in the owner server's reply; this catches a certificate for the wrong user, an expired one, or a chain from another partner before anything is encrypted to it. */
import { decodePem } from './rsa'

const OID_COMMON_NAME = '2.5.4.3'
const OID_BASIC_CONSTRAINTS = '2.5.29.19'
const OID_KEY_USAGE = '2.5.29.15'
const OID_SHA256_RSA = '1.2.840.113549.1.1.11'
const OID_RSA_PSS = '1.2.840.113549.1.1.10'
const OID_MGF1 = '1.2.840.113549.1.1.8'
const OID_SHA256 = '2.16.840.1.101.3.4.2.1'
const UTF8_STRING = 0x0c
const PRINTABLE_STRING = 0x13
const TELETEX_STRING = 0x14
const IA5_STRING = 0x16

/** How far `notBefore` may lie ahead of this clock, so a freshly renewed partner intermediate passes a clock running slightly behind; `notAfter` is strict. */
const CLOCK_SKEW_MS = 5 * 60 * 1000

export type FederatedCertificateReason = 'malformed' | 'untrusted_root' | 'bad_chain' | 'name_mismatch' | 'not_valid_now'

export class FederatedCertificateError extends Error {
	constructor(readonly reason: FederatedCertificateReason) {
		super(reason)
		this.name = 'FederatedCertificateError'
	}
}

interface Element {
	tag: number
	/** Where the header starts. */
	offset: number
	start: number
	end: number
}

type Algorithm = { import: RsaHashedImportParams; verify: AlgorithmIdentifier | RsaPssParams }

interface Parsed {
	der: Uint8Array<ArrayBuffer>
	tbs: Uint8Array<ArrayBuffer>
	/** `null` for a signature algorithm Keepiq does not use. */
	algorithm: Algorithm | null
	signature: Uint8Array<ArrayBuffer>
	issuer: Uint8Array
	subject: Uint8Array
	notBefore: number
	notAfter: number
	spki: Uint8Array<ArrayBuffer>
	/** A CA whose key usage, if it has one, includes keyCertSign. */
	mayIssue: boolean
	pathLen: number | null
}

const malformed = () => new FederatedCertificateError('malformed')
const utf8 = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true })

function pemToDer(pem: unknown): Uint8Array<ArrayBuffer> {
	const { label, der } = decodePem(typeof pem === 'string' ? pem : '')
	if (label !== 'CERTIFICATE') throw malformed()
	return der
}

function byteAt(bytes: Uint8Array, offset: number): number {
	const byte = bytes[offset]
	if (byte === undefined) throw malformed()
	return byte
}

/** One DER element whose length fits in `limit`, so a hostile certificate can't read out of bounds. */
function tlv(bytes: Uint8Array, offset: number, limit = bytes.length): Element {
	const tag = byteAt(bytes, offset)
	let cursor = offset + 1
	let length = byteAt(bytes, cursor++)
	if (length & 0x80) {
		const count = length & 0x7f
		if (count === 0 || count > 4) throw malformed()
		length = 0
		for (let i = 0; i < count; i++) length = length * 256 + byteAt(bytes, cursor++)
	}
	if (cursor + length > limit) throw malformed()
	return { tag, offset, start: cursor, end: cursor + length }
}

function children(bytes: Uint8Array, parent: Element | undefined): Element[] {
	if (!parent) throw malformed()
	const out: Element[] = []
	for (let cursor = parent.start; cursor < parent.end;) {
		const child = tlv(bytes, cursor, parent.end)
		out.push(child)
		cursor = child.end
	}
	return out
}

/** The one element that fills `parent`, with `tag` when given. */
function only(bytes: Uint8Array, parent: Element | undefined, tag?: number): Element {
	const [child, extra] = children(bytes, parent)
	if (!child || extra || (tag !== undefined && child.tag !== tag)) throw malformed()
	return child
}

function uint(bytes: Uint8Array, element: Element): number {
	const length = element.end - element.start
	if (element.tag !== 0x02 || length < 1 || length > 4 || byteAt(bytes, element.start) & 0x80) throw malformed()
	let value = 0
	for (let i = element.start; i < element.end; i++) value = value * 256 + byteAt(bytes, i)
	return value
}

function boolean(bytes: Uint8Array, element: Element): boolean {
	if (element.tag !== 0x01 || element.end - element.start !== 1) throw malformed()
	return byteAt(bytes, element.start) !== 0
}

function oid(bytes: Uint8Array, element: Element | undefined): string {
	if (element?.tag !== 0x06) throw malformed()
	const parts: number[] = []
	let value = 0
	for (let i = element.start; i < element.end; i++) {
		const byte = byteAt(bytes, i)
		value = value * 128 + (byte & 0x7f)
		if ((byte & 0x80) === 0) {
			if (parts.length === 0) parts.push(Math.floor(value / 40), value % 40)
			else parts.push(value)
			value = 0
		}
	}
	return parts.join('.')
}

/** The whole element, header included; a copy, since WebCrypto takes `ArrayBuffer`-backed views. */
function whole(bytes: Uint8Array<ArrayBuffer>, element: Element): Uint8Array<ArrayBuffer> {
	return bytes.slice(element.offset, element.end)
}

const equalBytes = (a: Uint8Array, b: Uint8Array) => a.length === b.length && a.every((byte, i) => byte === b[i])

function time(bytes: Uint8Array, element: Element | undefined): number {
	if (!element) throw malformed()
	const text = new TextDecoder().decode(bytes.subarray(element.start, element.end))
	const match = /^(\d{2}|\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$/.exec(text)
	if (!match) throw malformed()
	let year = Number(match[1])
	if (match[1]!.length === 2) year += year < 50 ? 2000 : 1900
	return Date.UTC(year, Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6]))
}

/** RFC 5280 § 4.2: each extension at most once, and none critical that this doesn't read. § 4.2.1.3 and § 4.2.1.9: without basicConstraints a certificate is no CA. */
function issuing(bytes: Uint8Array, extensions: Element | undefined): Pick<Parsed, 'mayIssue' | 'pathLen'> {
	let ca = false
	let keyCertSign = true
	let pathLen: number | null = null
	const seen = new Set<string>()
	for (const extension of extensions ? children(bytes, only(bytes, extensions, 0x30)) : []) {
		if (extension.tag !== 0x30) throw malformed()
		const [extnId, ...rest] = children(bytes, extension)
		const value = rest.pop()
		const [critical, extra] = rest
		if (value?.tag !== 0x04 || extra) throw malformed()
		const id = oid(bytes, extnId)
		if (seen.has(id)) throw malformed()
		seen.add(id)
		if (critical && boolean(bytes, critical) && id !== OID_BASIC_CONSTRAINTS && id !== OID_KEY_USAGE) throw malformed()
		if (id === OID_BASIC_CONSTRAINTS) {
			const fields = children(bytes, only(bytes, value, 0x30))
			let next = 0
			if (fields[next]?.tag === 0x01) ca = boolean(bytes, fields[next++]!)
			if (fields[next]?.tag === 0x02) pathLen = uint(bytes, fields[next++]!)
			if (next !== fields.length) throw malformed()
		} else if (id === OID_KEY_USAGE) {
			const bits = only(bytes, value, 0x03)
			const length = bits.end - bits.start
			const unused = length > 0 ? byteAt(bytes, bits.start) : 0
			if (length === 0 || unused > 7 || (length === 1 && unused !== 0)) throw malformed()
			// After the unused-bits count, keyCertSign is bit 5 of the first byte; when that byte is also the last, its unused bits are padding.
			const first = length > 1 ? byteAt(bytes, bits.start + 1) : 0
			const mask = length === 2 ? (0xff << unused) & 0xff : 0xff
			keyCertSign = (first & mask & 0x04) !== 0
		}
	}
	return { mayIssue: ca && keyCertSign, pathLen: ca ? pathLen : null }
}

/** Only SHA-256, and for PSS only with MGF1-SHA-256: the PSS default is SHA-1. Parameters that don't decode are malformed. */
function algorithmOf(bytes: Uint8Array, algorithm: Element): Algorithm | null {
	const [id, params, extra] = children(bytes, algorithm)
	if (extra) throw malformed()
	const name = oid(bytes, id)
	if (name === OID_SHA256_RSA) {
		const pkcs1 = { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }
		return { import: pkcs1, verify: pkcs1 }
	}
	if (name !== OID_RSA_PSS || !params) return null
	if (params.tag !== 0x30) throw malformed()
	const algorithmId = (element: Element) => oid(bytes, children(bytes, element.tag === 0x30 ? element : undefined)[0])
	let hash: string | null = null
	let mask: string | null = null
	let maskHash: string | null = null
	let saltLength = 20
	// RSASSA-PSS-params: [0] hash, [1] mask generation, [2] salt length, [3] trailer field.
	for (const field of children(bytes, params)) {
		const inner = only(bytes, field)
		if (field.tag === 0xa0) hash = algorithmId(inner)
		else if (field.tag === 0xa1) {
			if (inner.tag !== 0x30) throw malformed()
			const [maskId, maskParams] = children(bytes, inner)
			mask = oid(bytes, maskId)
			maskHash = maskParams ? algorithmId(maskParams) : null
		} else if (field.tag === 0xa2) saltLength = uint(bytes, inner)
		else if (field.tag !== 0xa3 || uint(bytes, inner) !== 1) throw malformed()
	}
	if (hash !== OID_SHA256 || mask !== OID_MGF1 || maskHash !== OID_SHA256) return null
	return { import: { name: 'RSA-PSS', hash: 'SHA-256' }, verify: { name: 'RSA-PSS', saltLength } }
}

const ASCII_STRINGS = new Set([PRINTABLE_STRING, TELETEX_STRING, IA5_STRING])

/** The other string types OpenSSL's `string_mask` may write a cloud id in, only as printable ASCII: any other byte reads differently per decoder. */
function textOf(bytes: Uint8Array, value: Element): string {
	const text = bytes.subarray(value.start, value.end)
	if (value.tag === UTF8_STRING || (ASCII_STRINGS.has(value.tag) && text.every((byte) => byte >= 0x20 && byte <= 0x7e))) return utf8.decode(text)
	throw malformed()
}

/** The leaf's one common name, which must be text this decodes exactly; a second could name someone else to a reader that takes the other. A CA's is never read. */
function commonNameOf(subject: Uint8Array): string | null {
	let commonName: string | null = null
	for (const rdn of children(subject, tlv(subject, 0))) {
		for (const attribute of children(subject, rdn)) {
			const [type, value] = children(subject, attribute)
			if (!type || !value || oid(subject, type) !== OID_COMMON_NAME) continue
			if (commonName !== null) throw malformed()
			commonName = textOf(subject, value)
		}
	}
	return commonName
}

function parse(pem: unknown): Parsed {
	const der = pemToDer(pem)
	const outer = tlv(der, 0)
	if (outer.tag !== 0x30 || outer.end !== der.length) throw malformed()
	const [tbs, algorithm, signature, extra] = children(der, outer)
	// A BIT STRING value starts with its unused-bits count, which a signature never has.
	if (tbs?.tag !== 0x30 || algorithm?.tag !== 0x30 || signature?.tag !== 0x03 || extra || signature.end === signature.start || byteAt(der, signature.start) !== 0) throw malformed()
	const fields = children(der, tbs)
	const [, signed, issuer, validity, subject, spki, ...optional] = fields.slice(fields[0]?.tag === 0xa0 ? 1 : 0)
	if (!signed || !issuer || !validity || !subject || spki?.tag !== 0x30) throw malformed()
	// RFC 5280 § 4.1.1.2: the algorithm the signature covers is the one it is checked with.
	if (!equalBytes(der.subarray(signed.offset, signed.end), der.subarray(algorithm.offset, algorithm.end))) throw malformed()
	// The unique IDs and the extensions, each at most once and in order, so no second extensions block goes unread.
	if (!optional.every((field, i) => [0x81, 0x82, 0xa3].includes(field.tag) && field.tag > (optional[i - 1]?.tag ?? 0))) throw malformed()
	const [notBefore, notAfter] = children(der, validity)
	return {
		der,
		tbs: whole(der, tbs),
		algorithm: algorithmOf(der, algorithm),
		signature: der.slice(signature.start + 1, signature.end),
		issuer: whole(der, issuer),
		subject: whole(der, subject),
		notBefore: time(der, notBefore),
		notAfter: time(der, notAfter),
		spki: whole(der, spki),
		...issuing(der, optional.find((field) => field.tag === 0xa3)),
	}
}

async function signedBy(cert: Parsed, issuer: Parsed): Promise<boolean> {
	if (!cert.algorithm || !equalBytes(cert.issuer, issuer.subject)) return false
	try {
		const key = await crypto.subtle.importKey('spki', issuer.spki, cert.algorithm.import, false, ['verify'])
		return await crypto.subtle.verify(cert.algorithm.verify, key, cert.signature, cert.tbs)
	} catch {
		return false
	}
}

async function sha256Hex(der: Uint8Array<ArrayBuffer>): Promise<string> {
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', der))
	return Array.from(digest, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

/** `user@host[:port][/path]`: an http instance names its users `bob@http://host`, which is their certificate's common name, while the share holds `bob@host`. Only the host's case is folded. */
export function canonicalCloudId(cloudId: unknown): string {
	const text = String(cloudId ?? '')
	const at = text.lastIndexOf('@')
	if (at <= 0) return ''
	const remote = text.slice(at + 1).replace(/^https?:\/\//i, '').replace(/\/+$/, '')
	const host = remote.split('/', 1)[0]!
	return remote === '' ? '' : `${text.slice(0, at)}@${host.toLowerCase()}${remote.slice(host.length)}`
}

export interface FederatedCertificateInput {
	certificate: unknown
	/** The partner's intermediate, then its root. */
	chain: unknown
	/** The owner server's pin, hex SHA-256. */
	partnerRootFingerprint: unknown
	/** The share's own `recipientCloudId`, never the lookup's echo of it. */
	cloudId: string
	now?: number
}

export interface VerifiedFederatedCertificate {
	/** The leaf's SHA-256 as lowercase hex, the `certFingerprint` Keepiq takes. */
	fingerprint: string
	/** The leaf's SubjectPublicKeyInfo, for `importSpki`: a second parse of the PEM might not find the key that was checked. */
	spki: Uint8Array<ArrayBuffer>
}

/** Rejects with a `FederatedCertificateError`. */
export async function verifyFederatedCertificate({ certificate, chain, partnerRootFingerprint, cloudId, now = Date.now() }: FederatedCertificateInput): Promise<VerifiedFederatedCertificate> {
	if (!Array.isArray(chain) || chain.length === 0) throw new FederatedCertificateError('untrusted_root')
	// Keepiq's `FederationRootService::localChain` serves exactly these two.
	if (chain.length !== 2) throw new FederatedCertificateError('bad_chain')
	let certs: Parsed[]
	let commonName: string | null
	try {
		certs = [certificate, ...chain].map(parse)
		commonName = commonNameOf(certs[0]!.subject)
	} catch (error) {
		throw error instanceof FederatedCertificateError ? error : malformed()
	}
	const pinned = typeof partnerRootFingerprint === 'string' ? partnerRootFingerprint.toLowerCase() : ''
	if (pinned === '' || await sha256Hex(certs[certs.length - 1]!.der) !== pinned) throw new FederatedCertificateError('untrusted_root')
	// The first link must be the partner's intermediate, not its root again.
	if (equalBytes(certs[1]!.issuer, certs[1]!.subject)) throw new FederatedCertificateError('bad_chain')
	for (let i = 1; i < certs.length; i++) {
		const issuer = certs[i]!
		// pathLen counts the CAs below this one, not the leaf.
		const mayIssue = issuer.mayIssue && (issuer.pathLen === null || i - 1 <= issuer.pathLen)
		if (!mayIssue || !await signedBy(certs[i - 1]!, issuer)) throw new FederatedCertificateError('bad_chain')
	}
	const leaf = certs[0]!
	const expected = canonicalCloudId(cloudId)
	// Two names without `user@remote` both canonicalise to ''.
	if (expected === '' || commonName === null || canonicalCloudId(commonName) !== expected) throw new FederatedCertificateError('name_mismatch')
	if (certs.some((cert) => now + CLOCK_SKEW_MS < cert.notBefore || now > cert.notAfter)) throw new FederatedCertificateError('not_valid_now')
	return { fingerprint: await sha256Hex(leaf.der), spki: leaf.spki }
}
