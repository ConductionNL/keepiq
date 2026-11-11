/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The labels three views give a status or refusal reason: every known value,
 * and what an unknown, missing or prototype-named value falls back to.
 *
 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
 */

import { describe, expect, it } from 'vitest'
import SecretRequestList from '../../src/components/secretRequest/SecretRequestList.vue'
import ApplicationDetail from '../../src/views/ApplicationDetail.vue'
import SecretRequestFill from '../../src/views/SecretRequestFill.vue'

describe('ApplicationDetail statusLabel', () => {
	const label = (application) =>
		ApplicationDetail.computed.statusLabel.call({
			application,
			t: (app, text) => `${app}:${text}`,
		})

	it('labels the known statuses and passes any other one through', () => {
		expect(label({ status: 'active' })).toBe('keepiq:Active')
		expect(label({ status: 'pending' })).toBe('keepiq:Pending')
		expect(label({ status: 'revoked' })).toBe('revoked')
		expect(label({ status: 'toString' })).toBe('toString')
		expect(label({ status: '' })).toBe('')
		expect(label({})).toBe('')
		expect(label(null)).toBe('')
		expect(label(undefined)).toBe('')
	})
})

describe('SecretRequestList statusLabel', () => {
	const label = (status) => SecretRequestList.methods.statusLabel(status)

	it('labels every request status and passes any other one through', () => {
		expect(label('pending')).toBe('Pending')
		expect(label('fulfilled')).toBe('Fulfilled')
		expect(label('declined')).toBe('Declined')
		expect(label('locked')).toBe('Locked')
		expect(label('expired')).toBe('Expired')
		expect(label('mystery')).toBe('mystery')
		expect(label('constructor')).toBe('constructor')
		expect(label(undefined)).toBeUndefined()
		expect(label(null)).toBeNull()
	})
})

describe('SecretRequestFill messageForReason', () => {
	const message = (reason) => SecretRequestFill.methods.messageForReason(reason)

	it('words every refusal reason and has nothing for any other one', () => {
		expect(message('fulfilled')).toBe('This request has already been fulfilled.')
		expect(message('declined')).toBe('This request was declined.')
		expect(message('locked')).toBe(
			'This request is temporarily unavailable while a compromise recovery is in progress.',
		)
		expect(message('expired')).toBe('This request has expired.')
		expect(message('unavailable')).toBe('This request is no longer available.')
		expect(message('not-found')).toBe('This request could not be found.')
		expect(message('unknown')).toBeNull()
		expect(message('hasOwnProperty')).toBeNull()
		expect(message(undefined)).toBeNull()
		expect(message(null)).toBeNull()
	})
})
