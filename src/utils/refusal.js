/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * How the web app recognises a refusal. Keepiq's OCS routes refuse with 428
 * and an `error` code, because Nextcloud turns a 403 on an OCSController into
 * an HTTP 200 OCS envelope that axios would read as a success (keepiq#673).
 * Routes on a plain controller still refuse with 403. Both mean the same.
 */

/**
 * The status Keepiq's OCS routes refuse with.
 */
export const REFUSAL_STATUS = 428

/**
 * Whether an axios error is a refusal (403 or 428).
 *
 * @param {object|null|undefined} error The axios error.
 * @return {boolean}
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
export function isRefusal(error) {
	const status = error?.response?.status
	return status === 403 || status === REFUSAL_STATUS
}

/**
 * The machine-readable code of a refusal: its `error`, else its policy `code`.
 *
 * @param {object|null|undefined} error The axios error.
 * @return {string|null}
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */
export function refusalCode(error) {
	const data = error?.response?.data
	return data?.error ?? data?.code ?? null
}
