/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The privileged login helpers: the state of a request's window (start
 * inclusive, end exclusive, as on the server), the datetime-local to ISO
 * conversion, the typed address ranges and the rotation offer.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */

import { describe, expect, it } from 'vitest'
import {
	localInputToIso,
	parseRanges,
	requestState,
	shouldOfferRotation,
} from '../../src/utils/privilegedAccess.js'

const approved = {
	status: 'approved',
	approvedStart: '2026-11-16T14:00:00Z',
	approvedEnd: '2026-11-16T16:00:00Z',
}

describe('requestState', () => {
	it('reads pending and denied from the status', () => {
		expect(requestState({ status: 'pending' }, new Date())).toBe('pending')
		expect(requestState({ status: 'denied' }, new Date())).toBe('denied')
		expect(requestState(null, new Date())).toBe('pending')
	})

	it('places a moment against the approved window, end exclusive', () => {
		expect(requestState(approved, new Date('2026-11-16T13:59:59Z'))).toBe('upcoming')
		expect(requestState(approved, new Date('2026-11-16T14:00:00Z'))).toBe('active')
		expect(requestState(approved, new Date('2026-11-16T15:59:59Z'))).toBe('active')
		expect(requestState(approved, new Date('2026-11-16T16:00:00Z'))).toBe('ended')
	})

	it('treats an approval without a window as still pending', () => {
		expect(requestState({ status: 'approved', approvedStart: null, approvedEnd: null }, new Date())).toBe('pending')
	})
})

describe('localInputToIso', () => {
	it('turns a local input into the same moment in UTC', () => {
		expect(localInputToIso('2026-11-16T14:00')).toBe(new Date('2026-11-16T14:00').toISOString())
	})

	it('answers empty for nothing or a non-date', () => {
		expect(localInputToIso('')).toBe('')
		expect(localInputToIso(undefined)).toBe('')
		expect(localInputToIso('soon')).toBe('')
	})
})

describe('parseRanges', () => {
	it('splits on commas, spaces and new lines and drops duplicates', () => {
		expect(parseRanges('192.0.2.0/24, 2001:db8::/32\n192.0.2.0/24  10.0.0.0/8')).toEqual([
			'192.0.2.0/24',
			'2001:db8::/32',
			'10.0.0.0/8',
		])
	})

	it('answers no ranges for blank input', () => {
		expect(parseRanges('  ')).toEqual([])
		expect(parseRanges(null)).toEqual([])
	})
})

describe('shouldOfferRotation', () => {
	it('offers rotation once an approved window has ended', () => {
		expect(shouldOfferRotation([approved], new Date('2026-11-16T17:00:00Z'))).toBe(true)
		expect(shouldOfferRotation([approved], new Date('2026-11-16T15:00:00Z'))).toBe(false)
		expect(shouldOfferRotation([{ status: 'denied' }], new Date('2026-11-16T17:00:00Z'))).toBe(false)
		expect(shouldOfferRotation(undefined, new Date())).toBe(false)
	})
})
