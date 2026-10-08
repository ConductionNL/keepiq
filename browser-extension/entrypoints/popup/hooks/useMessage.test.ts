import { beforeEach, describe, expect, it, vi } from 'vitest'
import { NOT_RESPONDING, sendMessage } from './useMessage'

const notResponding = { ok: false, code: 'unknown', message: NOT_RESPONDING }

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

function reply(value: unknown) {
	vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(value as never)
}

describe('sendMessage', () => {
	it('passes a valid result through', async () => {
		const state = { screen: 'add_account', accounts: [], active: null, notice: null, canAddAccount: true }
		reply({ ok: true, state })
		expect(await sendMessage({ kind: 'vault.status' })).toEqual({ ok: true, state })
		reply({ ok: false, code: 'invalid_master_password', message: 'Invalid master password' })
		expect(await sendMessage({ kind: 'vault.status' })).toEqual({ ok: false, code: 'invalid_master_password', message: 'Invalid master password' })
	})

	it('turns no answer into an error instead of throwing', async () => {
		// A background with no listener for the kind replies undefined.
		reply(undefined)
		expect(await sendMessage({ kind: 'vault.status' })).toEqual(notResponding)
	})

	it('rejects the reply of a stale background', async () => {
		// What the old scaffold's service worker answered when Chromium kept running it.
		reply({ enabled: true, activeTabs: 0 })
		expect(await sendMessage({ kind: 'vault.status' })).toEqual(notResponding)
	})

	it.each([null, 'ok', { ok: true }, { ok: true, state: {} }, { ok: false, code: 'x' }])('rejects the malformed reply %j', async (value) => {
		reply(value)
		expect(await sendMessage({ kind: 'vault.status' })).toEqual(notResponding)
	})

	it('turns a rejected send into an error', async () => {
		vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('Could not establish connection. Receiving end does not exist.'))
		expect(await sendMessage({ kind: 'vault.status' })).toEqual(notResponding)
	})
})
