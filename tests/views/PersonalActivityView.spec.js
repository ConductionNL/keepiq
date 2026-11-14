/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal activity feed reads the audit store, loads on creation and
 * formats each entry's time and event label.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const auditStore = {
	personalEntries: [],
	loading: false,
	fetchPersonalActivity: vi.fn(),
}

vi.mock('../../src/store/modules/audit.js', () => ({
	useAuditStore: () => auditStore,
}))

const { default: PersonalActivityView } = await import(
	'../../src/views/PersonalActivityView.vue'
)

describe('PersonalActivityView', () => {
	beforeEach(() => {
		auditStore.personalEntries = []
		auditStore.loading = false
		auditStore.fetchPersonalActivity = vi.fn().mockResolvedValue()
	})

	it('exposes the store personal entries and loading flag', () => {
		const entries = [{ id: 1, eventType: 'secret.read' }]
		auditStore.personalEntries = entries
		auditStore.loading = true

		expect(PersonalActivityView.computed.entries.call({})).toBe(entries)
		expect(PersonalActivityView.computed.loading.call({})).toBe(true)
	})

	it('fetches the personal activity once when created', async () => {
		await PersonalActivityView.created.call({})

		expect(auditStore.fetchPersonalActivity).toHaveBeenCalledTimes(1)
		expect(auditStore.fetchPersonalActivity).toHaveBeenCalledWith()
	})

	it('falls back to the raw event type for an unknown event', () => {
		expect(PersonalActivityView.methods.label('no.such.event')).toBe(
			'no.such.event',
		)
	})

	it('labels a known event with its readable label', () => {
		expect(PersonalActivityView.methods.label('secret.created')).toBe(
			'Secret created',
		)
	})

	it('returns an empty string for a missing timestamp', () => {
		expect(PersonalActivityView.methods.formatTime('')).toBe('')
		expect(PersonalActivityView.methods.formatTime(null)).toBe('')
		expect(PersonalActivityView.methods.formatTime(undefined)).toBe('')
	})

	it('formats an ISO timestamp as the locale date-time', () => {
		const iso = '2026-03-04T05:06:07Z'

		expect(PersonalActivityView.methods.formatTime(iso)).toBe(
			new Date(iso).toLocaleString(),
		)
	})

	it('returns the input when the date cannot be formatted', () => {
		const spy = vi
			.spyOn(Date.prototype, 'toLocaleString')
			.mockImplementation(() => {
				throw new RangeError('bad locale')
			})
		try {
			expect(PersonalActivityView.methods.formatTime('2026-01-01')).toBe(
				'2026-01-01',
			)
		} finally {
			spy.mockRestore()
		}
	})
})
