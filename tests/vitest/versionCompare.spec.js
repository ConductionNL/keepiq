/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The field-by-field comparison behind the side-by-side version view.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/secret-version-history/spec.md#requirement-compare-two-versions-side-by-side
 */

import { describe, expect, it } from 'vitest'
import { anyChanged, compareStates } from '../../src/utils/versionCompare.js'

const byField = (rows) => Object.fromEntries(rows.map((row) => [(row.additional ? '+' : '') + row.field, row]))

describe('compareStates', () => {
	it('marks a new password and a new additional field, and nothing else', () => {
		const lastWeek = { name: 'Mail server', url: 'https://mail.example', login: 'admin', key: 'old-pass', additionalFields: { host: 'mx1' } }
		const current = { name: 'Mail server', url: 'https://mail.example', login: 'admin', key: 'new-pass', additionalFields: { host: 'mx1', port: '993' } }

		const rows = byField(compareStates(lastWeek, current))

		expect(rows.key).toMatchObject({ left: 'old-pass', right: 'new-pass', changed: true, masked: true })
		expect(rows['+port']).toMatchObject({ left: '', right: '993', changed: true, masked: true, additional: true })
		expect(rows.name.changed).toBe(false)
		expect(rows.url.changed).toBe(false)
		expect(rows.login).toMatchObject({ changed: false, masked: false })
		expect(rows['+host'].changed).toBe(false)
	})

	it('marks an additional field that was removed', () => {
		const rows = byField(compareStates({ additionalFields: { pin: '1234' } }, { additionalFields: {} }))

		expect(rows['+pin']).toMatchObject({ left: '1234', right: '', changed: true })
	})

	it('marks an additional field emptied out as removed only when it is gone, not when it is blank', () => {
		const rows = byField(compareStates({ additionalFields: { note: '' } }, { additionalFields: {} }))

		expect(rows['+note'].changed).toBe(true)
		expect(byField(compareStates({ additionalFields: { note: '' } }, { additionalFields: { note: '' } }))['+note'].changed).toBe(false)
	})

	it('reports no change for two states that differ only in fields it does not compare', () => {
		const a = { name: 'N', key: 'k', createdAt: '2026-01-01T00:00:00Z', versionNumber: 1, additionalFields: { a: '1' } }
		const b = { name: 'N', key: 'k', createdAt: '2026-02-01T00:00:00Z', versionNumber: 2, additionalFields: { a: '1' } }

		expect(anyChanged(compareStates(a, b))).toBe(false)
	})

	it('treats null, undefined and an absent value alike, and lists additional fields by name', () => {
		const rows = compareStates({ url: null, additionalFields: { b: '2', a: '1' } }, { additionalFields: { a: '1', b: '2' } })

		expect(rows.find((row) => row.field === 'url')).toMatchObject({ left: '', right: '', changed: false })
		expect(rows.filter((row) => row.additional).map((row) => row.field)).toEqual(['a', 'b'])
		expect(anyChanged(rows)).toBe(false)
	})
})
