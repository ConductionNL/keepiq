/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of revoking the encryption suite from the app shell:
 * what the store is asked, and what the form shows afterwards.
 */

import { describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'

function shell(revokeSuite) {
	const context = {
		suiteStore: { revokeSuite },
		revoking: false,
		revokeError: 'old error',
		revokeSuccess: true,
		revokeConfirm: true,
		revokeReason: 'lost laptop',
		revokePassword: 'master',
		revokeEmergencyCount: 0,
	}
	for (const [name, method] of Object.entries(App.methods)) {
		context[name] = method.bind(context)
	}
	return context
}

describe('App.vue handleRevoke', () => {
	it('revokes with the reason, the password and the loss flag, then clears the form', async () => {
		const revokeSuite = vi.fn().mockResolvedValue(undefined)
		const context = shell(revokeSuite)
		context.revokeEmergencyCount = 2

		await context.handleRevoke(true)

		expect(revokeSuite).toHaveBeenCalledWith('lost laptop', 'master', true)
		expect(context).toMatchObject({
			revoking: false,
			revokeError: null,
			revokeSuccess: true,
			revokeConfirm: false,
			revokeReason: '',
			revokePassword: '',
			revokeEmergencyCount: 0,
		})
	})

	it('passes no loss acceptance by default', async () => {
		const revokeSuite = vi.fn().mockResolvedValue(undefined)
		await shell(revokeSuite).handleRevoke()

		expect(revokeSuite).toHaveBeenCalledWith('lost laptop', 'master', false)
	})

	it.each([
		[{ usableEmergencyContacts: 3 }, 3],
		[{}, 1],
	])('asks to accept the emergency-access loss, keeping the form (%#)', async (data, count) => {
		const context = shell(vi.fn().mockRejectedValue({
			response: { status: 409, data: { error: 'emergency_access_present', ...data } },
		}))

		await context.handleRevoke()

		expect(context).toMatchObject({
			revoking: false,
			revokeError: null,
			revokeSuccess: false,
			revokeConfirm: true,
			revokeReason: 'lost laptop',
			revokePassword: 'master',
			revokeEmergencyCount: count,
		})
	})

	it.each([
		[{ response: { status: 409, data: { error: 'other', message: 'conflict' } } }, 'conflict'],
		[{ response: { status: 403, data: { message: 'refused' } }, message: 'x' }, 'refused'],
		[{ message: 'network down' }, 'network down'],
		[{}, 'Failed to revoke suite'],
	])('shows any other failure and keeps the form (%#)', async (failure, text) => {
		const context = shell(vi.fn().mockRejectedValue(failure))

		await context.handleRevoke()

		expect(context).toMatchObject({
			revoking: false,
			revokeError: text,
			revokeSuccess: false,
			revokeConfirm: true,
			revokePassword: 'master',
			revokeEmergencyCount: 0,
		})
	})
})
