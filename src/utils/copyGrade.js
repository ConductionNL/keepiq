/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Whether the holder of a copy may change its value, from the copy's
 * write-context (`GET /secrets/{id}/write-context`). The browser extension
 * applies the same rule.
 */

/** Grades that let a copy holder save for every holder (TeamFolderMember::WRITE_GRADES plus owner). */
export const COPY_WRITER_GRADES = ['owner', 'write', 'manage']

/**
 * Whether a secret is a copy its holder may only view: its value changes only
 * through a holder with a write grade.
 *
 * @param {object|null|undefined} context The write-context of the secret.
 * @param {string} id The secret the context was read for.
 * @return {boolean} False when the context is unknown or names no source.
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
 */
export function isViewOnlyCopy(context, id) {
	if (!context?.sourceSecretId) {
		return false
	}
	return (
		context.sourceSecretId !== id
		&& !COPY_WRITER_GRADES.includes(context.effectiveGrade)
	)
}
