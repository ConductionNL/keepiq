import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { importPrivateKey, pemToPkcs8, toBase64 } from '@/src/crypto'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { proveCertificate, provenCertificateKey, provenKey, readProvenCertificate } from './certificate'

const privateKey = () => importPrivateKey(pemToPkcs8(envelope.privateKeyPem))

/** Another RSA-4096 key, generated once. */
let other: Promise<string> | undefined
const otherCertificate = () => (other ??= (async () => {
	const { publicKey } = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt'])
	return `-----BEGIN PUBLIC KEY-----\n${toBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)))}\n-----END PUBLIC KEY-----`
})())

beforeEach(() => {
	fakeBrowser.reset()
})

describe('proven certificate', () => {
	it('proves the certificate that belongs to the private key, and no other', async () => {
		expect(await provenKey(suiteRow.certificate, await privateKey())).not.toBeNull()
		expect(await provenKey(await otherCertificate(), await privateKey())).toBeNull()
	})

	it('keeps a certificate that proves, in storage.local', async () => {
		await proveCertificate('a', suiteRow.certificate, await privateKey())
		expect(await readProvenCertificate('a')).toBe(suiteRow.certificate)
		expect(await browser.storage.local.get(null)).toEqual({ [provenCertificateKey('a')]: suiteRow.certificate })
	})

	it.each([
		['a swapped certificate', otherCertificate],
		['a malformed certificate', async () => 'not a certificate'],
		['no certificate', async () => undefined],
	])('stores nothing for %s and removes the one kept before', async (_, certificate) => {
		await proveCertificate('a', suiteRow.certificate, await privateKey())
		await proveCertificate('a', await certificate(), await privateKey())
		expect(await readProvenCertificate('a')).toBeUndefined()
	})
})
