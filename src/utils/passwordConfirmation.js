/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Complete Nextcloud's password confirmation (sudo) before a request whose
 * endpoint carries `#[PasswordConfirmationRequired]`: it resolves at once
 * while sudo is still fresh, otherwise once the user has confirmed, and
 * rejects when the prompt is cancelled.
 *
 * `@nextcloud/password-confirmation` is imported lazily so its
 * `@nextcloud/vue` dialog stays off the static load path of the stores and
 * sections that use it.
 *
 * @return {Promise<void>}
 * @spec openspec/specs/encryption-suites/spec.md#requirement-re-enrolment-after-a-revocation-requires-a-fresh-password-confirmation
 */
export async function confirmPasswordFirst() {
	const { confirmPassword } = await import('@nextcloud/password-confirmation')
	await confirmPassword()
}
