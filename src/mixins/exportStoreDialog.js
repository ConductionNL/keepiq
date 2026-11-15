/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { useExportStore } from '../store/modules/export.js'
import { useSessionStore } from '../store/modules/session.js'

/**
 * What the export, GDPR export and account deletion dialogs share around
 * their action: the export and session stores, the export store's loading
 * flag, and closing, which clears the dialog before the parent hears of it.
 *
 * The host provides `reset()`, which clears what the user entered.
 */
export default {
	computed: {
		/**
		 * The export store.
		 *
		 * @return {object}
		 * @spec openspec/specs/secret-export/spec.md
		 */
		exportStore() {
			return useExportStore()
		},

		/**
		 * The session store.
		 *
		 * @return {object}
		 * @spec openspec/specs/secret-export/spec.md
		 */
		sessionStore() {
			return useSessionStore()
		},

		/**
		 * Whether an export or deletion is in flight (from the store).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		loading() {
			return this.exportStore.loading
		},
	},

	methods: {
		/**
		 * Handle open-state changes, clearing what was entered on close.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec openspec/specs/secret-export/spec.md
		 * @spec openspec/specs/gdpr-compliance/spec.md
		 */
		onUpdateOpen(value) {
			if (!value) {
				this.reset()
			}
			this.$emit('update:open', value)
		},
	},
}
