import { importPublicKey, rsaEncrypt } from '@/src/crypto'
import type { BlockedSecretRow, FolderRow, OpenSecretRow, SecretTypeRow } from '@/src/api/types'
import { envelope, suiteRow } from './vectors'

let publicKey: Promise<CryptoKey> | undefined

/** Ciphertext the vectors' private key opens. */
export function encrypt(plaintext: string): Promise<string> {
	publicKey ??= importPublicKey(envelope.publicKeyPem)
	return publicKey.then((key) => rsaEncrypt(plaintext, key))
}

export function secretRow(overrides: Partial<OpenSecretRow> = {}): OpenSecretRow {
	return {
		id: 's1', name: 'GitHub', url: 'https://github.com', typeId: 't-login', folderId: null,
		key: 'ciphertext', login: null, additionalFields: null, blocked: false,
		encryptionSuiteId: suiteRow.id, ownerType: 'user', ownerId: 'alice',
		createdAt: '2026-01-05T10:00:00+00:00', updatedAt: '2026-03-02T10:00:00+00:00',
		keyUpdatedAt: null, expiresAt: null, possiblyCompromisedAt: null, tombstonedAt: null, tombstoneReason: null,
		...overrides,
	}
}

export function blockedRow(overrides: Partial<BlockedSecretRow> = {}): BlockedSecretRow {
	const base: Partial<OpenSecretRow> = secretRow()
	delete base.key
	delete base.login
	delete base.additionalFields
	return { ...(base as Omit<OpenSecretRow, 'key' | 'login' | 'additionalFields' | 'blocked'>), id: 'b1', name: 'Blocked', blocked: true, blockedReason: 'suite revoked', ...overrides }
}

export function folderRow(overrides: Partial<FolderRow> = {}): FolderRow {
	return {
		id: 'f1', name: 'Work', parentId: null, ownerType: 'user', ownerId: 'alice', customIcon: null, customColor: null,
		createdAt: '2026-01-01T00:00:00+00:00', updatedAt: '2026-01-01T00:00:00+00:00', ...overrides,
	}
}

export const typeRows: SecretTypeRow[] = [
	'login', 'api_key', 'ssh_key', 'certificate', 'note', 'database', 'totp', 'passkey', 'card', 'identity',
].map((name) => ({ id: `t-${name}`, name, label: name === 'totp' ? 'TOTP' : name[0]!.toUpperCase() + name.slice(1).replace('_', ' ') }))
