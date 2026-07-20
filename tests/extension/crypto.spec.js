/**
 * @spec openspec/changes/browser-extension-autofill/specs/browser-extension-autofill/spec.md
 *
 * The extension shares the web app's crypto verbatim (ADR-003 dual-
 * implementation, task 2.2). This proves the re-exported module round-trips the
 * unlock envelope and RSA-OAEP field encryption, and that the imported private
 * key the worker holds is NON-EXTRACTABLE (task 6.1 invariant).
 */
import { describe, it, expect } from 'vitest'
import {
	deriveAesKey,
	encryptPrivateKey,
	decryptPrivateKey,
	importPrivateKey,
	importPublicKey,
	rsaEncrypt,
	rsaDecrypt,
} from '../../browser-extension/src/crypto/index.js'

function toPem(b64, label) {
	return `-----BEGIN ${label}-----\n${b64.match(/.{1,64}/g).join('\n')}\n-----END ${label}-----\n`
}
function b64(buf) {
	return Buffer.from(new Uint8Array(buf)).toString('base64')
}

async function rsaKeyPairPems() {
	const kp = await crypto.subtle.generateKey(
		{ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' },
		true, ['encrypt', 'decrypt'],
	)
	const pkcs8 = toPem(b64(await crypto.subtle.exportKey('pkcs8', kp.privateKey)), 'PRIVATE KEY')
	const spki = toPem(b64(await crypto.subtle.exportKey('spki', kp.publicKey)), 'PUBLIC KEY')
	return { pkcs8, spki }
}

describe('extension shared crypto', () => {
	it('round-trips the unlock envelope (encrypt/decrypt private key)', async () => {
		const { pkcs8 } = await rsaKeyPairPems()
		const master = 'correct horse battery staple'
		const envelope = await encryptPrivateKey(pkcs8, master)
		const recovered = await decryptPrivateKey(envelope, master)
		expect(recovered.replace(/\s+/g, '')).toBe(pkcs8.replace(/\s+/g, ''))
	})

	it('fails to unlock with the wrong master password', async () => {
		const { pkcs8 } = await rsaKeyPairPems()
		const envelope = await encryptPrivateKey(pkcs8, 'right')
		await expect(decryptPrivateKey(envelope, 'wrong')).rejects.toBeTruthy()
	})

	it('imports the private key NON-EXTRACTABLE (worker key never leaves)', async () => {
		const { pkcs8 } = await rsaKeyPairPems()
		const key = await importPrivateKey(pkcs8)
		expect(key.extractable).toBe(false)
	})

	it('round-trips a field: encrypt to the cert, decrypt with the private key', async () => {
		const { pkcs8, spki } = await rsaKeyPairPems()
		const publicKey = await importPublicKey(spki)
		const privateKey = await importPrivateKey(pkcs8)
		const value = 'S3cr3t value — long enough to force multiple RSA-OAEP chunks: ' + 'x'.repeat(700)
		const ciphertext = await rsaEncrypt(value, publicKey)
		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe(value)
	})

	it('deriveAesKey produces a non-extractable AES key', async () => {
		const key = await deriveAesKey('pw', new Uint8Array(16))
		expect(key.extractable).toBe(false)
	})
})
