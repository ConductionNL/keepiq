/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import rows a decrypted backup payload becomes: the default of every
 * field, and which value blobs are carried over.
 *
 * @spec openspec/specs/secret-export/spec.md
 */

import { describe, expect, it, vi } from 'vitest'

const decrypted = { payload: null }

vi.mock('../../src/export/backup.js', () => ({
	BACKUP_FORMAT: 'doriath-backup',
	decryptBackup: vi.fn(async () => decrypted.payload),
}))

const { parseBackup } = await import('../../src/import/backupParser.js')

describe('backup payload rows', () => {
	it('yields no rows for a payload without secrets', async () => {
		decrypted.payload = null
		expect(await parseBackup({}, { passphrase: 'x' })).toEqual([])
		decrypted.payload = { folders: [] }
		expect(await parseBackup('{}', { passphrase: 'x' })).toEqual([])
	})

	it('defaults every field of a bare secret', async () => {
		decrypted.payload = { secrets: [{}] }
		expect(await parseBackup({}, { passphrase: 'x' })).toEqual([
			{
				sourceRow: 1,
				name: '',
				url: null,
				login: null,
				password: null,
				additionalFields: null,
				folder: '',
				type: 'login',
			},
		])
	})

	it('keeps given fields and only non-empty string blobs', async () => {
		decrypted.payload = {
			secrets: [
				{
					name: 'A',
					url: 'https://a.test',
					login: 'al',
					password: 'pw',
					additionalFields: '{}',
					folder: 'Work',
					type: 'card',
					totp: 'JBSWY3DPEHPK3PXP',
					passkeys: '',
					other: 'dropped',
				},
				{ name: 'B', totp: 5, passkeys: '[]' },
			],
		}
		expect(await parseBackup({}, { passphrase: 'x' })).toEqual([
			{
				sourceRow: 1,
				name: 'A',
				url: 'https://a.test',
				login: 'al',
				password: 'pw',
				additionalFields: '{}',
				folder: 'Work',
				type: 'card',
				totp: 'JBSWY3DPEHPK3PXP',
			},
			{
				sourceRow: 2,
				name: 'B',
				url: null,
				login: null,
				password: null,
				additionalFields: null,
				folder: '',
				type: 'login',
				passkeys: '[]',
			},
		])
	})
})
