/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The single place the public fill-link URL is built.
 *
 * It lives here because getting it wrong is not a cosmetic bug. Two forms exist
 * and only one works for the person the link is for:
 *
 *   /apps/keepiq/public#/share/request/<token>   200 — the anonymous SPA shell
 *   /apps/keepiq/share/request/<token>           401 — requires a Nextcloud account
 *   /api/v1/public/secret-requests/<token>        200 — JSON, for machines
 *
 * The dialog shipped the third form once and the second form before that, so a
 * requester handed a recipient either raw JSON or a login wall. With the URL
 * built in one function, a second consumer cannot reintroduce a variant: the
 * list and the dialog now produce the same string by construction.
 */

import { generateUrl } from '@nextcloud/router'

/**
 * Build the absolute, human-openable fill link for a request token.
 *
 * The recipient has no Nextcloud account, so this must be the anonymous shell
 * (`publicShell#page`) carrying the router's hash route — never the authenticated
 * path and never the JSON endpoint.
 *
 * @param {string} token The request's fill token.
 *
 * @return {string} The absolute URL, or '' when there is no token.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-link-recovery
 */
export function fillLinkFor(token) {
	if (!token) {
		return ''
	}

	return (
		generateUrl('/apps/keepiq/public', {}, { absolute: true })
		+ `#/share/request/${token}`
	)
}
