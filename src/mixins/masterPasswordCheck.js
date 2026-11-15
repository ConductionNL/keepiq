/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { verifyMasterPassword } from '../crypto/reauth.js'

/**
 * A fresh master-password re-auth for a dialog that writes or sends vault
 * data: a client-side proof of knowledge of the typed `masterPassword`
 * against the session's wrapped private key. The dialog provides
 * `sessionStore`, `masterPassword` and `error`.
 */
export default {
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
	},
}
