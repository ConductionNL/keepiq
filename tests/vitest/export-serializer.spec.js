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
import {
	OMITTABLE_FIELDS,
	PAYLOAD_FORMAT,
	reportedScope,
	serializeVault,
} from '../../src/export/serializer.js'

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
})

// portability-export-choice-and-restore-fidelity D1-D3.
describe('serializeVault scope, fields and type names', () => {
	const VPN_TYPE_ID = 'b0c1d2e3-0000-4000-8000-00000000c0de'
	const types = [
		{ id: LOGIN_TYPE_ID, name: 'login', label: 'Login', scope: 'system' },
		{ id: API_KEY_TYPE_ID, name: 'api-key', label: 'API key', scope: 'system' },
		{ id: VPN_TYPE_ID, name: 'vpn-profile', label: 'VPN profile', scope: 'user' },
	]
	const vault = [
		{ id: 's1', name: 'AWS', key: 'k1', login: 'l1', folderId: 'f2', typeId: API_KEY_TYPE_ID },
		{ id: 's2', name: 'Bank', key: 'k2', login: 'l2', folderId: 'f3', typeId: LOGIN_TYPE_ID, totp: 'SEED', additionalFields: { notes: 'n', totp: 'SEED2' } },
		{ id: 's3', name: 'GCP', key: 'k3', login: null, folderId: 'f1', typeId: API_KEY_TYPE_ID },
		{ id: 's4', name: 'Office VPN', key: 'k4', login: 'v', folderId: 'f3', typeId: VPN_TYPE_ID },
	]
	const names = (payload) => payload.secrets.map((s) => s.name).sort()

	it('combines folders with their subfolders and types', () => {
		const payload = serializeVault(vault, folders, { folderIds: ['f1', 'f3'], typeIds: [API_KEY_TYPE_ID] })
		expect(names(payload)).toEqual(['AWS', 'GCP'])
		expect(reportedScope({ folderIds: ['f1'], typeIds: [API_KEY_TYPE_ID] })).toBe('folders')
	})

	it('limits to the chosen types alone', () => {
		expect(names(serializeVault(vault, folders, { typeIds: [VPN_TYPE_ID] }))).toEqual(['Office VPN'])
	})

	it('limits to exactly the selected secrets', () => {
		const payload = serializeVault(vault, folders, { secretIds: ['s1', 's2', 's4'] })
		expect(names(payload)).toEqual(['AWS', 'Bank', 'Office VPN'])
		// A selection keeps every folder path; it filters secrets only.
		expect(payload.folders).toHaveLength(3)
	})

	it('treats an empty scope as the entire vault', () => {
		expect(serializeVault(vault, folders, {}).secrets).toHaveLength(4)
		expect(serializeVault(vault, folders, { folderIds: [], typeIds: [], secretIds: [] }).secrets).toHaveLength(4)
		expect(reportedScope({})).toBe('vault')
		expect(reportedScope({ mode: 'folders', folderIds: ['f1'] })).toBe('folders')
		expect(reportedScope(undefined)).toBe('vault')
	})

	it('writes the type name for a system type and name plus label for a custom one', () => {
		const payload = serializeVault(vault, folders, {}, { types })
		const byName = Object.fromEntries(payload.secrets.map((s) => [s.name, s]))
		expect(byName.AWS.type).toBe(API_KEY_TYPE_ID)
		expect(byName.AWS.typeName).toBe('api-key')
		expect(byName.AWS).not.toHaveProperty('typeLabel')
		expect(byName['Office VPN'].typeName).toBe('vpn-profile')
		expect(byName['Office VPN'].typeLabel).toBe('VPN profile')
		// No type list: no names, and the payload is not partial.
		const bare = serializeVault(vault, folders, {})
		expect(bare.secrets[0]).not.toHaveProperty('typeName')
		expect(bare).not.toHaveProperty('partial')
	})

	it('leaves out usernames and marks the backup partial', () => {
		const payload = serializeVault(vault, folders, {}, { omitFields: ['login'] })
		expect(payload.secrets.every((s) => s.login === null)).toBe(true)
		expect(payload.secrets.every((s) => s.password !== null && s.name !== '')).toBe(true)
		expect(payload.partial).toBe(true)
		expect(payload.omittedFields).toEqual(['login'])
	})

	it('leaves out additional fields', () => {
		const bank = serializeVault(vault, folders, {}, { omitFields: ['additionalFields'] }).secrets.find((s) => s.name === 'Bank')
		expect(bank.additionalFields).toBeNull()
		expect(bank.totp).toBe('SEED')
	})

	it('leaves out the one-time code seed, also among the additional fields', () => {
		const payload = serializeVault(vault, folders, {}, { omitFields: ['totp', 'name', 'password'] })
		const bank = payload.secrets.find((s) => s.name === 'Bank')
		expect(bank).not.toHaveProperty('totp')
		expect(bank.additionalFields).toEqual({ notes: 'n' })
		expect(bank.password).toBe('k2')
		// Name and value cannot be left out.
		expect(payload.omittedFields).toEqual(['totp'])
		expect(OMITTABLE_FIELDS).toEqual(['login', 'additionalFields', 'totp'])
	})
})
