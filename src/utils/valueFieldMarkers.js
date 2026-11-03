/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Whether a vault row holds a one-time code or passkeys. A row carries its
 * `totp` and `passkeys` as ciphertext, so only their presence is read.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @param {object|null|undefined} row A vault row.
 * @return {boolean} Whether it holds a one-time code (null and '' are none).
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function rowHasCode(row) {
	return typeof row?.totp === 'string' && row.totp !== ''
}

/**
 * @param {object|null|undefined} row A vault row.
 * @return {boolean} Whether it holds passkeys (null and '' are none).
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function rowHasPasskeys(row) {
	return typeof row?.passkeys === 'string' && row.passkeys !== ''
}

/**
 * The accessible name of a row that opens a secret, naming what it holds
 * besides its value. The count of passkeys is not known from ciphertext, so
 * the wording does not depend on it.
 *
 * @param {object} row A vault row.
 * @return {string} The label.
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */
export function rowOpenLabel(row) {
	const name = row?.name ?? ''
	const code = rowHasCode(row)
	const passkeys = rowHasPasskeys(row)
	if (code && passkeys) {
		return t('keepiq', 'Open {name}, has a one-time code and passkeys', {
			name,
		})
	}
	if (code) {
		return t('keepiq', 'Open {name}, has a one-time code', { name })
	}
	if (passkeys) {
		return t('keepiq', 'Open {name}, has passkeys', { name })
	}
	return t('keepiq', 'Open {name}', { name })
}
