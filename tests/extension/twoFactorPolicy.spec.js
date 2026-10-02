/**
 * @spec openspec/changes/admin-vault-policies/tasks.md#3.4
 *
 * The extension names two_factor_required when the server withholds the
 * wrapped key, instead of failing inside the decryption.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fetchActiveSuite } from '../../browser-extension/src/lib/api.js'

const config = { url: 'https://cloud.example', user: 'frank', appPassword: 'app' }

/**
 * Answer GET /api/v1/suites with the given body.
 *
 * @param {Array<object>} suites The suites.
 */
function serve(suites) {
	globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, json: async () => suites })
}

describe('fetchActiveSuite under the two-factor policy', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('throws an error carrying two_factor_required', async () => {
		serve([{ id: 's', status: 'active', certificate: 'C', unlockBlocked: 'two_factor_required' }])

		await expect(fetchActiveSuite(config)).rejects.toMatchObject({ code: 'two_factor_required' })
	})

	it('returns an ordinary suite unchanged', async () => {
		serve([{ id: 's', status: 'active', certificate: 'C', privateKey: 'K' }])

		await expect(fetchActiveSuite(config)).resolves.toMatchObject({ privateKey: 'K' })
	})
})
