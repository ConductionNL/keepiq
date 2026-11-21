/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden record for the small list and field-name helpers, recorded before
 * they were split. KEEPIQ_RECORD_GOLDEN=1 rewrites the record.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => {
	const translated = (app, text, vars) =>
		`[${app}] ${text} ${JSON.stringify(vars ?? null)}`
	return { t: translated, translate: translated }
})

const { memberNameError } = await import('../../src/utils/additionalFields.js')
const { fieldListError } = await import('../../src/utils/typedFields.js')
const { rowOpenLabel } = await import('../../src/utils/valueFieldMarkers.js')
const { destinationRows, subfolderRows } =
	await import('../../src/utils/vaultList.js')

const GOLDEN = path.join(__dirname, 'golden', 'list-helpers.json')
const t = (app, text, vars) => `(${app}) ${text} ${JSON.stringify(vars ?? null)}`

const FOLDERS = [
	{
		id: 'v2',
		name: 'zeta',
		parentId: null,
		customIcon: 'star',
		customColor: '#123',
	},
	{ id: 'v1', name: 'Alpha', parentId: null },
	{ id: 'a2', name: 'Work', parentId: 'v1' },
	{ id: 'a1', name: 'home work', parentId: 'v1' },
	{ id: 'a3', name: null, parentId: 'v1' },
	{ id: 'b1', name: 'deep', parentId: 'a1' },
	{ id: 'c1', name: 'orphan', parentId: 'gone' },
	{ id: 'u1', name: 'undefined parent' },
	null,
]
const ROWS = FOLDERS.filter(Boolean)

const WORLDS = {
	memberNameError: [
		[undefined],
		[''],
		['   '],
		['Key'],
		[' URL '],
		['notes'],
		['custom'],
		['custom', ['custom']],
		[' custom ', [' custom ', null]],
		['Custom', ['custom']],
		['custom', [null, undefined, '']],
	].map((args) => [args, memberNameError(...args)]),
	fieldListError: [
		[],
		[{}],
		[{ label: '  ' }],
		[{ label: 'Key' }],
		[{ label: ' URL ' }],
		[{ label: 'a' }, { label: 'b' }],
		[{ label: 'a' }, { label: ' A ' }],
		[{ label: 'a' }, { label: '' }],
		[{ label: 'a' }, { label: 'password' }],
	].map((fields) => [fields, fieldListError(fields, t)]),
	rowOpenLabel: [
		null,
		undefined,
		{},
		{ name: 'n' },
		{ name: 'n', passkeys: '' },
		{ name: 'n', passkeys: 'x' },
		{ name: 'n', totp: 'x', loginTotp: 'x', totpSeed: 'x' },
		{ name: 'n', passkeys: 'x', totp: 'x', loginTotp: 'x', totpSeed: 'x' },
		{ passkeys: 'x' },
	].map((row) => [row ?? String(row), rowOpenLabel(row)]),
	subfolderRows: [
		[FOLDERS, null],
		[FOLDERS, 'v1'],
		[FOLDERS, 'v1', 'WORK'],
		[FOLDERS, 'v1', '  work '],
		[FOLDERS, 'v1', ''],
		[FOLDERS, 'v1', null],
		[FOLDERS, 'none'],
		[null, null],
		[undefined, 'v1', 'x'],
		[FOLDERS, '', 'zeta'],
		[FOLDERS, undefined],
	].map((args) => [args.slice(1), subfolderRows(...args)]),
	destinationRows: [
		[ROWS],
		[ROWS, null],
		[ROWS, 'v1'],
		[ROWS, 'a1'],
		[ROWS, 'none'],
		[null],
		[undefined, 'x'],
		[[]],
	].map((args) => [args.slice(1), destinationRows(...args)]),
}

describe('list and field-name helpers golden', () => {
	it('match the record', () => {
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
			fs.writeFileSync(GOLDEN, JSON.stringify(WORLDS, null, '\t') + '\n')
		}
		const want = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
		const got = JSON.parse(JSON.stringify(WORLDS))
		for (const name of Object.keys(want)) {
			expect(got[name], name).toEqual(want[name])
		}
		expect(Object.keys(got)).toEqual(Object.keys(want))
	})
})
