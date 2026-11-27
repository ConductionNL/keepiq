/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of the restore's type resolution
 * (portability-export-choice-and-restore-fidelity D3, task 2.2).
 *
 * @spec openspec/specs/export-selection-and-restore/spec.md#requirement-a-restored-backup-keeps-types-and-row-positions
 */

import { describe, expect, it, vi } from 'vitest'
import { resolveRowTypes } from '../../src/store/modules/import.js'

const SYSTEM_LOGIN = '0b8a1f6e-0000-4000-8000-000000000001'
const CUSTOM_WIFI = '0b8a1f6e-0000-4000-8000-0000000000aa'

/**
 * A type store with the given types and a createType double.
 *
 * @param {Array<object>} types The vault's types.
 * @param {Function} [create] What createType answers.
 * @return {object} The store.
 */
function typeStore(
	types,
	create = async (data) => ({ id: `new-${data.name}`, ...data }),
) {
	return { types, createType: vi.fn(create) }
}

describe('resolveRowTypes', () => {
	it("takes the system type of the backup's name", async () => {
		const store = typeStore([
			{ id: SYSTEM_LOGIN, name: 'login', scope: 'system' },
			{ id: 'u1', name: 'login', scope: 'user' },
		])
		const row = { sourceRow: 1, typeName: 'login', type: 'other-instance-id' }

		const { typeIdFor, created, fallbacks } = await resolveRowTypes([row], store)

		expect(typeIdFor(row)).toBe(SYSTEM_LOGIN)
		expect(created).toEqual([])
		expect(fallbacks).toEqual([])
	})

	it("takes the vault's own custom type of that name", async () => {
		const store = typeStore([
			{ id: CUSTOM_WIFI, name: 'wifi', label: 'Wi-Fi', scope: 'user' },
		])
		const row = { sourceRow: 2, typeName: 'wifi', typeLabel: 'Wi-Fi' }

		const { typeIdFor } = await resolveRowTypes([row], store)

		expect(typeIdFor(row)).toBe(CUSTOM_WIFI)
		expect(store.createType).not.toHaveBeenCalled()
	})

	it('creates a missing custom type once and reuses it', async () => {
		const store = typeStore([])
		const rows = [
			{ sourceRow: 3, typeName: 'wifi', typeLabel: 'Wi-Fi' },
			{ sourceRow: 4, typeName: 'wifi', typeLabel: 'Wi-Fi' },
		]

		const { typeIdFor, created } = await resolveRowTypes(rows, store)

		expect(store.createType).toHaveBeenCalledTimes(1)
		expect(store.createType).toHaveBeenCalledWith({
			name: 'wifi',
			label: 'Wi-Fi',
			scope: 'user',
		})
		expect(rows.map(typeIdFor)).toEqual(['new-wifi', 'new-wifi'])
		expect(created).toEqual([{ name: 'wifi', label: 'Wi-Fi' }])
	})

	it("recovers an old backup's system type by its id", async () => {
		const store = typeStore([
			{ id: SYSTEM_LOGIN, name: 'login', scope: 'system' },
		])
		const row = { sourceRow: 5, type: SYSTEM_LOGIN }

		const { typeIdFor } = await resolveRowTypes([row], store)

		expect(typeIdFor(row)).toBe(SYSTEM_LOGIN)
	})

	it('falls back with a warning naming the row when a type cannot be made', async () => {
		const store = typeStore([], async () => {
			throw new Error('refused')
		})
		const rows = [
			{ sourceRow: 6, typeName: 'wifi', typeLabel: 'Wi-Fi' },
			{ sourceRow: 7, typeName: 'wifi', typeLabel: 'Wi-Fi' },
			{ sourceRow: 8, type: 'an-unknown-id' },
		]

		const { typeIdFor, fallbacks, created } = await resolveRowTypes(rows, store)

		expect(store.createType).toHaveBeenCalledTimes(1)
		expect(rows.map(typeIdFor)).toEqual([null, null, null])
		expect(created).toEqual([])
		expect(fallbacks).toEqual([
			{ sourceRow: 6, type: 'Wi-Fi' },
			{ sourceRow: 7, type: 'Wi-Fi' },
			{ sourceRow: 8, type: 'an-unknown-id' },
		])
	})
})
