/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Whether a honey alert's accessor is snoozed: only while the snooze lies
 * ahead. Shared by the secret's honey panel and the admin honey section.
 *
 * @param {object} alert The alert row.
 * @return {boolean}
 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
 */
export function isSnoozed(alert) {
	return (
		!!alert.snoozedUntil
		&& new Date(alert.snoozedUntil).getTime() > Date.now()
	)
}
