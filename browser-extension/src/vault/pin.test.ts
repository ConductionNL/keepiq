import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { fromBase64, importPublicKey, pemToPkcs8, rsaEncrypt, toBase64 } from '@/src/crypto'
import { decodePem, extractSpki } from '@/src/crypto/rsa'
import { envelope, suiteRow } from '@/src/testing/vectors'
import {
	forgetPin, hasPin, hasSeal, openSeal, pinGeneration, pinKey, pinSealKey, readPin, sealPin, storePin, storePinCopies, unwrapPin, wrapPin, WrongPin,
} from './pin'

const pkcs8 = pemToPkcs8(envelope.privateKeyPem)
const suite = { id: suiteRow.id, unlockKeyEpoch: suiteRow.unlockKeyEpoch }

beforeEach(() => {
	fakeBrowser.reset()
})

describe('wrapping', () => {
	it('gives back the key bytes for the right PIN', async () => {
		const record = await wrapPin('2468', pkcs8, suite)
		expect(record).toMatchObject({ suiteId: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch, failures: 0 })
		expect(record.envelope).not.toContain(envelope.privateKeyPem.slice(40, 80))
		expect(await unwrapPin(record, '2468')).toEqual(pkcs8)
	})

	it('fails a wrong PIN', async () => {
		const record = await wrapPin('2468', pkcs8, suite)
		await expect(unwrapPin(record, '2469')).rejects.toBeInstanceOf(WrongPin)
	})

	it('salts every wrap anew', async () => {
		const [a, b] = await Promise.all([wrapPin('2468', pkcs8, suite), wrapPin('2468', pkcs8, suite)])
		expect(a.envelope).not.toBe(b.envelope)
	})
})

describe('the seal', () => {
	const random = (length: number) => toBase64(crypto.getRandomValues(new Uint8Array(length)))

	it('gives back the PIN for the private key, account, suite and epoch it was made with', async () => {
		const seal = await sealPin('a', '246813', pkcs8, suite)
		expect(seal).toMatchObject({ v: 1, suiteId: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch })
		expect(fromBase64(seal.salt)).toHaveLength(16)
		expect(JSON.stringify(seal)).not.toContain('246813')
		expect(await openSeal('a', JSON.parse(JSON.stringify(seal)), pkcs8, suite)).toBe('246813')
	})

	it('hides the PIN\'s length: short and long PINs seal to the same size', async () => {
		const short = await sealPin('a', '246813', pkcs8, suite)
		const long = await sealPin('a', 'correct horse battery staple 2468', pkcs8, suite)
		expect(fromBase64(short.ciphertext).length).toBe(fromBase64(long.ciphertext).length)
	})

	it.each(['😀a😀b😀c', 'x'.repeat(62), 'x'.repeat(200)])('gives back a PIN of any length or script (%s)', async (pin) => {
		expect(await openSeal('a', await sealPin('a', pin, pkcs8, suite), pkcs8, suite)).toBe(pin)
	})

	it('refuses a PIN encrypted to the public key with RSA-OAEP', async () => {
		const forged = { v: 1, salt: random(16), iv: random(12), ciphertext: await rsaEncrypt('111111', await importPublicKey(envelope.certificatePem)), suiteId: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch }
		expect(await openSeal('a', forged, pkcs8, suite)).toBeNull()
	})

	it('refuses a seal made with a key derived from the public key', async () => {
		const spki = extractSpki(decodePem(envelope.certificatePem).der)
		expect(await openSeal('a', await sealPin('a', '111111', spki, suite), pkcs8, suite)).toBeNull()
	})

	it('refuses another account\'s seal', async () => {
		expect(await openSeal('b', await sealPin('a', '246813', pkcs8, suite), pkcs8, suite)).toBeNull()
	})

	it.each([
		['suite', { suiteId: 'suite-other' }, { id: 'suite-other', unlockKeyEpoch: suite.unlockKeyEpoch }],
		['epoch', { unlockKeyEpoch: suite.unlockKeyEpoch + 1 }, { id: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch + 1 }],
	])('refuses a seal whose %s was changed to match the cached one', async (_, change, cached) => {
		const seal = { ...await sealPin('a', '246813', pkcs8, suite), ...change }
		expect(await openSeal('a', seal, pkcs8, cached)).toBeNull()
	})

	it('refuses a seal made for another suite or epoch than the cached one', async () => {
		const seal = await sealPin('a', '246813', pkcs8, suite)
		expect(await openSeal('a', seal, pkcs8, { ...suite, unlockKeyEpoch: suite.unlockKeyEpoch + 1 })).toBeNull()
		expect(await openSeal('a', seal, pkcs8, { ...suite, id: 'suite-other' })).toBeNull()
	})

	it('refuses a flipped ciphertext byte', async () => {
		const seal = await sealPin('a', '246813', pkcs8, suite)
		const bytes = fromBase64(seal.ciphertext)
		bytes[0]! ^= 1
		expect(await openSeal('a', { ...seal, ciphertext: toBase64(bytes) }, pkcs8, suite)).toBeNull()
	})

	it.each([
		['another version', (seal: object) => ({ ...seal, v: 2 })],
		['not an object', () => 'seal'],
		['missing its iv', (seal: object) => ({ ...seal, iv: undefined })],
		['an iv that is not base64', (seal: object) => ({ ...seal, iv: '***' })],
	])('refuses a seal with %s', async (_, change) => {
		expect(await openSeal('a', change(await sealPin('a', '246813', pkcs8, suite)), pkcs8, suite)).toBeNull()
	})
})

describe('the session record', () => {
	it('lives in storage.session as a string, and nothing reaches storage.local', async () => {
		const set = vi.spyOn(browser.storage.local, 'set')
		const record = await wrapPin('2468', pkcs8, suite)
		expect(await storePin('a', record, pinGeneration('a'))).toBe(true)
		expect(typeof (await browser.storage.session.get(pinKey('a')))[pinKey('a')]).toBe('string')
		expect(await readPin('a')).toEqual(record)
		expect(await hasPin('a')).toBe(true)
		expect(set).not.toHaveBeenCalled()
		expect(await browser.storage.local.get(null)).toEqual({})
	})

	it('is gone once forgotten', async () => {
		await storePin('a', await wrapPin('2468', pkcs8, suite), pinGeneration('a'))
		await forgetPin('a')
		expect(await readPin('a')).toBeNull()
		expect(await hasPin('a')).toBe(false)
	})

	it('stores the session copy and the seal together, and forgetting removes both', async () => {
		const generation = pinGeneration('a')
		expect(await storePinCopies('a', await wrapPin('246813', pkcs8, suite), await sealPin('a', '246813', pkcs8, suite), generation)).toBe(true)
		expect(await hasPin('a')).toBe(true)
		expect(await hasSeal('a')).toBe(true)
		await forgetPin('a')
		expect(await browser.storage.session.get(null)).toEqual({})
		expect(await browser.storage.local.get(null)).toEqual({})
		expect(await hasSeal('a')).toBe(false)
	})

	it('stores neither copy after a forget since the generation was read', async () => {
		const generation = pinGeneration('a')
		const record = await wrapPin('246813', pkcs8, suite)
		const seal = await sealPin('a', '246813', pkcs8, suite)
		await forgetPin('a')
		expect(await storePinCopies('a', record, seal, generation)).toBe(false)
		expect(await hasPin('a')).toBe(false)
		expect(await hasSeal('a')).toBe(false)
	})

	it('starts removing both copies in one tick', async () => {
		const local = vi.spyOn(browser.storage.local, 'remove')
		const session = vi.spyOn(browser.storage.session, 'remove')
		void forgetPin('a')
		expect(local).toHaveBeenCalledWith(pinSealKey('a'))
		expect(session).toHaveBeenCalledWith([pinKey('a')])
	})

	it('is not written back by a write that started before it was forgotten', async () => {
		const record = await wrapPin('2468', pkcs8, suite)
		const generation = pinGeneration('a')
		await storePin('a', record, generation)
		await forgetPin('a')
		expect(await storePin('a', { ...record, failures: 1 }, generation)).toBe(false)
		expect(await readPin('a')).toBeNull()
	})

	it.each([
		['not JSON', '{'],
		['missing the envelope', JSON.stringify({ suiteId: 's', unlockKeyEpoch: 1, failures: 0 })],
		['with a negative count', JSON.stringify({ envelope: 'AAAA', suiteId: 's', unlockKeyEpoch: 1, failures: -1 })],
	])('reads a record %s as no PIN', async (_, stored) => {
		await browser.storage.session.set({ [pinKey('a')]: stored })
		expect(await readPin('a')).toBeNull()
	})

	it('keeps the record in memory where storage.session is missing (Firefox < 115)', async () => {
		vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
		const record = await wrapPin('2468', pkcs8, suite)
		await storePin('m', record, pinGeneration('m'))
		expect(await readPin('m')).toEqual(record)
		expect(await browser.storage.local.get(null)).toEqual({})
		await forgetPin('m')
		expect(await hasPin('m')).toBe(false)
	})
})
