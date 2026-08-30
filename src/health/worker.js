/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Dedicated web worker for password-health analysis.
 *
 * The main thread posts `{ rows, options }` (decrypted values included), the
 * worker runs the pure {@link analyse} engine off the main thread (zxcvbn over a
 * large vault is CPU-heavy), posts the findings + summary + score back, and
 * drops its plaintext references immediately after the pass. The worker holds no
 * long-lived state; the store terminates it on lock so no decrypted material
 * survives a locked vault (password-health design D2).
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */

import { analyse } from './engine.js'

self.addEventListener('message', async (event) => {
	let { rows, options } = event.data || {}
	const requestId = event.data?.requestId
	try {
		// breachResults arrives as a plain entries array (structured-clone safe).
		if (options && Array.isArray(options.breachResults)) {
			options = { ...options, breachResults: new Map(options.breachResults) }
		}
		const result = await analyse(rows || [], options || {})
		self.postMessage({ requestId, ok: true, result })
	} catch (e) {
		self.postMessage({ requestId, ok: false, error: String(e?.message || e) })
	} finally {
		// Drop plaintext references held by this message scope.
		rows = null
		options = null
	}
})
