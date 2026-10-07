/**
 * Keepiq's OCS routes refuse with 428 and an `error` code, because Nextcloud
 * turns a 403 there into an HTTP 200 OCS envelope (keepiq#673, measured live
 * 4 Oct 2026). The extension reads the code, shows the server's reason for a
 * policy refusal, and does not take an OCS failure envelope for a success.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSecret } from '../../browser-extension/src/lib/api.js'
import { writeErrorMessage } from '../../browser-extension/src/lib/item-form.js'

const CONFIG = { url: 'https://cloud.example/', user: 'ann', appPassword: 'app-pw' }

/**
 * A fetch Response stand-in.
 *
 * @param {number} status The status.
 * @param {object} body The JSON body.
 * @return {object}
 */
function reply(status, body) {
	return {
		ok: status >= 200 && status < 300,
		status,
		text: async () => JSON.stringify(body),
		json: async () => body,
	}
}

describe('a refused request', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('carries the status and the refusal code', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(
				reply(428, {
					error: 'org_ownership_required',
					code: 'org_ownership_required',
					message: 'Save work logins in a team folder',
				}),
			),
		)

		const refusal = await createSecret(CONFIG, { name: 'x' }).catch((e) => e)
		expect(refusal.status).toBe(428)
		expect(refusal.code).toBe('org_ownership_required')
	})

	it('does not take an OCS failure envelope for a success', async () => {
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue(
				reply(200, {
					ocs: {
						meta: {
							status: 'failure',
							statuscode: 403,
							message: 'Not allowed',
						},
						data: [],
					},
				}),
			),
		)

		const refusal = await createSecret(CONFIG, { name: 'x' }).catch((e) => e)
		expect(refusal).toBeInstanceOf(Error)
		expect(refusal.status).toBe(403)
	})
})

describe('the message for a refused write', () => {
	it('shows the reason of a policy refusal', () => {
		expect(
			writeErrorMessage({
				status: 428,
				body: '{"error":"org_ownership_required","code":"org_ownership_required","message":"Save work logins in a team folder"}',
			}),
		).toBe('Save work logins in a team folder')
	})

	it('reads a plain refusal as a blocked suite, as a 403 was', () => {
		expect(
			writeErrorMessage({
				status: 428,
				body: '{"error":"forbidden","message":"Suite revoked"}',
			}),
		).toMatch(/encryption suite is blocked/)
	})
})
