/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { evaluateHibp, fetchPolicy } from '../policy/policy.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { missingRequired } from '../utils/typedFields.js'

/**
 * What the create and edit secret dialogs check before a save: loading the
 * org policy and the secret types, the Save guard with the required typed
 * fields, and the breached-value block.
 *
 * The host provides `policy`, `canSubmit`, `typedFields`, `typedValues`,
 * `typedMissing`, `selectedTypeName`, `value`, `error` and `saving`.
 */
export const secretSaveGuards = {
	methods: {
		/**
		 * Load the org policy, then the secret types when none are cached.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
		 */
		async loadPolicyAndTypes() {
			this.policy = await fetchPolicy()
			const typeStore = useSecretTypeStore()
			if (typeStore.types.length === 0) {
				await typeStore.fetchTypes()
			}
		},

		/**
		 * Whether the save stops before it starts: Save is not allowed, or a
		 * required field of the type is empty, which is then marked (admin-18).
		 *
		 * @return {boolean} True when the save must not run.
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		saveBlocked() {
			if (!this.canSubmit) {
				return true
			}
			this.typedMissing = missingRequired(this.typedFields, this.typedValues)
			return this.typedMissing.length > 0
		},

		/**
		 * The HIBP block (org-password-policies §4.2): a k-anonymity check
		 * BEFORE encryption, so only the 5-char prefix leaves the browser. A
		 * breached value shows the reason and ends the save.
		 *
		 * @return {Promise<boolean>} True when the value is refused.
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		async breachBlocked() {
			const hibpReason = await evaluateHibp(
				this.policy,
				this.selectedTypeName,
				this.value,
			)
			if (hibpReason === null) {
				return false
			}
			this.error = hibpReason
			this.saving = false
			return true
		},
	},
}
