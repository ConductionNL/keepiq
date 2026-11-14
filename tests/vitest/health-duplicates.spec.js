/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for duplicate detection and the merge's field fold
 * (src/health/duplicates.js).
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */

import { describe, expect, it } from 'vitest'
import { duplicateGroups, foldFields, hostOf } from '../../src/health/duplicates.js'

function row (id, over = {}) {
  return {
	id,
	name: 'GitHub',
	url: 'https://github.com/login',
	login: 'anna@example.org',
	value: 'S3cret-one',
	folderPath: null,
	...over,
}
}

const ids = (group) => group.items.map((item) => item.id)

describe('duplicateGroups', () => {
	it('groups the same host, username and value as an exact duplicate', () => {
		const { exact, likely } = duplicateGroups([
			row('a'),
			row('b', { name: 'github.com', url: 'github.com' }),
			row('c', { url: 'https://gitlab.com' }),
		])
		expect(exact).toHaveLength(1)
		expect(ids(exact[0])).toEqual(['a', 'b'])
		expect(exact[0]).toMatchObject({ kind: 'exact', host: 'github.com', username: 'anna@example.org' })
		expect(likely).toEqual([])
	})

	it('compares usernames without case or surrounding spaces, and drops www.', () => {
		const { exact } = duplicateGroups([
			row('a', { login: 'Anna@Example.org ' }),
			row('b', { url: 'https://www.github.com' }),
		])
		expect(exact.map(ids)).toEqual([['a', 'b']])
	})

	it('groups the same host and username with another value as a likely duplicate', () => {
		const { exact, likely } = duplicateGroups([
			row('a'),
			row('b'),
			row('c', { value: 'Other-pass' }),
			row('d', { login: 'someone-else' }),
		])
		expect(exact.map(ids)).toEqual([['a', 'b']])
		expect(likely).toHaveLength(1)
		expect(likely[0].kind).toBe('likely')
		expect(ids(likely[0])).toEqual(['a', 'b', 'c'])
	})

	it('groups items without an address on exact name, username and value only', () => {
		const noUrl = { url: null, name: 'Router' }
		const { exact, likely } = duplicateGroups([
			row('a', noUrl),
			row('b', noUrl),
			row('c', { ...noUrl, value: 'different' }),
			row('d', { ...noUrl, name: 'router' }),
		])
		expect(exact.map(ids)).toEqual([['a', 'b']])
		expect(exact[0].host).toBeNull()
		expect(likely).toEqual([])
	})

	it('never groups passkey or authenticator material', () => {
		const { exact, likely } = duplicateGroups([
			row('a', { machineMaterial: true }),
			row('b', { machineMaterial: true }),
		])
		expect(exact).toEqual([])
		expect(likely).toEqual([])
	})

	it('never lists a recipient copy of someone else\'s secret', () => {
		const { exact } = duplicateGroups([row('own'), row('copy', { copy: true })])
		expect(exact).toEqual([])
		expect(duplicateGroups([row('own'), row('own2'), row('copy', { copy: true })]).exact.map(ids)).toEqual([['own', 'own2']])
	})

	it('reports no value and no digest', () => {
		const { exact } = duplicateGroups([row('a'), row('b')])
		expect(JSON.stringify(exact)).not.toContain('S3cret-one')
		expect(Object.keys(exact[0].items[0]).sort()).toEqual(['folderId', 'id', 'name'])
	})

	it('ignores empty values and empty input', () => {
		expect(duplicateGroups([row('a', { value: '' }), row('b', { value: '' })]).exact).toEqual([])
		expect(duplicateGroups(undefined)).toEqual({ exact: [], likely: [] })
	})
})

describe('hostOf', () => {
	it('reads the host of a stored address, or null', () => {
		expect(hostOf('https://Login.Example.org:8443/x')).toBe('login.example.org')
		expect(hostOf('example.org/path')).toBe('example.org')
		expect(hostOf('')).toBeNull()
		expect(hostOf(null)).toBeNull()
		expect(hostOf('http://')).toBeNull()
	})
})

describe('foldFields', () => {
	it('keeps the kept secret\'s fields and adds the others\' new ones', () => {
		expect(foldFields({ pin: '1234' }, [{ 'recovery email': 'anna@home.example' }])).toEqual({
			pin: '1234',
			'recovery email': 'anna@home.example',
		})
	})

	it('keeps a clashing value under a suffixed name, the kept value winning', () => {
		expect(foldFields({ pin: '1234' }, [{ pin: '9999' }, { pin: '5555' }])).toEqual({
			pin: '1234',
			'pin (2)': '9999',
			'pin (3)': '5555',
		})
	})

	it('does not repeat a value the name already holds', () => {
		expect(foldFields({ pin: '1234' }, [{ pin: '1234' }, { pin: '9999' }, { pin: '9999' }])).toEqual({
			pin: '1234',
			'pin (2)': '9999',
		})
	})

	it('reads a missing or unparsed blob as no fields', () => {
		expect(foldFields(null, ['not json', null, { a: '1' }])).toEqual({ a: '1' })
		expect(foldFields('text', [])).toEqual({})
	})
})
