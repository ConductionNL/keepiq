/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared ephemeral-send crypto (`src/send/sendCrypto.js`), used by the web
 * app's send store and the browser extension.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */

import { describe, expect, it } from 'vitest'
import {
	aesDecrypt,
	fromBase64Url,
	sealPayload,
	sendLink,
} from '../../src/send/sendCrypto.js'

describe('sealPayload and sendLink', () => {
	it('a recipient with the link alone can decrypt the payload', async () => {
		const { encryptedPayload, rawKey } = await sealPayload('wifi: correct horse')
		expect(encryptedPayload).not.toContain('correct horse')

		const link = sendLink(
			'https://cloud.example/apps/keepiq/public',
			'tok/1',
			rawKey,
		)
		expect(link).toMatch(
			/^https:\/\/cloud\.example\/apps\/keepiq\/public\/send\/tok%2F1#k=[\w-]+$/,
		)

		const fragmentKey = link.split('#k=')[1]
		const key = await crypto.subtle.importKey(
			'raw',
			fromBase64Url(fragmentKey),
			{ name: 'AES-GCM' },
			false,
			['decrypt'],
		)
		const plain = await aesDecrypt(key, encryptedPayload)
		expect(new TextDecoder().decode(plain)).toBe('wifi: correct horse')
	})

	it('puts no key in the link of a password send', () => {
		expect(sendLink('https://c.example/public', 't', null)).toBe(
			'https://c.example/public/send/t',
		)
	})

	it('draws a fresh key and IV for every send', async () => {
		const a = await sealPayload('same')
		const b = await sealPayload('same')
		expect(a.encryptedPayload).not.toBe(b.encryptedPayload)
		expect(Array.from(a.rawKey)).not.toEqual(Array.from(b.rawKey))
	})
})
