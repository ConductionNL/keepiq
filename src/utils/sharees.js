/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Rows of Nextcloud's sharee search, read the same way for users and groups.
 */

/**
 * Sharee rows as recipients, each id once, labelled by the display name or
 * else the id; a row without an id is passed over.
 *
 * @param {Array<object>} rows The sharee rows.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
export function uniqueSharees(rows) {
	const recipients = []
	const seen = new Set()
	for (const row of rows) {
		const id = String(row?.value?.shareWith ?? '')
		if (id === '' || seen.has(id)) {
			continue
		}
		seen.add(id)
		recipients.push({ id, label: String(row?.label || id) })
	}
	return recipients
}
