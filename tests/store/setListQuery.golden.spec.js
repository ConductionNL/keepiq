/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for setListQuery in the secret store: sequences of list
 * queries, each recorded as the filters and sort after every step, compared
 * with golden/set-list-query.json.
 *
 * Each sequence is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'

const GOLDEN = path.join(__dirname, 'golden', 'set-list-query.json')

const VALUES = [undefined, null, '', 0, false, 'x', 'last_used_at', true]
const FIELDS = ['folderId', 'search', 'typeId', 'sort', 'state', 'favourite', 'tag']

const SEQUENCES = {
	'no argument': [undefined],
	'empty query': [{}],
	'every field set': [
		{
			folderId: 'f1',
			search: 'db',
			typeId: 't1',
			sort: 'name',
			state: 'archived',
			favourite: 1,
			tag: 'work',
		},
	],
	'set then clear': [
		{
			folderId: 'f1',
			search: 'db',
			typeId: 't1',
			sort: 'last_used_at',
			state: 'trashed',
			favourite: true,
			tag: 'work',
		},
		{
			folderId: null,
			search: null,
			typeId: null,
			sort: '',
			state: '',
			favourite: 0,
			tag: '',
		},
	],
	'sort last used then name': [
		{ sort: 'last_used_at' },
		{ sort: 'name' },
		{ sort: undefined },
	],
	'unrelated keys only': [{ page: 3, other: 'x' }],
	'set then undefined': [
		{
			folderId: 'f1',
			search: 's',
			typeId: 't',
			state: 'archived',
			favourite: true,
			tag: 'x',
		},
		{
			folderId: undefined,
			search: undefined,
			typeId: undefined,
			state: undefined,
			favourite: undefined,
			tag: undefined,
		},
	],
}
for (const field of FIELDS) {
	SEQUENCES[`${field} through every value`] = VALUES.map((value) => ({
		[field]: value,
	}))
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('setListQuery (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it.each(Object.keys(SEQUENCES))('%s', (name) => {
		const store = useSecretStore()
		const actual = SEQUENCES[name].map((query) => {
			if (query === undefined) {
				store.setListQuery()
			} else {
				store.setListQuery(query)
			}
			return JSON.parse(
				JSON.stringify(
					{ filters: store.filters, sort: store.sort },
					(_, v) => (v === undefined ? '(undefined)' : v),
				),
			)
		})
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
