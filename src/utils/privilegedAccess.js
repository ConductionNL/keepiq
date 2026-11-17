/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Helpers for privileged logins (capability-requirements-from-the-matrix
 * task 3.1): the state of an access request's window, the conversion of a
 * datetime-local input to an ISO moment, and the address ranges an owner
 * types. The server checks every window and range again; these only shape
 * what the panel shows and sends.
 */

/**
 * The state of an access request at a moment.
 *
 * @param {object} request The request as the API serialises it.
 * @param {Date} now The moment to judge at.
 * @return {'pending'|'denied'|'upcoming'|'active'|'ended'} The state.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
export function requestState(request, now) {
	if (request?.status === 'denied') {
		return 'denied'
	}
	if (
		request?.status !== 'approved'
		|| !request.approvedStart
		|| !request.approvedEnd
	) {
		return 'pending'
	}
	if (now < new Date(request.approvedStart)) {
		return 'upcoming'
	}
	// The end is exclusive, as on the server.
	if (now >= new Date(request.approvedEnd)) {
		return 'ended'
	}
	return 'active'
}

/**
 * An ISO moment from a datetime-local input value, or '' when empty or not a date.
 *
 * @param {string} value The input value (`YYYY-MM-DDTHH:mm`, local time).
 * @return {string} The ISO moment in UTC, or ''.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
export function localInputToIso(value) {
	if (!value) {
		return ''
	}
	const moment = new Date(value)
	if (Number.isNaN(moment.getTime())) {
		return ''
	}
	return moment.toISOString()
}

/**
 * The address ranges an owner typed, split on commas, spaces or new lines.
 *
 * @param {string} text The typed ranges.
 * @return {string[]} The non-empty ranges, in order, without duplicates.
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
export function parseRanges(text) {
	const ranges = String(text ?? '')
		.split(/[\s,]+/)
		.map((range) => range.trim())
		.filter((range) => range !== '')
	return [...new Set(ranges)]
}

/**
 * Whether an owner should be told to rotate the login: a window that was
 * approved has ended, so the recipient may still know the value.
 *
 * @param {object[]} requests The requests of the login.
 * @param {Date} now The moment to judge at.
 * @return {boolean}
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
export function shouldOfferRotation(requests, now) {
	return (requests ?? []).some((request) => requestState(request, now) === 'ended')
}
