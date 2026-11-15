/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the timestamp formatters of the honey, SIEM and audit screens:
 * a timestamp shows as the browser's locale date-time; a missing one shows
 * as a dash on the honey and SIEM screens and as nothing on the audit lists.
 * Compared against the browser's own rendering, so no clock or locale is
 * pinned.
 *
 * @spec exclude Presentation-only formatters.
 */

import { describe, expect, it } from 'vitest'
import HoneyPanel from '../../src/components/HoneyPanel.vue'
import SecretActivityTab from '../../src/components/SecretActivityTab.vue'
import AdminAuditSection from '../../src/components/settings/AdminAuditSection.vue'
import HoneySection from '../../src/components/settings/HoneySection.vue'
import SiemSection from '../../src/components/settings/SiemSection.vue'
import PersonalActivityView from '../../src/views/PersonalActivityView.vue'

const ISO = '2026-10-01T10:00:00+00:00'
const SHOWN = new Date(ISO).toLocaleString()

describe.each([
	['HoneyPanel.formatDate', HoneyPanel.methods.formatDate],
	['HoneySection.formatDate', HoneySection.methods.formatDate],
	['SiemSection.formatDate', SiemSection.methods.formatDate],
])('%s', (_name, format) => {
	it('shows a timestamp as locale date-time, and a dash for none', () => {
		expect(format(ISO)).toBe(SHOWN)
		expect(format(null)).toBe('—')
		expect(format('')).toBe('—')
		expect(format(undefined)).toBe('—')
		expect(format('not a date')).toBe('Invalid Date')
	})
})

describe.each([
	['SecretActivityTab.relativeTime', SecretActivityTab.methods.relativeTime],
	['PersonalActivityView.formatTime', PersonalActivityView.methods.formatTime],
	['AdminAuditSection.formatTime', AdminAuditSection.methods.formatTime],
])('%s', (_name, format) => {
	it('shows a timestamp as locale date-time, and nothing for none', () => {
		expect(format(ISO)).toBe(SHOWN)
		expect(format(null)).toBe('')
		expect(format('')).toBe('')
		expect(format(undefined)).toBe('')
		expect(format('not a date')).toBe('Invalid Date')
	})
})
