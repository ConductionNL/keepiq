/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Client-side org password-policy evaluation (org-password-policies §4).
 *
 * Honest-client model: the browser fetches the read-only policy floor
 * and blocks a non-compliant manual value BEFORE encryption — the
 * server never inspects, scores, or gates a save on a secret value.
 * The HIBP check reuses the k-anonymity proxy: only the 5-character
 * SHA-1 prefix ever leaves the browser.
 *
 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import zxcvbn from 'zxcvbn'
import { checkValue } from '../health/hibp.js'

let cachedPolicy = null

/**
 * Fetch the read-only policy floor (cached per page load).
 *
 * @return {Promise<object|null>} The policy, or null when unavailable.
 */
export async function fetchPolicy() {
	if (cachedPolicy !== null) {
		return cachedPolicy
	}
	try {
		const response = await axios.get(
			generateUrl('/apps/keepiq/api/settings/policy'),
		)
		cachedPolicy = response?.data ?? null
	} catch {
		cachedPolicy = null
	}
	return cachedPolicy
}

/**
 * Drop the cached policy (tests / settings changes).
 *
 * @return {void}
 */
export function resetPolicyCache() {
	cachedPolicy = null
}

/**
 * Whether a secret type is exempt from the policy.
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @return {boolean}
 */
export function isExemptType(policy, typeName) {
	const exempt = Array.isArray(policy?.policy_exempt_types)
		? policy.policy_exempt_types
		: []
	return exempt.includes(typeName)
}

/**
 * Synchronous policy evaluation of a manual value: zxcvbn score floor.
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @param {string} value The manual value.
 * @return {{compliant: boolean, reason: string|null}}
 */
export function evaluateScore(policy, typeName, value) {
	if (
		!policy
		|| policy.policy_enabled !== true
		|| isExemptType(policy, typeName)
	) {
		return { compliant: true, reason: null }
	}
	if (typeof value !== 'string' || value === '') {
		return { compliant: true, reason: null }
	}
	const floor = Number.parseInt(policy.min_zxcvbn_score, 10) || 0
	if (floor <= 0) {
		return { compliant: true, reason: null }
	}
	const score = zxcvbn(value).score
	if (score < floor) {
		return {
			compliant: false,
			reason: t(
				'keepiq',
				'Value strength {score} is below the org minimum of {floor}',
				{ score, floor },
			),
		}
	}
	return { compliant: true, reason: null }
}

/**
 * Async HIBP gate: resolves to a blocking reason or null. Only runs
 * when the policy enables the block; an unavailable proxy NEVER blocks
 * (availability must not gate saves).
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @param {string} value The manual value.
 * @return {Promise<string|null>} The blocking reason, or null.
 */
export async function evaluateHibp(policy, typeName, value) {
	if (
		!policy
		|| policy.policy_enabled !== true
		|| policy.block_on_hibp_hit !== true
		|| isExemptType(policy, typeName)
		|| typeof value !== 'string'
		|| value === ''
	) {
		return null
	}
	const result = await checkValue(value)
	if (result.status === 'breached') {
		return t(
			'keepiq',
			'This value appears in known breaches {count} times — choose another',
			{ count: result.count },
		)
	}
	return null
}
