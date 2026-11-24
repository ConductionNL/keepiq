/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export dialog's scope and options (portability-export-choice-and-restore-fidelity task 1.2),
 * checked against the real serializer.
 *
 * @spec openspec/specs/export-selection-and-restore/spec.md#requirement-choose-what-an-export-contains
 */

import { describe, expect, it } from 'vitest'
import { buildScope, serializerOptions } from '../../src/export/exportScope.js'
import { serializeVault } from '../../src/export/serializer.js'

const TYPE_LOGIN = '0b8a1f6e-0000-4000-8000-000000000001'
const TYPE_CUSTOM = '0b8a1f6e-0000-4000-8000-000000000002'
const types = [
	{ id: TYPE_LOGIN, name: 'login', scope: 'system' },
	{ id: TYPE_CUSTOM, name: 'wifi', label: 'Wi-Fi', scope: 'user' },
]
const folders = [
	{ id: 'f1', name: 'Work', parentId: null },
	{ id: 'f2', name: 'Home', parentId: null },
	{ id: 'f3', name: 'Bank', parentId: null },
]
const secrets = [
	{ id: 's1', name: 'Mail', login: 'me', key: 'k1', folderId: 'f1', typeId: TYPE_LOGIN, additionalFields: { pin: '1' }, totp: 'SEED' },
	{ id: 's2', name: 'Router', login: 'admin', key: 'k2', folderId: 'f2', typeId: TYPE_CUSTOM },
	{ id: 's3', name: 'Bank', login: 'x', key: 'k3', folderId: 'f3', typeId: TYPE_LOGIN },
]
const names = (payload) => payload.secrets.map((s) => s.name).sort()

describe('buildScope', () => {
	it('exports two chosen folders', () => {
		const scope = buildScope({ kind: 'folders', folderIds: ['f1', 'f2'] })
		expect(scope).toEqual({ folderIds: ['f1', 'f2'] })
		expect(names(serializeVault(secrets, folders, scope, { types }))).toEqual(['Mail', 'Router'])
	})

	it('exports two chosen folders narrowed to one type', () => {
		const scope = buildScope({ kind: 'folders', folderIds: ['f1', 'f3'], typeIds: [TYPE_LOGIN] })
		expect(scope).toEqual({ folderIds: ['f1', 'f3'], typeIds: [TYPE_LOGIN] })
		expect(names(serializeVault(secrets, folders, scope, { types }))).toEqual(['Bank', 'Mail'])
		const custom = buildScope({ kind: 'folders', folderIds: ['f1', 'f3'], typeIds: [TYPE_CUSTOM] })
		expect(serializeVault(secrets, folders, custom, { types }).secrets).toHaveLength(0)
	})

	it('reads chosen folders with only types chosen as a type filter', () => {
		expect(buildScope({ kind: 'folders', folderIds: [], typeIds: [TYPE_CUSTOM] })).toEqual({ typeIds: [TYPE_CUSTOM] })
	})

	it('ignores chosen folders when the scope is chosen types', () => {
		expect(buildScope({ kind: 'types', folderIds: ['f1'], typeIds: [TYPE_CUSTOM] })).toEqual({ typeIds: [TYPE_CUSTOM] })
	})

	it('exports one chosen type', () => {
		const scope = buildScope({ kind: 'types', typeIds: [TYPE_CUSTOM] })
		expect(names(serializeVault(secrets, folders, scope, { types }))).toEqual(['Router'])
	})

	it('exports exactly the selection', () => {
		const scope = buildScope({ kind: 'selection', secretIds: ['s1', 's3'] })
		expect(scope).toEqual({ mode: 'selection', secretIds: ['s1', 's3'] })
		expect(names(serializeVault(secrets, folders, scope, { types }))).toEqual(['Bank', 'Mail'])
	})

	it('reads folders or types with nothing chosen as the whole vault', () => {
		expect(buildScope({ kind: 'folders', folderIds: [] })).toEqual({ mode: 'vault' })
		expect(buildScope({ kind: 'types', typeIds: [''] })).toEqual({ mode: 'vault' })
		expect(buildScope(undefined)).toEqual({ mode: 'vault' })
	})

	it('reads an empty selection as nothing, not the vault', () => {
		expect(serializeVault(secrets, folders, buildScope({ kind: 'selection', secretIds: [] }), { types }).secrets).toHaveLength(0)
	})
})

describe('serializerOptions', () => {
	it('leaves out the chosen fields and names the types', () => {
		const payload = serializeVault(secrets, folders, { mode: 'vault' }, serializerOptions(types, ['login', 'totp']))
		const mail = payload.secrets.find((s) => s.name === 'Mail')
		expect(mail.login ?? '').toBe('')
		expect(mail.totp ?? '').toBe('')
		expect(payload.partial).toBe(true)
		const router = payload.secrets.find((s) => s.name === 'Router')
		expect(router.typeName).toBe('wifi')
		expect(router.typeLabel).toBe('Wi-Fi')
	})

	it('copes with nothing passed', () => {
		expect(serializerOptions(undefined, undefined)).toEqual({ types: [], omitFields: [] })
	})
})
