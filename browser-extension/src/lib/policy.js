/**
 * The org password policy on the extension's save path (keepiq#746).
 *
 * The web app refuses a manual value below the policy's strength floor, or
 * one found in known breaches when the policy blocks those. A login saved
 * from the extension skipped both. This module applies the SAME rules
 * (`src/policy/rules.js`, bundled from the web app's source) to the same
 * policy (`GET /api/settings/policy`), before the value is encrypted.
 *
 * Honest-client model, as in the web app: the server cannot see the value.
 * A policy or breach service that does not answer never blocks a save.
 *
 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
 */

import { checkValueWith } from '../../../src/health/hibpMatch.js'
import { hibpBlockApplies, scoreShortfall } from '../../../src/policy/rules.js'

/** The type a login saved from the extension gets (the server default). */
export const EXTENSION_SAVE_TYPE = 'login'

/**
 * Why the policy refuses this value, or null when it may be saved.
 *
 * @param {object|null} policy The policy from the server, or null when unavailable.
 * @param {string} value The captured password (stays in the worker).
 * @param {Function} fetchRange Resolves a 5-char SHA-1 prefix to the suffix list.
 * @param {string} [typeName] The secret type the value is saved as.
 * @return {Promise<string|null>} The refusal reason, or null.
 */
export async function policyRefusal(
	policy,
	value,
	fetchRange,
	typeName = EXTENSION_SAVE_TYPE,
) {
	const shortfall = scoreShortfall(policy, typeName, value)
	if (shortfall !== null) {
		return `Password strength ${shortfall.score} is below your organisation's minimum of ${shortfall.floor}.`
	}
	if (hibpBlockApplies(policy, typeName, value)) {
		const result = await checkValueWith(value, fetchRange)
		if (result.status === 'breached') {
			return `This password appears in known breaches ${result.count} times. Choose another.`
		}
	}
	return null
}
