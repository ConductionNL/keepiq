/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which copies their holder may only view (keepiq#1287): the same rule the
 * browser extension applies.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
 */

import { describe, expect, it } from 'vitest'
import { COPY_WRITER_GRADES, isViewOnlyCopy } from '../../src/utils/copyGrade.js'

describe('isViewOnlyCopy', () => {
	it.each(['read', 'none', undefined])(
		'marks a copy with grade %s view only',
		(effectiveGrade) => {
			expect(
				isViewOnlyCopy({ sourceSecretId: 'src', effectiveGrade }, 'copy-1'),
			).toBe(true)
		},
	)

	it.each(COPY_WRITER_GRADES)('lets a copy with grade %s be written', (grade) => {
		expect(
			isViewOnlyCopy(
				{ sourceSecretId: 'src', effectiveGrade: grade },
				'copy-1',
			),
		).toBe(false)
	})

	it('never marks the source itself view only', () => {
		expect(
			isViewOnlyCopy({ sourceSecretId: 's1', effectiveGrade: 'none' }, 's1'),
		).toBe(false)
	})

	it('reads an unknown context as not view only', () => {
		expect(isViewOnlyCopy(null, 'copy-1')).toBe(false)
		expect(isViewOnlyCopy(undefined, 'copy-1')).toBe(false)
		expect(isViewOnlyCopy({}, 'copy-1')).toBe(false)
	})
})
