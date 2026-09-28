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
 * @spec openspec/changes/secret-export-gdpr/specs/secret-export/spec.md
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
})
