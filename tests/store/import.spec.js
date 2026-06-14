/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `useImportStore` (`src/store/modules/import.js`).
 *
 * Locks down the commit pipeline:
 *  - Sensitive fields are encrypted client-side BEFORE the POST — the request
 *    body never contains plaintext (mirrors the link-share / export guard).
 *  - The export → import round-trip: a `.doriath-backup` written by the export
 *    serializer parses back into importable rows that commit + decrypt to the
 *    original plaintext.
 *  - Chunking at 50, one retry then reject on a failed chunk, per-index
 *    failures land in the rejected list, duplicate skip vs import-as-copy.
 *  - No localStorage / sessionStorage / IndexedDB writes (persistence guard).
 *
 * Runs under jsdom for Pinia + WebCrypto (the real RSA encrypt path).
 *
 * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import axios from '@nextcloud/axios'
import { useImportStore, COMMIT_CHUNK_SIZE } from '../../src/store/modules/import.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { generateKeyPair, rsaDecrypt } from '../../src/crypto/rsa.js'
import { encryptBackup } from '../../src/export/backup.js'
import { serializeVault } from '../../src/export/serializer.js'

/**
 * Unlock the session store with a freshly generated RSA-4096 key pair so the
 * real encrypt path runs and the test can decrypt to verify the round-trip.
 *
 * @return {Promise<CryptoKey>} The private key for decryption assertions.
 */
async function unlockSession() {
	const { privateKey, publicKeyPem } = await generateKeyPair()
	const session = useSessionStore()
	session.certificate = publicKeyPem
	session.cryptoKey = privateKey
	return privateKey
}

describe('useImportStore', () => {
	let lsSpy

	beforeEach(() => {
		setActivePinia(createPinia())
		lsSpy = vi.spyOn(Storage.prototype, 'setItem')
	})

	it('encrypts sensitive fields client-side; the request body has no plaintext', async () => {
		const privateKey = await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()

		let body = null
		vi.spyOn(axios, 'post').mockImplementation(async (url, payload) => {
			body = payload
			return { data: { results: payload.items.map((_, i) => ({ index: i, status: 'created', secretId: 's' + i })) } }
		})

		const store = useImportStore()
		store.rows = [
			{ sourceRow: 1, name: 'GitHub', url: 'https://github.com', login: 'octocat', password: 'hunter2-PLAINTEXT', additionalFields: { notes: 'secret-note' }, folder: 'Work/CI', errors: [] },
		]
		await store.commit()

		const serialized = JSON.stringify(body)
		expect(serialized).not.toContain('hunter2-PLAINTEXT')
		expect(serialized).not.toContain('octocat')
		expect(serialized).not.toContain('secret-note')
		// Plaintext-permitted fields only: name, url, folderPath are present.
		expect(body.items[0].name).toBe('GitHub')
		expect(body.items[0].url).toBe('https://github.com')
		expect(body.items[0].folderPath).toEqual(['Work', 'CI'])
		// Ciphertext decrypts back to the original plaintext.
		expect(await rsaDecrypt(body.items[0].key, privateKey)).toBe('hunter2-PLAINTEXT')
		expect(await rsaDecrypt(body.items[0].login, privateKey)).toBe('octocat')
		expect(store.summary.imported).toBe(1)
	})

	it('round-trips an export .doriath-backup into committed, decryptable secrets', async () => {
		const privateKey = await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()

		// Build a real export payload + encrypted backup envelope.
		const decrypted = [
			{ name: 'AWS', url: 'https://aws.test', login: 'admin', key: 'aws-key-123', additionalFields: null, folderId: 'f1', type: 'login' },
		]
		const folders = [{ id: 'f1', name: 'Cloud', parentId: null }]
		const payload = serializeVault(decrypted, folders, { mode: 'vault' })
		const envelope = await encryptBackup(payload, 'restore-pass')

		const captured = []
		vi.spyOn(axios, 'post').mockImplementation(async (url, b) => {
			captured.push(b)
			return { data: { results: b.items.map((_, i) => ({ index: i, status: 'created', secretId: 'x' + i })) } }
		})

		const store = useImportStore()
		await store.parseFile(JSON.stringify(envelope), 'doriath-backup', { passphrase: 'restore-pass' })
		expect(store.rows).toHaveLength(1)
		await store.detectDuplicates()
		await store.commit()

		const item = captured[0].items[0]
		expect(item.name).toBe('AWS')
		expect(item.folderPath).toEqual(['Cloud'])
		expect(await rsaDecrypt(item.key, privateKey)).toBe('aws-key-123')
		expect(store.summary.imported).toBe(1)
	})

	it('chunks at 50 and reports progress', async () => {
		await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()
		const calls = []
		vi.spyOn(axios, 'post').mockImplementation(async (url, b) => {
			calls.push(b.items.length)
			return { data: { results: b.items.map((_, i) => ({ index: i, status: 'created', secretId: 'y' })) } }
		})

		const store = useImportStore()
		store.rows = Array.from({ length: 120 }, (_, i) => ({ sourceRow: i + 1, name: 'S' + i, url: null, login: null, password: 'p', additionalFields: null, folder: '', errors: [] }))
		await store.commit()

		expect(calls).toEqual([COMMIT_CHUNK_SIZE, COMMIT_CHUNK_SIZE, 20])
		expect(store.totalChunks).toBe(3)
		expect(store.committedChunks).toBe(3)
		expect(store.summary.imported).toBe(120)
	})

	it('retries a failed chunk once, then rejects its rows', async () => {
		await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('5xx'))

		const store = useImportStore()
		store.rows = [{ sourceRow: 1, name: 'X', url: null, login: null, password: 'p', additionalFields: null, folder: '', errors: [] }]
		await store.commit()

		expect(store.summary.imported).toBe(0)
		expect(store.summary.rejected).toBe(1)
		expect(store.rejected[0].reason).toBe('server error')
	})

	it('folds per-index server failures into the rejected list', async () => {
		await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()
		vi.spyOn(axios, 'post').mockImplementation(async (url, b) => ({
			data: { results: [
				{ index: 0, status: 'created', secretId: 'ok' },
				{ index: 1, status: 'failed', error: 'bad item' },
			] },
		}))

		const store = useImportStore()
		store.rows = [
			{ sourceRow: 1, name: 'Good', url: null, login: null, password: 'p', additionalFields: null, folder: '', errors: [] },
			{ sourceRow: 2, name: 'Bad', url: null, login: null, password: 'p', additionalFields: null, folder: '', errors: [] },
		]
		await store.commit()

		expect(store.summary.imported).toBe(1)
		expect(store.summary.rejected).toBe(1)
		expect(store.rejected[0].reason).toBe('bad item')
	})

	it('detects duplicates and honours skip vs import-as-copy', async () => {
		const privateKey = await unlockSession()
		// Existing vault has one matching secret (same name+url).
		const secretStore = useSecretStore()
		vi.spyOn(secretStore, 'fetchSecrets').mockImplementation(async () => {
			secretStore.secrets = [{ name: 'GitHub', url: 'https://github.com' }]
		})
		const captured = []
		vi.spyOn(axios, 'post').mockImplementation(async (url, b) => {
			captured.push(b)
			return { data: { results: b.items.map((_, i) => ({ index: i, status: 'created', secretId: 'z' })) } }
		})

		const store = useImportStore()
		store.rows = [
			{ sourceRow: 1, name: 'GitHub', url: 'https://github.com/', login: null, password: 'p', additionalFields: null, folder: '', errors: [] },
			{ sourceRow: 2, name: 'New', url: 'https://new.test', login: null, password: 'p', additionalFields: null, folder: '', errors: [] },
		]
		await store.detectDuplicates()
		expect(store.duplicates).toHaveLength(1)
		expect(store.duplicateResolutions[1]).toBe('skip')

		// Default skip: only the non-duplicate commits.
		await store.commit()
		expect(captured[0].items).toHaveLength(1)
		expect(captured[0].items[0].name).toBe('New')
		expect(store.summary.skippedDuplicates).toBe(1)

		// Now import-as-copy: the duplicate commits with a suffix.
		store.reset()
		store.rows = [{ sourceRow: 1, name: 'GitHub', url: 'https://github.com/', login: null, password: 'p', additionalFields: null, folder: '', errors: [] }]
		await store.detectDuplicates()
		store.resolveDuplicate(1, 'copy')
		captured.length = 0
		await store.commit()
		expect(captured[0].items[0].name).toBe('GitHub (imported)')
		// Sanity: encryption still applied.
		expect(await rsaDecrypt(captured[0].items[0].key, privateKey)).toBe('p')
	})

	it('reset() releases all plaintext rows', async () => {
		const store = useImportStore()
		store.rows = [{ sourceRow: 1, name: 'X', password: 'p', errors: [] }]
		store.reset()
		expect(store.rows).toEqual([])
		expect(store.step).toBe('pick')
		expect(store.summary).toBeNull()
	})

	it('never writes secrets to local or session storage', async () => {
		await unlockSession()
		vi.spyOn(useSecretStore(), 'fetchSecrets').mockResolvedValue()
		vi.spyOn(axios, 'post').mockImplementation(async (url, b) => ({
			data: { results: b.items.map((_, i) => ({ index: i, status: 'created', secretId: 's' })) },
		}))

		const store = useImportStore()
		store.rows = [{ sourceRow: 1, name: 'X', url: null, login: 'u', password: 'plain-secret', additionalFields: null, folder: '', errors: [] }]
		await store.commit()

		for (const call of lsSpy.mock.calls) {
			expect(JSON.stringify(call)).not.toContain('plain-secret')
		}
	})
})
