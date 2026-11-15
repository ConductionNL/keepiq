/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * DER OBJECT IDENTIFIER decoding, shared by the certificate display parser
 * and the federated certificate verifier.
 */

/**
 * Decode the content of an OID element to dotted notation: base-128 arcs,
 * the first byte holding the first two arcs as 40 * first + second.
 *
 * @param {Uint8Array} bytes The DER buffer.
 * @param {{start: number, end: number}} element The OID element's content range.
 * @return {string} Dotted OID.
 */
export function decodeOid(bytes, element) {
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
