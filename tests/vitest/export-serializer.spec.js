/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the vault serializer (`src/export/serializer.js`).
 *
 * Locks down:
 *  - Whole-vault serialization with relative folder paths.
 *  - Folder-scoped serialization includes only the selected subtree's secrets.
 *
 * @spec openspec/specs/secret-export/spec.md
 */

import { describe, expect, it } from 'vitest'
import { PAYLOAD_FORMAT, serializeVault } from '../../src/export/serializer.js'

const folders = [
	{ id: 'f1', name: 'Work', parentId: null },
	{ id: 'f2', name: 'Cloud', parentId: 'f1' },
	{ id: 'f3', name: 'Personal', parentId: null },
]

// A server secret carries `typeId`, a UUID (lib/Db/Secret.php); the system
// type ids are UUID v5 of the type name (lib/Repair/SeedSecretTypes.php).
const LOGIN_TYPE_ID = '307f9df3-b31f-519e-bb55-748490bb9b11'
const API_KEY_TYPE_ID = '398f4274-0cda-5c40-8cf2-34124657b1d5'

const secrets = [
	{ name: 'AWS', key: 'k1', login: 'l1', folderId: 'f2', typeId: API_KEY_TYPE_ID },
	{ name: 'Bank', key: 'k2', login: 'l2', folderId: 'f3', typeId: LOGIN_TYPE_ID },
	{ name: 'Root', key: 'k3', login: null, folderId: null, typeId: LOGIN_TYPE_ID },
]

describe('serializeVault', () => {
	it('serializes the whole vault with relative folder paths', () => {
		const payload = serializeVault(secrets, folders, { mode: 'vault' })
		expect(payload.format).toBe(PAYLOAD_FORMAT)
		expect(payload.secrets).toHaveLength(3)
		const aws = payload.secrets.find((s) => s.name === 'AWS')
		expect(aws.folder).toBe('Work/Cloud')
		expect(aws.password).toBe('k1')
		// The type id travels as-is; the restore maps it back (keepiq#749).
		expect(aws.type).toBe(API_KEY_TYPE_ID)
		const root = payload.secrets.find((s) => s.name === 'Root')
		expect(root.folder).toBe('')
		expect(payload.folders.map((f) => f.path).sort()).toEqual([
			'Personal',
			'Work',
			'Work/Cloud',
		])
	})

	it('folder-scoped export includes only the selected subtree', () => {
		// Selecting "Work" (f1) must include its subtree (f2/Cloud) secrets.
		const payload = serializeVault(secrets, folders, {
			mode: 'folders',
			folderIds: ['f1'],
		})
		expect(payload.secrets.map((s) => s.name)).toEqual(['AWS'])
		expect(payload.folders.map((f) => f.path).sort()).toEqual([
			'Work',
			'Work/Cloud',
		])
	})

	it("carries a login's code and passkeys, and writes neither when unset (keepiq#1327)", () => {
		const passkeys = JSON.stringify([
			{ credentialId: 'C', rpId: 'bank.example', privateKey: 'PK' },
		])
		const payload = serializeVault(
			[
				{
					name: 'Bank',
					key: 'pw',
					totp: 'JBSWY3DPEHPK3PXP',
					passkeys,
					folderId: null,
				},
				{ name: 'Plain', key: 'x', totp: '', passkeys: '', folderId: null },
			],
			[],
		)
		const [bank, plain] = payload.secrets
		expect(bank.totp).toBe('JBSWY3DPEHPK3PXP')
		expect(bank.passkeys).toBe(passkeys)
		expect('totp' in plain).toBe(false)
		expect('passkeys' in plain).toBe(false)
	})

	// portability-cxp-cross-provider-transfer: the send side seals exactly
	// the items selected on the list.
	it('selection export holds exactly the selected items and their folders', () => {
		const withIds = secrets.map((secret, i) => ({ ...secret, id: `s${i + 1}` }))
		const payload = serializeVault(withIds, folders, {
			mode: 'selection',
			secretIds: ['s1', 's3'],
		})
		expect(payload.secrets.map((s) => s.name)).toEqual(['AWS', 'Root'])
		expect(payload.folders.map((f) => f.path)).toEqual(['Work/Cloud'])
	})

	it('an empty selection exports nothing', () => {
		const withIds = secrets.map((secret, i) => ({ ...secret, id: `s${i + 1}` }))
		const payload = serializeVault(withIds, folders, { mode: 'selection', secretIds: [] })
		expect(payload.secrets).toEqual([])
		expect(payload.folders).toEqual([])
	})
})
