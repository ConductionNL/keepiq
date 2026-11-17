/**
 * The recovery kit's crypto and requests (crypto-recovery-kit 2.1): the code,
 * the wrap and unwrap round trip, a wrong code, and that no request body or
 * header carries the code or the unwrapped private key.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import {
	generateRecoveryCode,
	normaliseRecoveryCode,
	RECOVERY_KIT_VERSION,
	recoveryKitText,
	unwrapWithRecoveryCode,
	wrapWithRecoveryCode,
} from '../../src/crypto/recoveryKit.js'
import { pemToDer, PRIVATE_KEY_PKCS8_PEM, PUBLIC_KEY_SPKI_PEM } from './fixtures/rsa-fixtures.js'

const calls = []
const responses = {}

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/axios', () => {
	const record = (verb) => async (url, body, config) => {
		calls.push({ verb, url, body, config })
		return { data: responses[verb + ' ' + url] ?? {} }
	}
	return { default: { get: record('get'), put: record('put'), post: record('post'), delete: record('delete') } }
})

const { createRecoveryKit, recoverWithCode } = await import('../../src/services/recoveryKit.js')

/**
 * Everything a request sent, as one string.
 *
 * @return {string}
 */
function sent() {
	return JSON.stringify(calls.map((c) => [c.url, c.body ?? null, c.config ?? null]))
}

beforeEach(() => {
	calls.length = 0
	for (const key of Object.keys(responses)) {
		delete responses[key]
	}
})

describe('recovery code', () => {
	it('is 32 base32 characters in groups of four, new every time', () => {
		const code = generateRecoveryCode()

		expect(code).toMatch(/^([0-9A-HJKMNP-TV-Z]{4}-){7}[0-9A-HJKMNP-TV-Z]{4}$/)
		expect(generateRecoveryCode()).not.toBe(code)
	})

	it('reads a code as printed, whatever the case, spacing and look-alike letters', () => {
		expect(normaliseRecoveryCode('0123-4567-89ab-cdef-ghjk-mnpq-rstv-wxyz')).toBe('0123456789ABCDEFGHJKMNPQRSTVWXYZ')
		expect(normaliseRecoveryCode('o1l1 4567 89AB CDEF GHJK MNPQ RSTV WXYZ')).toBe('0111456789ABCDEFGHJKMNPQRSTVWXYZ')
		expect(normaliseRecoveryCode('too short')).toBe('')
		expect(normaliseRecoveryCode('UUUU-4567-89AB-CDEF-GHJK-MNPQ-RSTV-WXYZ')).toBe('')
	})
})

describe('wrap and unwrap', () => {
	it('gives the private key back with the code, also as typed differently', async () => {
		const code = generateRecoveryCode()
		const copy = await wrapWithRecoveryCode(PRIVATE_KEY_PKCS8_PEM, code)

		expect(JSON.parse(copy.kdf)).toMatchObject({ version: RECOVERY_KIT_VERSION, kdf: 'Argon2id' })
		expect(copy.wrappedKey).not.toContain('PRIVATE KEY')
		expect(await unwrapWithRecoveryCode(copy, code.toLowerCase().replace(/-/g, ' '))).toBe(PRIVATE_KEY_PKCS8_PEM)
	})

	it('opens nothing with a wrong or malformed code, or a copy it cannot read', async () => {
		const copy = await wrapWithRecoveryCode(PRIVATE_KEY_PKCS8_PEM, generateRecoveryCode())

		await expect(unwrapWithRecoveryCode(copy, generateRecoveryCode())).rejects.toThrow('does not open')
		await expect(unwrapWithRecoveryCode(copy, 'not a code')).rejects.toThrow('not a recovery code')
		await expect(unwrapWithRecoveryCode({ ...copy, kdf: '{"version":2}' }, generateRecoveryCode())).rejects.toThrow('cannot be read')
	})

	it('refuses to wrap without a key or a code', async () => {
		await expect(wrapWithRecoveryCode('', generateRecoveryCode())).rejects.toThrow()
		await expect(wrapWithRecoveryCode(PRIVATE_KEY_PKCS8_PEM, 'x')).rejects.toThrow()
	})

	it('writes the kit file with the code, the account and the date', () => {
		const text = recoveryKitText({ code: 'ABCD-EFGH', account: 'alice', createdAt: '2026-11-14' }, (app, s) => s)

		expect(text).toContain('Recovery code: ABCD-EFGH')
		expect(text).toContain('Account: alice')
		expect(text).toContain('Made on: 2026-11-14')
	})
})

describe('requests', () => {
	it('creates a kit by sending only the wrapped copy and its parameters', async () => {
		const envelope = await encryptPrivateKey(PRIVATE_KEY_PKCS8_PEM, 'master password')
		responses['put /apps/keepiq/api/v1/recovery-kit'] = { kit: { suiteId: 's-1' } }

		const { code, kit } = await createRecoveryKit(envelope, 'master password')

		expect(kit).toEqual({ suiteId: 's-1' })
		expect(calls.map((c) => c.verb + ' ' + c.url)).toEqual(['put /apps/keepiq/api/v1/recovery-kit'])
		expect(Object.keys(calls[0].body).sort()).toEqual(['kdf', 'wrappedKey'])
		expect(sent()).not.toContain(code)
		expect(sent()).not.toContain(normaliseRecoveryCode(code))
		expect(sent()).not.toContain('PRIVATE KEY')
		expect(await unwrapWithRecoveryCode(calls[0].body, code)).toBe(PRIVATE_KEY_PKCS8_PEM)
	})

	it('recovers: a new envelope for the new password, proven with the key, then the kit is spent', async () => {
		const code = generateRecoveryCode()
		const copy = await wrapWithRecoveryCode(PRIVATE_KEY_PKCS8_PEM, code)
		responses['get /apps/keepiq/api/v1/recovery-kit/copy'] = { suiteId: 's-1', ...copy }
		responses['get /apps/keepiq/api/v1/suites/s-1/proof-challenge'] = { nonce: 'nonce-1' }

		await recoverWithCode(code, 'new master password')

		expect(calls.map((c) => c.verb + ' ' + c.url)).toEqual([
			'get /apps/keepiq/api/v1/recovery-kit/copy',
			'get /apps/keepiq/api/v1/suites/s-1/proof-challenge',
			'put /apps/keepiq/api/v1/suites/s-1/private-key',
			'post /apps/keepiq/api/v1/recovery-kit/spent',
		])
		expect(calls[1].body).toEqual({ params: { purpose: 'update-private-key' } })
		expect(sent()).not.toContain(code)
		expect(sent()).not.toContain(normaliseRecoveryCode(code))
		expect(sent()).not.toContain('PRIVATE KEY')
		expect(sent()).not.toContain('new master password')

		const put = calls[2]
		const { decryptPrivateKey } = await import('../../src/crypto/aes.js')
		expect(await decryptPrivateKey(put.body.encryptedPrivateKey, 'new master password')).toBe(PRIVATE_KEY_PKCS8_PEM)
		expect(put.config.headers['X-Keepiq-Key-Proof-Nonce']).toBe('nonce-1')
		const publicKey = await crypto.subtle.importKey('spki', pemToDer(PUBLIC_KEY_SPKI_PEM), { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['verify'])
		const { signedMessageBytes } = await import('../../src/crypto/reauth.js')
		const signature = Uint8Array.from(atob(put.config.headers['X-Keepiq-Key-Proof']), (ch) => ch.charCodeAt(0))
		expect(await crypto.subtle.verify('RSASSA-PKCS1-v1_5', publicKey, signature, await signedMessageBytes('nonce-1', [put.body.encryptedPrivateKey]))).toBe(true)
	})

	it('changes nothing when the code is wrong', async () => {
		const copy = await wrapWithRecoveryCode(PRIVATE_KEY_PKCS8_PEM, generateRecoveryCode())
		responses['get /apps/keepiq/api/v1/recovery-kit/copy'] = { suiteId: 's-1', ...copy }

		await expect(recoverWithCode(generateRecoveryCode(), 'new master password')).rejects.toThrow('does not open')
		expect(calls.map((c) => c.verb + ' ' + c.url)).toEqual(['get /apps/keepiq/api/v1/recovery-kit/copy'])
	})
})
