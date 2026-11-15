/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for the verification phrase: five words picked by the first five
 * bytes of the SHA-256 of the key, the same for raw bytes, an ArrayBuffer
 * and base64, and different for a different key.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
 */

import { describe, expect, it } from 'vitest'
import {
	PHRASE_LENGTH,
	PHRASE_WORDS,
	verificationPhrase,
	verificationPhraseFromBase64,
} from '../../src/crypto/verificationPhrase.js'

/**
 * The expected phrase, computed independently from the digest.
 *
 * @param {Uint8Array} bytes The key bytes.
 * @return {Promise<string>} The phrase.
 */
async function expected(bytes) {
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
	return [0, 1, 2, 3, 4].map((i) => PHRASE_WORDS[digest[i]]).join(' ')
}

describe('verificationPhrase', () => {
	it('has one distinct lower-case word per byte value', () => {
		expect(PHRASE_WORDS).toHaveLength(256)
		expect(new Set(PHRASE_WORDS).size).toBe(256)
		expect(PHRASE_WORDS.every((w) => /^[a-z]+$/.test(w))).toBe(true)
		expect(Object.isFrozen(PHRASE_WORDS)).toBe(true)
		expect(PHRASE_LENGTH).toBe(5)
	})

	it('pins the phrase of the empty key', async () => {
		// SHA-256('') starts e3 b0 c4 42 98.
		expect(await verificationPhrase(new Uint8Array(0))).toBe(
			[0xe3, 0xb0, 0xc4, 0x42, 0x98].map((b) => PHRASE_WORDS[b]).join(' '),
		)
	})

	it('picks five words from the first five digest bytes', async () => {
		const key = new Uint8Array([1, 2, 3, 4, 5, 6, 7, 8])
		const phrase = await verificationPhrase(key)

		expect(phrase.split(' ')).toHaveLength(PHRASE_LENGTH)
		expect(phrase).toBe(await expected(key))
	})

	it('gives the same phrase for a Uint8Array and its ArrayBuffer', async () => {
		const key = new Uint8Array([9, 8, 7, 6, 5])

		expect(await verificationPhrase(key.buffer)).toBe(await verificationPhrase(key))
	})

	it('gives the same phrase for the base64 form', async () => {
		const key = new Uint8Array([0, 255, 16, 128, 42, 7])
		const b64 = btoa(String.fromCharCode(...key))

		expect(await verificationPhraseFromBase64(b64)).toBe(await verificationPhrase(key))
	})

	it('gives a different phrase for a swapped key', async () => {
		const a = await verificationPhrase(new Uint8Array([1, 2, 3]))
		const b = await verificationPhrase(new Uint8Array([1, 2, 4]))

		expect(a).not.toBe(b)
	})

	it('rejects invalid base64', async () => {
		await expect(verificationPhraseFromBase64('***')).rejects.toBeDefined()
	})
})
