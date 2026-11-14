import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { suiteKey } from '@/src/accounts/store'
import { importPublicKey, pemToPkcs8, rsaEncrypt, toBase64 } from '@/src/crypto'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { provenCertificateKey } from '@/src/vault/certificate'
import { clearKey, generatorHistoryKey, generatorSealedKey, putKey, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { addHistory, clearHistory, listHistory, openHistory, sealHistory } from './history'
import { HISTORY_LIMIT } from './options'

beforeEach(() => {
	fakeBrowser.reset()
})

describe('generator history', () => {
	it('lists newest first with the time it was added', async () => {
		await addHistory('a', { value: 'one', kind: 'password' }, undefined, new Date('2026-10-01T10:00:00Z'))
		await addHistory('a', { value: 'two', kind: 'passphrase' }, undefined, new Date('2026-10-01T11:00:00Z'))
		expect(await listHistory('a')).toEqual([
			{ value: 'two', kind: 'passphrase', createdAt: '2026-10-01T11:00:00.000Z' },
			{ value: 'one', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z' },
		])
		expect(await listHistory('b')).toEqual([])
	})

	it(`keeps the newest ${HISTORY_LIMIT}`, async () => {
		for (let i = 0; i < HISTORY_LIMIT + 5; i++) await addHistory('a', { value: `v${i}`, kind: 'password' })
		const entries = await listHistory('a')
		expect(entries).toHaveLength(HISTORY_LIMIT)
		expect(entries[0]!.value).toBe(`v${HISTORY_LIMIT + 4}`)
		expect(entries.at(-1)!.value).toBe('v5')
	})

	it('records the same value twice in a row once', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		await addHistory('a', { value: 'one', kind: 'password' })
		await addHistory('a', { value: 'two', kind: 'password' })
		await addHistory('a', { value: 'one', kind: 'password' })
		expect((await listHistory('a')).map((e) => e.value)).toEqual(['one', 'two', 'one'])
	})

	it('drops no add when several arrive at once', async () => {
		await Promise.all(['a', 'b', 'c', 'd'].map((value) => addHistory('x', { value, kind: 'username' })))
		expect((await listHistory('x')).map((e) => e.value).sort()).toEqual(['a', 'b', 'c', 'd'])
	})

	it('lives in session storage only', async () => {
		const set = vi.spyOn(browser.storage.local, 'set')
		await addHistory('a', { value: 'secret', kind: 'password' })
		expect(set).not.toHaveBeenCalled()
		expect(await browser.storage.local.get(null)).toEqual({})
		expect(await browser.storage.session.get(generatorHistoryKey('a'))).toHaveProperty(generatorHistoryKey('a'))
	})

	it('clears, and goes with the key on lock and logout', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		await clearHistory('a')
		expect(await listHistory('a')).toEqual([])
		await addHistory('a', { value: 'two', kind: 'password' })
		await clearKey('a')
		expect(await listHistory('a')).toEqual([])
	})

	it('does not write the history back when a lock clears it during an add', async () => {
		await addHistory('a', { value: 'one', kind: 'password' })
		const get = browser.storage.session.get.bind(browser.storage.session)
		let cleared = false
		vi.spyOn(browser.storage.session, 'get').mockImplementation(async (keys) => {
			const values = await get(keys)
			if (!cleared) {
				cleared = true
				await clearKey('a')
			}
			return values
		})
		expect(await addHistory('a', { value: 'two', kind: 'password' })).toEqual([])
		expect(cleared).toBe(true)
		expect(await browser.storage.session.get(null)).toEqual({})
		// The next add after the lock records again.
		expect(await addHistory('a', { value: 'three', kind: 'password' })).toHaveLength(1)
	})

	it('does not write an add whose request arrived before a lock', async () => {
		const epoch = sessionEpoch('a')
		await clearKey('a')
		expect(await addHistory('a', { value: 'one', kind: 'password' }, epoch)).toEqual([])
		expect(await browser.storage.session.get(null)).toEqual({})
	})

	it('reads damaged storage as empty and skips invalid entries', async () => {
		await sessionValues.set({ [generatorHistoryKey('a')]: '{not json' })
		expect(await listHistory('a')).toEqual([])
		await sessionValues.set({ [generatorHistoryKey('a')]: JSON.stringify([{ value: 'ok', kind: 'password', createdAt: 'x' }, { value: 1 }, { value: 'v', kind: 'pin', createdAt: 'x' }]) })
		expect((await listHistory('a')).map((e) => e.value)).toEqual(['ok'])
	})
})

describe('sealed generator history', () => {
	const unlock = () => putKey('a', toBase64(pemToPkcs8(envelope.privateKeyPem)))
	const plant = async (values: unknown[]) => sessionValues.set({ [generatorSealedKey('a')]: JSON.stringify(values) })
	const sealedTo = async (entry: object) => rsaEncrypt(JSON.stringify(entry), await importPublicKey(suiteRow.certificate))

	beforeEach(async () => {
		await browser.storage.local.set({ [provenCertificateKey('a')]: suiteRow.certificate })
	})

	it('records nothing without a proven certificate, whatever the cached suite holds', async () => {
		await browser.storage.local.remove(provenCertificateKey('a'))
		await browser.storage.local.set({ [suiteKey('a')]: suiteRow })
		await sealHistory('a', { value: 'lost', kind: 'password' })
		expect(await sessionValues.get(generatorSealedKey('a'))).toBeUndefined()
	})

	it('seals to the proven certificate, not a swapped cached one', async () => {
		const { publicKey } = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt'])
		const spki = toBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)))
		await browser.storage.local.set({ [suiteKey('a')]: { ...suiteRow, certificate: `-----BEGIN PUBLIC KEY-----\n${spki}\n-----END PUBLIC KEY-----` } })
		await sealHistory('a', { value: 'mine', kind: 'password' })
		await unlock()
		expect((await openHistory('a')).map((e) => e.value)).toEqual(['mine'])
	})

	it('opens into the history by time, a repeat of the value before it recorded once', async () => {
		await addHistory('a', { value: 'same', kind: 'password' }, undefined, new Date('2026-10-01T10:00:00Z'))
		await sealHistory('a', { value: 'same', kind: 'password' }, undefined, new Date('2026-10-01T11:00:00Z'))
		await sealHistory('a', { value: 'new', kind: 'username' }, undefined, new Date('2026-10-01T12:00:00Z'))
		await unlock()
		expect(await openHistory('a')).toEqual([
			{ value: 'new', kind: 'username', createdAt: '2026-10-01T12:00:00.000Z' },
			{ value: 'same', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z' },
		])
	})

	it(`keeps the newest ${HISTORY_LIMIT} sealed`, async () => {
		for (let i = 0; i < HISTORY_LIMIT + 2; i++) await sealHistory('a', { value: `v${i}`, kind: 'password' })
		expect(JSON.parse((await sessionValues.get(generatorSealedKey('a')))!)).toHaveLength(HISTORY_LIMIT)
	})

	it(`keeps the newest ${HISTORY_LIMIT} once opened into the history`, async () => {
		for (let i = 0; i < HISTORY_LIMIT; i++) await addHistory('a', { value: `v${i}`, kind: 'password' }, undefined, new Date('2026-10-01T10:00:00Z'))
		await sealHistory('a', { value: 'sealed', kind: 'password' }, undefined, new Date('2026-10-01T11:00:00Z'))
		await unlock()
		const opened = await openHistory('a')
		expect(opened).toHaveLength(HISTORY_LIMIT)
		expect([opened[0]!.value, opened.at(-1)!.value]).toEqual(['sealed', 'v1'])
	})

	it('keeps the order of values sealed in the same millisecond', async () => {
		const now = new Date('2026-10-01T10:00:00Z')
		await sealHistory('a', { value: 'first', kind: 'password' }, undefined, now)
		await sealHistory('a', { value: 'second', kind: 'password' }, undefined, now)
		await unlock()
		expect((await openHistory('a')).map((e) => e.value)).toEqual(['second', 'first'])
	})

	it('reads damaged sealed storage as empty', async () => {
		await sessionValues.set({ [generatorSealedKey('a')]: '{not json' })
		await unlock()
		expect(await openHistory('a')).toEqual([])
	})

	it(`opens only the newest ${HISTORY_LIMIT} sealed values`, async () => {
		await plant([await sealedTo({ value: 'oldest', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z' }), ...Array<string>(HISTORY_LIMIT).fill('garbage')])
		await unlock()
		expect(await openHistory('a')).toEqual([])
	})

	it('takes only the value, kind and time from an opened entry', async () => {
		await plant([await sealedTo({ value: 'v', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z', extra: 'x' })])
		await unlock()
		expect(await openHistory('a')).toEqual([{ value: 'v', kind: 'password', createdAt: '2026-10-01T10:00:00.000Z' }])
	})

	it('seals nothing when a lock lands during the seal', async () => {
		const epoch = sessionEpoch('a')
		await clearKey('a')
		await sealHistory('a', { value: 'late', kind: 'password' }, epoch)
		expect(await sessionValues.get(generatorSealedKey('a'))).toBeUndefined()
	})

	it('opens nothing for a request from before a lock, even after the next unlock', async () => {
		await sealHistory('a', { value: 'kept', kind: 'password' })
		await unlock()
		const before = sessionEpoch('a')
		await clearKey('a', { sealed: 'keep' })
		await unlock()
		expect(await openHistory('a', before)).toEqual([])
		expect(await sessionValues.get(generatorHistoryKey('a'))).toBeUndefined()
		expect((await openHistory('a')).map((e) => e.value)).toEqual(['kept'])
		expect(await openHistory('a', before)).toEqual([])
	})

	it('keeps an add that races an open', async () => {
		await sealHistory('a', { value: 'sealed', kind: 'password' }, undefined, new Date('2026-10-01T10:00:00Z'))
		await unlock()
		await Promise.all([openHistory('a'), addHistory('a', { value: 'added', kind: 'password' }, undefined, new Date('2026-10-01T11:00:00Z'))])
		expect((await listHistory('a')).map((e) => e.value)).toEqual(['added', 'sealed'])
	})

	it('opens once when two requests race', async () => {
		await sealHistory('a', { value: 'once', kind: 'password' })
		await unlock()
		const [first, second] = await Promise.all([openHistory('a'), openHistory('a')])
		expect(first.map((e) => e.value)).toEqual(['once'])
		expect(second.map((e) => e.value)).toEqual(['once'])
	})
})

describe('generator history without storage.session (Firefox < 115)', () => {
	it('keeps the values in memory', async () => {
		vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
		await addHistory('a', { value: 'one', kind: 'password' })
		expect(await listHistory('a')).toHaveLength(1)
		expect(await browser.storage.local.get(null)).toEqual({})
		await clearKey('a')
		expect(await listHistory('a')).toEqual([])
	})

	it('keeps the sealed values in memory', async () => {
		vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
		await browser.storage.local.set({ [provenCertificateKey('a')]: suiteRow.certificate })
		await sealHistory('a', { value: 'sealed', kind: 'password' })
		expect(JSON.stringify(await browser.storage.local.get(null))).not.toContain('"sealed"')
		await putKey('a', toBase64(pemToPkcs8(envelope.privateKeyPem)))
		expect((await openHistory('a')).map((e) => e.value)).toEqual(['sealed'])
	})
})
