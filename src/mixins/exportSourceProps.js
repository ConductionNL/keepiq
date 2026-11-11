/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The props of a dialog that writes or sends the vault's decrypted secrets:
 * whether it is open, the secrets, how many could not be decrypted and are
 * not among them, and the folder rows.
 */
export default {
	props: {
		/** Whether the dialog is open. */
		open: {
			type: Boolean,
			default: false,
		},

		/** Already-decrypted secrets. */
		secrets: {
			type: Array,
			default: () => [],
		},

		/** How many secrets could not be decrypted and are not in `secrets`. */
		skipped: {
			type: Number,
			default: 0,
		},

		/** Folder rows ({ id, name, parentId }). */
		folders: {
			type: Array,
			default: () => [],
		},
	},
}
