/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The session store's unlock paths with real WebCrypto: the master-password
 * unlock from the suite API (and its no-suite and two-factor refusals), the
 * shared blob unlock and the AES metadata key it derives, the passkey raw-key
 * unlock, the officer private-key unlock, the team folder auto-confirm start
 * and stop, the lock hooks, and the lock clearing every key.
 *
 * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-offline-unlock
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	deriveAesKey,
	deriveUnlockKeyRaw,
	encryptPrivateKey,
} from '../../src/crypto/aes.js'
import { decodeEnvelope } from '../../src/crypto/envelope.js'
import {
	DEFAULT_TIMEOUT_CHOICE,
	onVaultLock,
	TIMEOUT_CHOICES,
	useSessionStore,
} from '../../src/store/modules/session.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const SUITES_URL = '/apps/keepiq/api/v1/suites'
const PASSWORD = 'correct horse battery staple'
const CERT = '-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----'

let keyPair
let privateKeyPem
let envelope

/**
 * Wrap DER bytes as PEM.
 *
 * @param {ArrayBuffer} der The DER bytes.
 * @param {string} label The PEM label.
 * @return {string}
 */
function toPem(der, label) {
	const b64 = btoa(String.fromCharCode(...new Uint8Array(der)))
	const lines = b64.match(/.{1,64}/g).join('\n')
	return `-----BEGIN ${label}-----\n${lines}\n-----END ${label}-----`
}

/**
 * Whether `privateKey` opens a message sealed to the test key pair.
 *
 * @param {CryptoKey} privateKey The key under test.
 * @return {Promise<string>} The opened text.
 */
async function openWith(privateKey) {
	const sealed = await crypto.subtle.encrypt(
		{ name: 'RSA-OAEP' },
		keyPair.publicKey,
		new TextEncoder().encode('ping'),
	)
	const opened = await crypto.subtle.decrypt(
		{ name: 'RSA-OAEP' },
		privateKey,
		sealed,
	)
	return new TextDecoder().decode(opened)
}

/**
 * Encrypt with `encryptKey`, decrypt with `decryptKey`: proves both are the
 * same AES-GCM key.
 *
 * @param {CryptoKey} encryptKey The key that encrypts.
 * @param {CryptoKey} decryptKey The key that decrypts.
 * @return {Promise<string>}
 */
async function aesRoundTrip(encryptKey, decryptKey) {
	const iv = crypto.getRandomValues(new Uint8Array(12))
	const ct = await crypto.subtle.encrypt(
		{ name: 'AES-GCM', iv },
		encryptKey,
		new TextEncoder().encode('metadata'),
	)
	const pt = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		decryptKey,
		ct,
	)
	return new TextDecoder().decode(pt)
}

/**
 * The suites list the API returns.
 *
 * @param {object} active Overrides for the active suite.
 * @return {Array<object>}
 */
function suites(active = {}) {
	return [
		{ id: 'old', status: 'retired', privateKey: 'x', certificate: 'y' },
		{
			id: 'suite-7',
			status: 'active',
			privateKey: envelope,
			certificate: CERT,
			...active,
		},
	]
}

beforeAll(async () => {
	keyPair = await crypto.subtle.generateKey(
		{
			name: 'RSA-OAEP',
			modulusLength: 2048,
			publicExponent: new Uint8Array([1, 0, 1]),
			hash: 'SHA-256',
		},
		true,
		['encrypt', 'decrypt'],
	)
	privateKeyPem = toPem(
		await crypto.subtle.exportKey('pkcs8', keyPair.privateKey),
		'PRIVATE KEY',
	)
	envelope = await encryptPrivateKey(privateKeyPem, PASSWORD)
})

describe('session store unlock paths', () => {
	let startAutoConfirm

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		startAutoConfirm = vi
			.spyOn(useTeamFolderStore(), 'startAutoConfirm')
			.mockResolvedValue(undefined)
	})

	it('starts locked with the ten-minute default', () => {
		const store = useSessionStore()
		expect(store.isLocked).toBe(true)
		expect(store.timeout).toBe(600000)
		expect(store.timeoutChoice).toBe(DEFAULT_TIMEOUT_CHOICE)
		expect(TIMEOUT_CHOICES['10min']).toBe(600000)
		expect(Object.isFrozen(TIMEOUT_CHOICES)).toBe(true)
	})

	it('unlocks with the master password from the active suite', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: suites() })
		const store = useSessionStore()
		store.lastActivity = 0

		await store.unlock(PASSWORD)

		expect(get).toHaveBeenCalledWith(SUITES_URL)
		expect(store.isLocked).toBe(false)
		expect(await openWith(store.cryptoKey)).toBe('ping')
		expect(store.cryptoKey.extractable).toBe(false)
		expect(store.encryptedPrivateKey).toBe(envelope)
		expect(store.certificate).toBe(CERT)
		expect(store.suiteId).toBe('suite-7')
		expect(store.lastActivity).toBeGreaterThan(0)
		expect(startAutoConfirm).toHaveBeenCalledTimes(1)

		// The AES metadata key is the one the envelope salt + password give.
		const { salt } = decodeEnvelope(envelope)
		const expected = await deriveAesKey(PASSWORD, salt)
		expect(await aesRoundTrip(store.aesKey, expected)).toBe('metadata')
	})

	it('refuses a wrong master password and stays locked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: suites() })
		const store = useSessionStore()

		await expect(store.unlock('wrong')).rejects.toThrow()

		expect(store.isLocked).toBe(true)
		expect(store.aesKey).toBeNull()
		expect(store.suiteId).toBeNull()
		expect(startAutoConfirm).not.toHaveBeenCalled()
	})

	it('throws when no suite is active', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'old', status: 'retired' }],
		})
		const store = useSessionStore()

		await expect(store.unlock(PASSWORD)).rejects.toThrow(
			'No active EncryptionSuite found',
		)
		expect(store.isLocked).toBe(true)
	})

	it('reports a withheld key as the two-factor code, not a wrong password', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: suites({ unlockBlocked: 'two_factor_required' }),
		})
		const store = useSessionStore()

		const error = await store.unlock(PASSWORD).catch((e) => e)

		expect(error).toBeInstanceOf(Error)
		expect(error.message).toBe('two_factor_required')
		expect(error.code).toBe('two_factor_required')
		expect(store.isLocked).toBe(true)
	})

	it('unlocks from a blob with no API call', async () => {
		const get = vi.spyOn(axios, 'get')
		const store = useSessionStore()

		await store.unlockFromBlob({
			privateKeyEnvelope: envelope,
			certificate: CERT,
			suiteId: 'offline-suite',
			masterPassword: PASSWORD,
		})

		expect(get).not.toHaveBeenCalled()
		expect(await openWith(store.cryptoKey)).toBe('ping')
		expect(store.suiteId).toBe('offline-suite')
		expect(store.certificate).toBe(CERT)
		expect(store.encryptedPrivateKey).toBe(envelope)
	})

	it('unlocks with the raw unlock key and uses it as the AES key', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: suites() })
		const { salt } = decodeEnvelope(envelope)
		const raw = await deriveUnlockKeyRaw(PASSWORD, salt)
		const store = useSessionStore()
		store.lastActivity = 0

		await store.unlockWithRawKey(raw)

		expect(get).toHaveBeenCalledWith(SUITES_URL)
		expect(await openWith(store.cryptoKey)).toBe('ping')
		expect(store.aesKey.extractable).toBe(false)
		expect(store.aesKey.algorithm.name).toBe('AES-GCM')
		// Same key as the master-password path derives.
		const expected = await deriveAesKey(PASSWORD, salt)
		expect(await aesRoundTrip(store.aesKey, expected)).toBe('metadata')
		expect(store.encryptedPrivateKey).toBe(envelope)
		expect(store.certificate).toBe(CERT)
		expect(store.suiteId).toBe('suite-7')
		expect(store.lastActivity).toBeGreaterThan(0)
		expect(startAutoConfirm).toHaveBeenCalledTimes(1)
	})

	it('refuses a raw key when no suite is active', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const store = useSessionStore()

		await expect(
			store.unlockWithRawKey(new Uint8Array(32)),
		).rejects.toThrow('No active EncryptionSuite found')
		expect(store.isLocked).toBe(true)
	})

	it('refuses a raw key that does not open the envelope', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: suites() })
		const store = useSessionStore()

		await expect(
			store.unlockWithRawKey(new Uint8Array(32).fill(1)),
		).rejects.toThrow()
		expect(store.isLocked).toBe(true)
		expect(store.aesKey).toBeNull()
	})

	it('unlocks from a recovered private key with the offline cache off', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: suites() })
		const store = useSessionStore()
		store.aesKey = {}
		store.lastActivity = 0

		await store.unlockWithPrivateKeyPem(privateKeyPem)

		expect(get).toHaveBeenCalledWith(SUITES_URL)
		expect(await openWith(store.cryptoKey)).toBe('ping')
		expect(store.aesKey).toBeNull()
		expect(store.encryptedPrivateKey).toBe(envelope)
		expect(store.certificate).toBe(CERT)
		expect(store.suiteId).toBe('suite-7')
		expect(store.lastActivity).toBeGreaterThan(0)
		// The officer path starts no background confirmation.
		expect(startAutoConfirm).not.toHaveBeenCalled()
	})

	it('refuses a recovered key when no suite is active', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'x', status: 'pending' }],
		})
		const store = useSessionStore()

		await expect(
			store.unlockWithPrivateKeyPem(privateKeyPem),
		).rejects.toThrow('No active EncryptionSuite found')
		expect(store.isLocked).toBe(true)
	})
})

describe('session store afterUnlock', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('swallows a rejected auto-confirm', async () => {
		const start = vi
			.spyOn(useTeamFolderStore(), 'startAutoConfirm')
			.mockRejectedValue(new Error('no members'))
		const store = useSessionStore()

		expect(() => store.afterUnlock()).not.toThrow()
		expect(start).toHaveBeenCalledTimes(1)
		// Let the swallowed rejection settle; an unhandled one fails the run.
		await Promise.resolve()
	})

	it('swallows an auto-confirm that throws synchronously', () => {
		vi.spyOn(useTeamFolderStore(), 'startAutoConfirm').mockImplementation(
			() => {
				throw new Error('sync')
			},
		)
		const store = useSessionStore()

		expect(() => store.afterUnlock()).not.toThrow()
	})
})

describe('session store lock', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	/**
	 * A store holding stand-in keys.
	 *
	 * @return {object}
	 */
	function filledStore() {
		const store = useSessionStore()
		store.cryptoKey = {}
		store.aesKey = {}
		store.encryptedPrivateKey = 'env'
		store.certificate = CERT
		store.suiteId = 'suite-7'
		return store
	}

	it('stops auto-confirm and clears every key', () => {
		const stop = vi
			.spyOn(useTeamFolderStore(), 'stopAutoConfirm')
			.mockImplementation(() => {})
		const store = filledStore()

		store.lock()

		expect(stop).toHaveBeenCalledTimes(1)
		expect(store.isLocked).toBe(true)
		expect(store.cryptoKey).toBeNull()
		expect(store.aesKey).toBeNull()
		expect(store.encryptedPrivateKey).toBeNull()
		expect(store.certificate).toBeNull()
		expect(store.suiteId).toBeNull()
	})

	it('still locks when stopping auto-confirm throws', () => {
		vi.spyOn(useTeamFolderStore(), 'stopAutoConfirm').mockImplementation(
			() => {
				throw new Error('stop failed')
			},
		)
		const store = filledStore()

		expect(() => store.lock()).not.toThrow()
		expect(store.isLocked).toBe(true)
		expect(store.aesKey).toBeNull()
	})

	it('runs each registered hook once, past a failing one', () => {
		const failing = vi.fn(() => {
			throw new Error('hook failed')
		})
		const hook = vi.fn()
		onVaultLock(failing)
		onVaultLock(hook)
		onVaultLock(hook)
		onVaultLock('not a function')
		const store = filledStore()

		expect(() => store.lock()).not.toThrow()

		expect(failing).toHaveBeenCalledTimes(1)
		expect(hook).toHaveBeenCalledTimes(1)
		expect(store.isLocked).toBe(true)
	})

	it('checkTimeout leaves a locked vault alone', () => {
		const stop = vi.spyOn(useTeamFolderStore(), 'stopAutoConfirm')
		const store = useSessionStore()
		store.lastActivity = 0

		store.checkTimeout()

		expect(stop).not.toHaveBeenCalled()
	})

	it('checkTimeout keeps an unlocked vault open inside the timeout', () => {
		const store = useSessionStore()
		store.cryptoKey = {}
		store.lastActivity = Date.now()

		store.checkTimeout()

		expect(store.isLocked).toBe(false)
	})

	it('updateActivity stamps the current time', () => {
		vi.spyOn(Date, 'now').mockReturnValue(1234567)
		const store = useSessionStore()

		store.updateActivity()

		expect(store.lastActivity).toBe(1234567)
	})
})
