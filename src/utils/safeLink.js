/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Which stored URLs become links. A value can come from someone else, through
 * a share, an import or a fill link, so only web and mail addresses are
 * linked: a `javascript:` or `data:` URL, or any other scheme, shows as text.
 */

/** The schemes a stored URL is linked for. */
const LINKED_SCHEMES = Object.freeze(['http:', 'https:', 'mailto:'])

/**
 * The href for a stored URL, or null when it is shown as text.
 *
 * @param {string|null|undefined} value The stored URL.
 * @return {string|null} The URL when its scheme is linked, else null.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
 */
export function linkHref(value) {
	try {
		return LINKED_SCHEMES.includes(new URL(value).protocol) ? value : null
	} catch {
		return null
	}
}
