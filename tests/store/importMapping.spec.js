/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import store keeps the CSV column mapping and re-parses with it
 * (portability-03).
 *
 * @spec openspec/specs/portability-import-mapping/spec.md#requirement-adjustable-csv-mapping
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useImportStore } from '../../src/store/modules/import.js'

const CSV = 'Title,Web address,User,Secret\nGitHub,https://github.com,alice,hunter2\n'

describe('import store column mapping', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('keeps the detected mapping and headers of a generic CSV', async () => {
		const store = useImportStore()

		await store.parseFile(CSV, 'csv')

		expect(store.adjustableMapping).toBe(true)
		expect(store.headers).toEqual(['Title', 'Web address', 'User', 'Secret'])
		expect(store.mapping.map((m) => m.column)).toEqual(store.headers)
	})

	it('re-parses the rows when a column is mapped to another field', async () => {
		const store = useImportStore()
		await store.parseFile(CSV, 'csv')

		await store.applyMapping([
			{ column: 'Title', target: 'name' },
			{ column: 'Web address', target: 'url' },
			{ column: 'User', target: 'login' },
			{ column: 'Secret', target: 'password' },
		])

		expect(store.rows).toHaveLength(1)
		expect(store.rows[0]).toMatchObject({
			name: 'GitHub',
			url: 'https://github.com',
			login: 'alice',
			password: 'hunter2',
		})
	})

	it('rejects the rows when no column is mapped to the name', async () => {
		const store = useImportStore()
		await store.parseFile(CSV, 'csv')

		await store.applyMapping([
			{ column: 'Title', target: 'ignore' },
			{ column: 'Web address', target: 'url' },
			{ column: 'User', target: 'login' },
			{ column: 'Secret', target: 'password' },
		])

		expect(store.mappingHasName).toBe(false)
	})

	it('offers no mapping for a vendor export', async () => {
		const store = useImportStore()
		const bitwarden =
			'folder,favorite,type,name,notes,fields,reprompt,login_uri,login_username,login_password,login_totp\n'
			+ ',,login,GitHub,,,,https://github.com,alice,hunter2,\n'

		await store.parseFile(bitwarden, 'bitwarden')

		expect(store.adjustableMapping).toBe(false)
	})

	it('forgets the source text on reset', async () => {
		const store = useImportStore()
		await store.parseFile(CSV, 'csv')

		store.reset()

		expect(store.sourceText).toBeNull()
		expect(store.mapping).toEqual([])
	})
})
