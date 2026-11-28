/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The fields of one login that a printed sheet carries, shared by the sheet
 * and the dialog that guards it. The one-time code seed is deliberately not
 * one of them (vault-item-clone-preview-and-print D4).
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */
export default {
	/** The login's name. */
	name: {
		type: String,
		default: '',
	},

	/** The login's address. */
	url: {
		type: String,
		default: '',
	},

	/** The login's username. */
	login: {
		type: String,
		default: '',
	},

	/** The decrypted password. */
	password: {
		type: String,
		required: true,
	},
}
