/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The strict PEM decoder every certificate and key goes through: one block
 * of an expected label and nothing else, so a check and a later import of
 * the same text cannot read different bytes.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { decodePem, PemError } from '../../src/crypto/pem.js'
import { derElement, otherKeyText, toBase64 } from './fixtures/pem-extra-text.js'
import { pemToDer, PUBLIC_KEY_SPKI_PEM } from './fixtures/rsa-fixtures.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB_DER = pemToDer(F.bob)
const NOT_ONE_BLOCK = /Expected exactly one CERTIFICATE PEM block/

/**
 * @param {Uint8Array} der DER bytes.
 * @param {string} label The PEM label.
 * @return {string} One PEM block.
 */
function pem(der, label = 'CERTIFICATE') {
	const body = toBase64(der)
		.match(/.{1,64}/g)
		.join('\n')
	return `-----BEGIN ${label}-----\n${body}\n-----END ${label}-----\n`
}

/**
 * @param {string} body Base64 text, as is.
 * @return {string} One CERTIFICATE block around it.
 */
function block(body) {
	return `-----BEGIN CERTIFICATE-----\n${body}\n-----END CERTIFICATE-----`
}

/**
 * @param {string} text The PEM text.
 * @return {string} The refusal message.
 */
function refusal(text) {
	try {
		decodePem(text, ['CERTIFICATE'])
	} catch (e) {
		expect(e).toBeInstanceOf(PemError)
		return e.message
	}
	throw new Error('the PEM was accepted')
}

describe('decodePem', () => {
	it('decodes one certificate block, with any line endings around it', () => {
		expect(decodePem(F.bob, ['CERTIFICATE'])).toEqual({
			label: 'CERTIFICATE',
			der: BOB_DER,
		})
		expect(
			decodePem(`\r\n  ${F.bob.replaceAll('\n', '\r\n')}  \n`, ['CERTIFICATE'])
				.der,
		).toEqual(BOB_DER)
	})

	it('names the label it found among the accepted ones', () => {
		expect(
			decodePem(PUBLIC_KEY_SPKI_PEM, ['PUBLIC KEY', 'CERTIFICATE']).label,
		).toBe('PUBLIC KEY')
		expect(refusal(PUBLIC_KEY_SPKI_PEM)).toMatch(NOT_ONE_BLOCK)
	})

	it('refuses base64 text before the block', async () => {
		const { text } = await otherKeyText()

		expect(refusal(`${text}\n${F.bob}`)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(`${text}${F.bob}`)).toMatch(NOT_ONE_BLOCK)
	})

	it('refuses two blocks', () => {
		expect(refusal(`${F.bob}\n${F.mallory}`)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(`${F.bob}${F.bob}`)).toMatch(NOT_ONE_BLOCK)
	})

	it('refuses anything but whitespace before or after the block', () => {
		expect(refusal(`junk\n${F.bob}`)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(`${F.bob}\njunk`)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(`${F.bob}\nAAAA`)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(`Bag Attributes\n${F.bob}`)).toMatch(NOT_ONE_BLOCK)
	})

	it('refuses a block whose END label does not match', () => {
		expect(refusal(F.bob.replace('END CERTIFICATE', 'END PUBLIC KEY'))).toMatch(
			NOT_ONE_BLOCK,
		)
	})

	it('refuses characters outside the base64 alphabet', () => {
		expect(refusal(pem(BOB_DER).replace('\n', '\n*'))).toMatch(NOT_ONE_BLOCK)
	})

	it('refuses base64 that is not padded or padded wrongly', () => {
		// MAA= is an empty SEQUENCE, 30 00.
		expect(decodePem(block('MAA='), ['CERTIFICATE']).der).toEqual(
			Uint8Array.of(0x30, 0x00),
		)
		expect(refusal(block('MAA'))).toMatch(/Invalid base64/)
		expect(refusal(block('MAA=='))).toMatch(/Invalid base64/)
		expect(refusal(block('MA=A'))).toMatch(/Invalid base64/)
		expect(refusal(block('MAA=MAA='))).toMatch(/Invalid base64/)
		expect(refusal(block(''))).toMatch(/Invalid base64/)
	})

	it('refuses bytes after the outer DER SEQUENCE', () => {
		expect(refusal(pem(Uint8Array.of(...BOB_DER, 0x05, 0x00)))).toMatch(
			/DER length does not match/,
		)
		expect(refusal(pem(Uint8Array.of(...BOB_DER, ...BOB_DER)))).toMatch(
			/DER length does not match/,
		)
	})

	it('refuses a DER body that is shorter than its header says', () => {
		expect(refusal(pem(BOB_DER.subarray(0, BOB_DER.length - 3)))).toMatch(
			/DER length does not match/,
		)
		expect(refusal(block('MA=='))).toMatch(/Truncated DER/)
	})

	it('refuses a length that is not in its shortest form', () => {
		const five = new Uint8Array(5)
		expect(
			decodePem(pem(Uint8Array.of(0x30, 0x05, ...five)), ['CERTIFICATE']).der,
		).toHaveLength(7)
		expect(
			decodePem(pem(derElement(0x30, new Uint8Array(0x80))), ['CERTIFICATE'])
				.der,
		).toHaveLength(0x83)

		expect(refusal(pem(Uint8Array.of(0x30, 0x81, 0x05, ...five)))).toMatch(
			/Invalid DER length/,
		)
		expect(refusal(pem(Uint8Array.of(0x30, 0x82, 0x00, 0x05, ...five)))).toMatch(
			/Invalid DER length/,
		)
		expect(
			refusal(pem(Uint8Array.of(0x30, 0x84, 0x00, 0x00, 0x00, 0x05, ...five))),
		).toMatch(/Invalid DER length/)
	})

	it('refuses DER that is not a SEQUENCE', () => {
		expect(refusal(pem(derElement(0x02, Uint8Array.of(5))))).toMatch(
			/Not a DER SEQUENCE/,
		)
	})

	it('refuses what is not text', () => {
		expect(refusal(undefined)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(null)).toMatch(NOT_ONE_BLOCK)
		expect(refusal(BOB_DER)).toMatch(NOT_ONE_BLOCK)
	})
})
