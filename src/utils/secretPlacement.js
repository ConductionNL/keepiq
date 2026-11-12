/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * The plain name, url, type and folder a new secret is created with, the
 * optional ones null when absent. Shared by the vault's own contribution
 * path and the secrets written for an application.
 *
 * @param {object} data The new secret's plaintext fields.
 * @return {{name: string, url: string|null, typeId: string|null, folderId: string|null}}
 * @spec openspec/specs/application-mgmt/spec.md#requirement-attribute-secrets-to-application
 */
export function secretPlacement(data) {
	return {
		name: data.name,
		url: data.url ?? null,
		typeId: data.typeId ?? null,
		folderId: data.folderId ?? null,
	}
}
