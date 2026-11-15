/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The locale date-time five views show for an ISO timestamp: the locale
 * text of a parseable one, the value itself of anything else, and '' for
 * none.
 *
 * @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
 */

import { describe, expect, it } from 'vitest'
import ApplicationLeasesPanel from '../../src/components/application/ApplicationLeasesPanel.vue'
import ComplianceSection from '../../src/components/settings/ComplianceSection.vue'
import VersionHistoryPanel from '../../src/components/VersionHistoryPanel.vue'
import ComplianceSnapshotDialog from '../../src/dialogs/ComplianceSnapshotDialog.vue'
import MySendsDialog from '../../src/modals/MySendsDialog.vue'

const VIEWS = { ApplicationLeasesPanel, ComplianceSection, VersionHistoryPanel, ComplianceSnapshotDialog, MySendsDialog }

describe('formatDate', () => {
	for (const [name, view] of Object.entries(VIEWS)) {
		it(`${name} shows a timestamp in the locale, anything else as it is`, () => {
			const format = view.methods.formatDate
			const iso = '2026-03-04T05:06:07Z'
			expect(format(iso)).toBe(new Date(Date.parse(iso)).toLocaleString())
			expect(format('not a date')).toBe('not a date')
			expect(format('')).toBe('')
			expect(format(null)).toBe('')
			expect(format(undefined)).toBe('')
		})
	}
})
