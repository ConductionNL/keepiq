/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Helpers for use-only copies and shares that end by themselves
 * (sharing-use-only-and-expiring-shares). Use-only is enforced by Keepiq's
 * own clients: every surface that would show, copy, export or edit a value
 * asks `isUseOnly()` first.
 */

/**
 * Whether a secret is a use-only copy: Keepiq's apps fill it but never show,
 * copy, export or edit its value.
 *
 * @param {object|null|undefined} secret A secret row or decrypted secret.
 * @return {boolean}
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */
export function isUseOnly(secret) {
	return secret?.useOnly === true
}

/**
 * Whether the holder's access to a copy has ended.
 *
 * @param {object|null|undefined} secret A secret row.
 * @param {Date} [now] The current time.
 * @return {boolean}
 * @spec openspec/specs/expiring-shares/spec.md#requirement-offline-copies-respect-the-end-date
 */
export function isAccessExpired(secret, now = new Date()) {
	const end = secret?.accessExpiresAt
	if (typeof end !== 'string' || end === '') {
		return false
	}
	const at = new Date(end)
	return !Number.isNaN(at.getTime()) && at <= now
}

/**
 * The server timestamp for an "access ends on" date: the end of that day,
 * local time, so access lasts the whole day the owner picked.
 *
 * @param {string} date YYYY-MM-DD, or '' for no end.
 * @return {string|null} ISO 8601, or null.
 * @spec openspec/specs/expiring-shares/spec.md#requirement-shares-and-memberships-can-carry-an-end-date
 */
export function accessEndTimestamp(date) {
	if (typeof date !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
		return null
	}
	const end = new Date(`${date}T23:59:59`)
	return Number.isNaN(end.getTime()) ? null : end.toISOString()
}

/**
 * The request fields for a restriction picked in ShareRestrictionFields.
 *
 * @param {{useOnly: boolean, endDate: string}} restriction The picked options.
 * @return {{useOnly: boolean, expiresAt: string|null}}
 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-2.3
 */
export function restrictionPayload(restriction) {
	return {
		useOnly: restriction?.useOnly === true,
		expiresAt: accessEndTimestamp(restriction?.endDate ?? ''),
	}
}
