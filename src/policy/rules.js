/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The org password-policy rules, free of Nextcloud page dependencies.
 *
 * The web app (`policy.js`) and the browser extension both judge a value
 * against the same fetched policy. Keeping the rules here, with no axios,
 * router or translation import, lets the extension's service worker bundle
 * them as they are, so the two clients cannot drift apart (keepiq#746).
 *
 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
 */

import zxcvbn from 'zxcvbn'

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
 * Whether the policy applies to this value at all.
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @param {string} value The manual value.
 * @return {boolean}
 */
function applies(policy, typeName, value) {
	return Boolean(policy)
		&& policy.policy_enabled === true
		&& !isExemptType(policy, typeName)
		&& typeof value === 'string'
		&& value !== ''
}

/**
 * The zxcvbn floor verdict for a value.
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @param {string} value The manual value.
 * @return {{score: number, floor: number}|null} The shortfall, or null when the value passes.
 */
export function scoreShortfall(policy, typeName, value) {
	if (!applies(policy, typeName, value)) {
		return null
	}
	const floor = Number.parseInt(policy.min_zxcvbn_score, 10) || 0
	if (floor <= 0) {
		return null
	}
	const score = zxcvbn(value).score
	return score < floor ? { score, floor } : null
}

/**
 * Whether the policy asks for a breach check of this value.
 *
 * @param {object|null} policy The fetched policy.
 * @param {string} typeName The secret's system type name.
 * @param {string} value The manual value.
 * @return {boolean}
 */
export function hibpBlockApplies(policy, typeName, value) {
	return applies(policy, typeName, value) && policy.block_on_hibp_hit === true
}
