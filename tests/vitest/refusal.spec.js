/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Keepiq refuses on its OCS routes with 428 and an `error` code: Nextcloud
 * turns a 403 there into an HTTP 200 envelope (keepiq#673, measured live
 * 4 Oct 2026). The web app reads 428 the same as a 403.
 */
import { describe, expect, it } from 'vitest'
import { classifyReplayError } from '../../src/offline/queue.js'
import { isRefusal, refusalCode } from '../../src/utils/refusal.js'

describe('isRefusal', () => {
	it('reads 403 and 428 as a refusal, nothing else', () => {
		expect(isRefusal({ response: { status: 403 } })).toBe(true)
		expect(isRefusal({ response: { status: 428 } })).toBe(true)
		for (const status of [200, 400, 404, 409, 423, 500]) {
			expect(isRefusal({ response: { status } })).toBe(false)
		}
		expect(isRefusal(new Error('Network Error'))).toBe(false)
	})

	it('names the code: error first, then a policy code', () => {
		expect(
			refusalCode({
				response: { status: 428, data: { error: 'key_proof_required' } },
			}),
		).toBe('key_proof_required')
		expect(
			refusalCode({
				response: {
					status: 403,
					data: { code: 'export_disabled_by_policy' },
				},
			}),
		).toBe('export_disabled_by_policy')
		expect(refusalCode({ response: { status: 428, data: {} } })).toBe(null)
	})
})

describe('offline replay of a refused write', () => {
	it('moves a 428 refusal to the failed list like a 403', () => {
		expect(classifyReplayError({ response: { status: 428 } })).toBe('failed')
		expect(classifyReplayError({ response: { status: 403 } })).toBe('failed')
	})
})
