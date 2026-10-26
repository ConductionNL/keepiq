/** The server serves the certificate, so it can swap it; only the private key, which only the master password opens, can't be (ADR-002). */
import { importPublicKey, rsaDecrypt, rsaEncrypt, toBase64 } from '@/src/crypto'

/** The certificate the last unlock proved against the private key. Public key material, so `storage.local`. */
export const provenCertificateKey = (accountId: string) => `provenCertificate.${accountId}`

/** The certificate's public key, or `null` when the private key does not open what it encrypts. */
export async function provenKey(certificate: string, privateKey: CryptoKey): Promise<CryptoKey | null> {
	const key = await importPublicKey(certificate)
	const probe = toBase64(crypto.getRandomValues(new Uint8Array(32)))
	const opened = await rsaDecrypt(await rsaEncrypt(probe, key), privateKey).catch(() => null)
	return opened === probe ? key : null
}

/** Keeps the certificate when it proves, and otherwise removes the one kept before. */
export async function proveCertificate(accountId: string, certificate: string | undefined, privateKey: CryptoKey): Promise<void> {
	const proven = certificate ? await provenKey(certificate, privateKey).catch(() => null) : null
	if (proven) await browser.storage.local.set({ [provenCertificateKey(accountId)]: certificate })
	else await browser.storage.local.remove(provenCertificateKey(accountId))
}

export async function readProvenCertificate(accountId: string): Promise<string | undefined> {
	const stored = (await browser.storage.local.get(provenCertificateKey(accountId)))[provenCertificateKey(accountId)]
	return typeof stored === 'string' ? stored : undefined
}
