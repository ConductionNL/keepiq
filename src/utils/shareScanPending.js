/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The answer the server gives a request that reaches a share row the pending
 * share quarantine scan cannot judge yet: 409 with `error: share_scan_pending`.
 * One translated text for every place that shows it.
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The error code of the refusal.
 */
export const SHARE_SCAN_PENDING = 'share_scan_pending'

/**
 * Whether an axios error is the share-scan-pending refusal.
 *
 * @param {object|null|undefined} error The axios error.
 * @return {boolean}
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
export function isShareScanPending(error) {
	return error?.response?.data?.error === SHARE_SCAN_PENDING
}

/**
 * The translated text of the refusal.
 *
 * @return {string}
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
export function shareScanPendingText() {
	return t(
		'keepiq',
		'This secret is waiting for a check of its shares that has not finished yet. Nothing was saved; try again later, or ask an administrator to check that background jobs run.',
	)
}
