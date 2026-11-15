/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether a honey alert's accessor is snoozed, in the secret's honey panel
 * and the admin honey section alike: only while the snooze lies in the
 * future. The clock is frozen.
 *
 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import HoneyPanel from '../../src/components/HoneyPanel.vue'
import HoneySection from '../../src/components/settings/HoneySection.vue'

const NOW = new Date('2026-06-01T12:00:00Z')

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(NOW)
})

afterEach(() => {
	vi.useRealTimers()
})

describe.each([
	['HoneyPanel', HoneyPanel.methods.isSnoozed],
	['HoneySection', HoneySection.methods.isSnoozed],
])('%s.isSnoozed', (_name, isSnoozed) => {
	it('is snoozed only while the snooze lies ahead', () => {
		expect(isSnoozed({ snoozedUntil: '2026-06-01T12:00:01Z' })).toBe(true)
		expect(isSnoozed({ snoozedUntil: '2026-06-01T12:00:00Z' })).toBe(false)
		expect(isSnoozed({ snoozedUntil: '2026-05-31T12:00:00Z' })).toBe(false)
	})

	it('is not snoozed without a snooze', () => {
		expect(isSnoozed({ snoozedUntil: null })).toBe(false)
		expect(isSnoozed({ snoozedUntil: '' })).toBe(false)
		expect(isSnoozed({})).toBe(false)
	})
})
