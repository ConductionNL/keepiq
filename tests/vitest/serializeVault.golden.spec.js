/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for serializeVault in src/export/serializer.js: one vault of
 * secrets with every field shape, folders with nesting, a cycle and an
 * orphan, exported under every scope, compared with
 * fixtures/serializeVault.golden.json.
 *
 * Each scope is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 *
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { serializeVault } from '../../src/export/serializer.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/serializeVault.golden.json')

const FOLDERS = [
	{ id: 'f1', name: 'Work', parentId: null },
	{ id: 'f2', name: 'Sub', parentId: 'f1' },
	{ id: 'f3', name: 'Deep', parentId: 'f2' },
	{ id: 'f4', name: 'Home', parentId: undefined },
	{ id: 'f5', name: 'Orphan', parentId: 'gone' },
	{ id: 'c1', name: 'LoopA', parentId: 'c2' },
	{ id: 'c2', name: 'LoopB', parentId: 'c1' },
]

const SECRETS = [
	{
		name: 'Plain',
		url: 'https://a.test',
		type: 'login',
		login: 'u',
		key: 'p',
		additionalFields: { notes: 'n' },
		folderId: 'f1',
	},
	{
		name: 'Deep one',
		key: 'k',
		folderId: 'f3',
		totp: 'otpauth://totp/A?secret=JBSWY3DPEHPK3PXP',
		passkeys: '[{"id":1}]',
	},
	{ name: 'Empty blobs', key: 'k', folderId: 'f2', totp: '', passkeys: 7 },
	{ name: 'Type id only', typeId: 't-note', key: 'x', folderId: 'f4' },
	{ name: null, url: undefined, key: undefined, folderId: null },
	{ key: 'no name', folderId: undefined },
	{ name: 'Unknown folder', key: 'k', folderId: 'nope' },
	{ name: 'Orphan folder', key: 'k', folderId: 'f5' },
	{ name: 'Cycle', key: 'k', folderId: 'c1' },
	{ name: 'Use only', key: 'secret', folderId: 'f1', useOnly: true },
	{ name: 'Use only string', key: 'k', folderId: 'f1', useOnly: 'true' },
	{ name: 'Zero folder', key: 'k', folderId: 0 },
]

const SCOPES = {
	default: [],
	'explicit vault': [{ mode: 'vault' }],
	null: [null],
	'unknown mode': [{ mode: 'other', folderIds: ['f1'] }],
	'folders Work': [{ mode: 'folders', folderIds: ['f1'] }],
	'folders Sub and Home': [{ mode: 'folders', folderIds: ['f2', 'f4'] }],
	'folders cycle': [{ mode: 'folders', folderIds: ['c1'] }],
	'folders none listed': [{ mode: 'folders' }],
	'folders unknown id': [{ mode: 'folders', folderIds: ['nope'] }],
	'folders zero id': [{ mode: 'folders', folderIds: [0] }],
	'folders null id': [{ mode: 'folders', folderIds: [null] }],
}

/**
 * The payload with every very long path (the folder cycle) cut to its
 * length and first segments, so the fixture stays readable.
 *
 * @param {object} payload The export payload.
 * @return {object} The same payload, long strings shortened.
 */
function shortened(payload) {
	return JSON.parse(
		JSON.stringify(payload, (_, v) =>
			typeof v === 'string' && v.length > 200
				? `${v.slice(0, 40)}... (${v.length} characters)`
				: v,
		),
	)
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('serializeVault (golden)', () => {
	it.each(Object.keys(SCOPES))('%s', (name) => {
		const actual = shortened(serializeVault(SECRETS, FOLDERS, ...SCOPES[name]))
		if (RECORD) {
			recorded[name] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
		expect(Object.keys(actual.secrets[0] ?? {})).toEqual(
			Object.keys(recorded[name].secrets[0] ?? {}),
		)
	})
})
