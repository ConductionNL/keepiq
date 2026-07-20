/**
 * Offline snapshot assembly (offline-readonly-cache §2.2 / D1).
 *
 * Pure transforms between the server manifest and the at-rest cache
 * snapshot — no IndexedDB, no network — so the security-critical
 * behaviour (secret ciphertext stored as-is; plaintext metadata encrypted
 * under the vault unlock key) is unit-testable on its own.
 *
 * Snapshot shape written to IndexedDB:
 *   {
 *     suite:    { id, certificate, privateKey (envelope), status },  // ciphertext/params only
 *     secrets:  [{ id, folderId, typeId, key, login, additionalFields, // RSA ciphertext as-is
 *                  meta }],  // encryptMetadata({name,url}) — encrypted at rest
 *     folders:  [{ id, parentId, meta }],  // encryptMetadata({name}) — encrypted at rest
 *     syncedAt: string,
 *   }
 *
 * @spec openspec/changes/offline-readonly-cache/specs/offline-readonly-cache/spec.md#requirement-atomic-snapshot
 */
import { encryptMetadata, decryptMetadata } from '../crypto/metadata.js'

/**
 * Build the at-rest snapshot from a server manifest, encrypting plaintext
 * metadata under the vault unlock key. Secret ciphertext is copied as-is.
 *
 * @param {CryptoKey} aesKey The vault unlock AES-GCM key.
 * @param {object} manifest The server manifest {suite, secrets, folders, syncedAt}.
 * @return {Promise<object>} The at-rest snapshot.
 */
export async function encryptSnapshot(aesKey, manifest) {
	const secrets = await Promise.all((manifest.secrets || []).map(async (s) => ({
		id: s.id,
		folderId: s.folderId,
		typeId: s.typeId,
		key: s.key,
		login: s.login,
		additionalFields: s.additionalFields,
		encryptionSuiteId: s.encryptionSuiteId,
		expiresAt: s.expiresAt,
		// Plaintext metadata encrypted at rest (never stored in the clear).
		meta: await encryptMetadata(aesKey, { name: s.name, url: s.url }),
	})))

	const folders = await Promise.all((manifest.folders || []).map(async (f) => ({
		id: f.id,
		parentId: f.parentId,
		meta: await encryptMetadata(aesKey, { name: f.name }),
	})))

	return {
		suite: {
			id: manifest.suite?.id,
			certificate: manifest.suite?.certificate,
			privateKey: manifest.suite?.privateKey,
			status: manifest.suite?.status,
		},
		secrets,
		folders,
		// Secret type definitions are shared, non-secret metadata (labels the
		// list schema needs) — stored as-is, not encrypted.
		types: manifest.types || [],
		syncedAt: manifest.syncedAt,
	}
}

/**
 * Decrypt an at-rest snapshot back into an in-memory vault view: secret
 * ciphertext is returned untouched (RSA-decrypted lazily on open); the
 * name/url and folder-name metadata is decrypted for listing.
 *
 * @param {CryptoKey} aesKey The vault unlock AES-GCM key.
 * @param {object} snapshot The at-rest snapshot.
 * @return {Promise<object>} {suite, secrets, folders, syncedAt} decrypted for display.
 */
export async function decryptSnapshot(aesKey, snapshot) {
	const secrets = await Promise.all((snapshot.secrets || []).map(async (s) => {
		const meta = await decryptMetadata(aesKey, s.meta)
		return {
			id: s.id,
			name: meta.name,
			url: meta.url,
			folderId: s.folderId,
			typeId: s.typeId,
			key: s.key,
			login: s.login,
			additionalFields: s.additionalFields,
			encryptionSuiteId: s.encryptionSuiteId,
			expiresAt: s.expiresAt,
		}
	}))

	const folders = await Promise.all((snapshot.folders || []).map(async (f) => {
		const meta = await decryptMetadata(aesKey, f.meta)
		return { id: f.id, parentId: f.parentId, name: meta.name }
	}))

	return { suite: snapshot.suite, secrets, folders, types: snapshot.types || [], syncedAt: snapshot.syncedAt }
}
