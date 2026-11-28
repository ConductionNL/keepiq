/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { evaluateHibp, fetchPolicy } from '../policy/policy.js'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { missingRequired } from '../utils/typedFields.js'

/**
 * What the create and edit secret dialogs check before a save: loading the
 * org policy and the secret types, the Save guard with the required typed
 * fields, the breached-value block, and why listed files hold the save back.
 *
 * The host provides `policy`, `canSubmit`, `canAddFiles`, `typedFields`,
 * `typedValues`, `typedMissing`, `selectedTypeName`, `value`, `error` and
 * `saving`.
 */
export const secretSaveGuards = {
	computed: {
		/**
		 * Why listed files cannot be added, '' when they can.
		 *
		 * @return {string}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-add-attachments-from-the-create-and-edit-secret-dialogs
		 */
		filesUnavailable() {
			if (this.canAddFiles) {
				return ''
			}
			return useOfflineStore().readOnly
				? t(
						'keepiq',
						'Files cannot be added while you are offline. Remove them to save.',
					)
				: t(
						'keepiq',
						'Files can only be added to a secret you own. Remove them to save.',
					)
		},
	},

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
