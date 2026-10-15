/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Strict PEM decoding for certificates and keys: exactly one block with an
 * expected label, nothing but whitespace around it, padded base64, and one
 * DER SEQUENCE that fills the whole body. A check that reads a PEM and the
 * key import that reads it again therefore always see the same bytes.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

const BLOCK =
	/^\s*-----BEGIN ([A-Z0-9 ]+)-----([A-Za-z0-9+/=\s]*)-----END \1-----\s*$/
const BASE64 = /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/

/** Why a PEM was refused. */
export class PemError extends Error {
	/**
	 * @param {string} message What is wrong with it.
	 */
	constructor(message) {
		super(message)
		this.name = 'PemError'
	}
}

/**
 * @param {Uint8Array} der DER bytes.
 * @return {number} The size of the first element, header included.
 */
function elementSize(der) {
	if (der.length < 2) {
		throw new PemError('Truncated DER')
	}
	let length = der[1]
	let header = 2
	if (length & 0x80) {
		const count = length & 0x7f
		// DER takes the shortest form: no leading zero, no long form below 0x80.
		if (count === 0 || count > 4 || der.length < 2 + count || der[2] === 0) {
			throw new PemError('Invalid DER length')
		}
		length = 0
		for (let i = 0; i < count; i++) {
			length = length * 256 + der[2 + i]
		}
		if (length < 0x80) {
			throw new PemError('Invalid DER length')
		}
		header += count
	}
	return header + length
}

/**
 * Decode one PEM certificate or key, refusing anything else.
 *
 * @param {string} pem The PEM text.
 * @param {string[]} labels The accepted labels, such as `['CERTIFICATE']`.
 * @return {{label: string, der: Uint8Array}} The block's label and DER bytes.
 * @throws {PemError} Unless the text is one clean block of an accepted label.
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export function decodePem(pem, labels) {
	const match = typeof pem === 'string' ? BLOCK.exec(pem) : null
	if (match === null || !labels.includes(match[1])) {
		throw new PemError(`Expected exactly one ${labels.join(' or ')} PEM block`)
	}
	const body = match[2].replace(/\s/g, '')
	if (body === '' || !BASE64.test(body)) {
		throw new PemError('Invalid base64 in PEM')
	}
	const der = Uint8Array.from(atob(body), (char) => char.charCodeAt(0))
	if (der[0] !== 0x30) {
		throw new PemError('Not a DER SEQUENCE')
	}
	if (elementSize(der) !== der.length) {
		throw new PemError('DER length does not match the PEM body')
	}
	return { label: match[1], der }
}
