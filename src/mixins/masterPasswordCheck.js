/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { verifyMasterPassword } from '../crypto/reauth.js'

/**
 * A fresh master-password re-auth for a dialog that writes or sends vault
 * data: a client-side proof of knowledge of the typed `masterPassword`
 * against the session's wrapped private key. The dialog provides
 * `sessionStore`, `masterPassword` and `error`; `masterPasswordChecked`
 * also uses `busy`.
 */
export default {
	computed: {
		/**
		 * Whether the confirm button waits: nothing typed yet, or a check runs.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		masterPasswordPending() {
			return this.masterPassword.length === 0 || this.busy
		},
	},

	methods: {
		/**
		 * Whether the typed master password opens the vault key; when it does
		 * not, the dialog shows why.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		async masterPasswordVerified() {
			const ok = await verifyMasterPassword(
				this.sessionStore.encryptedPrivateKey,
				this.masterPassword,
			)
			if (!ok) {
				this.error = this.t('keepiq', 'Incorrect master password')
			}
			return ok
		},

		/**
		 * One guarded check of the typed master password: ignored while a check
		 * runs or nothing is typed, and the field is cleared afterwards either way.
		 *
		 * @return {Promise<boolean>} Whether the password opens the vault key
		 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
		 */
		async masterPasswordChecked() {
			if (this.busy || this.masterPassword.length === 0) {
				return false
			}
			this.busy = true
			this.error = null
			try {
				return await this.masterPasswordVerified()
			} finally {
				this.masterPassword = ''
				this.busy = false
			}
		},
	},
}
