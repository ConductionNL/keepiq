/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * "Approve from another device" for the extension (crypto-new-device-approval
 * task 3.1). The worker, not the popup, holds the request: a popup closes as
 * soon as it loses focus, and the user approves on another screen. The
 * one-time X25519 private key and the request secret live only in this
 * module's memory. They are never written to extension storage, so a stopped
 * worker loses the request and the user starts again (design, risks).
 *
 * The approving device seals the raw unlock key to the one-time key; this
 * module opens it and hands it to the worker's raw-key unlock, then drops it.
 */

import {
	generateRecipientKeyPair,
	openUnlockKey,
	toBase64,
	verificationPhrase,
} from '../crypto/index.js'
import * as api from './api.js'

/** One open request per account: accountId => request with its secrets. */
const requests = new Map()

/** Statuses after which a request is over and its key is dropped. */
const FINAL = new Set(['denied', 'expired', 'consumed'])

/**
 * A label for this device, from the user agent: which browser, which system.
 *
 * @param {string} agent The user agent string.
 * @return {string} For example "Keepiq extension in Firefox on Linux".
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
export function deviceLabel(agent = '') {
	const browser =
		['Firefox', 'Edg', 'Chrome'].find((name) => agent.includes(name)) ?? ''
	const system =
		['Windows', 'Mac OS', 'Linux', 'CrOS'].find((name) => agent.includes(name))
		?? ''
	const names = { Edg: 'Edge', 'Mac OS': 'macOS', CrOS: 'ChromeOS' }
	let label = 'Keepiq extension'
	if (browser) label += ' in ' + (names[browser] ?? browser)
	if (system) label += ' on ' + (names[system] ?? system)
	return label
}

/**
 * What the popup may see of a request: never the key or the secret.
 *
 * @param {object} request The stored request.
 * @return {{id: string, phrase: string, expiresAt: string, status: string}}
 */
function publicView(request) {
	return {
		id: request.id,
		phrase: request.phrase,
		expiresAt: request.expiresAt,
		status: request.status,
	}
}

/**
 * The open request of an account, as the popup may see it.
 *
 * @param {string} accountId The account.
 * @return {object|null}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
 */
export function current(accountId) {
	const request = requests.get(accountId)
	return request ? publicView(request) : null
}

/**
 * Start a request for an account, or return the one already open, so a
 * reopened popup does not spend another of the three requests an hour.
 *
 * @param {object} account The paired account.
 * @param {string} agent The user agent, for the device label.
 * @return {Promise<object>} The request as the popup shows it.
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
export async function start(account, agent = '') {
	const open = requests.get(account.id)
	if (open && !lapsed(open)) return publicView(open)
	requests.delete(account.id)

	if (!(await api.deviceApprovalEnabled(account))) {
		throw new Error(
			'Your organisation has turned off approval from another device.',
		)
	}
	const pair = await generateRecipientKeyPair()
	const created = await api.createDeviceApproval(account, {
		publicKey: toBase64(pair.publicKeyRaw),
		deviceLabel: deviceLabel(agent),
	})
	const request = {
		id: String(created.id),
		expiresAt: String(created.expiresAt ?? ''),
		secret: String(created.requestSecret ?? ''),
		privateKey: pair.privateKey,
		publicKeyRaw: pair.publicKeyRaw,
		phrase: await verificationPhrase(pair.publicKeyRaw),
		status: 'pending',
	}
	requests.set(account.id, request)
	return publicView(request)
}

/**
 * Whether a request is past its expiry on this device's clock.
 *
 * @param {object} request The stored request.
 * @return {boolean}
 */
function lapsed(request) {
	const end = Date.parse(request.expiresAt)
	return Number.isFinite(end) && end <= Date.now()
}

/**
 * Ask the server once. On approval, open the sealed unlock key with the
 * one-time key, unlock through `unlock(rawKey)` and drop the request. The raw
 * key is zeroed after use, whatever the unlock did.
 *
 * @param {object} account The paired account.
 * @param {function(Uint8Array): Promise<void>} unlock The worker's raw-key unlock.
 * @return {Promise<string>} `none`, `pending`, `unlocked`, `denied`, `expired` or `consumed`.
 * @spec openspec/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 */
export async function poll(account, unlock) {
	const request = requests.get(account.id)
	if (!request) return 'none'
	if (lapsed(request)) {
		requests.delete(account.id)
		return 'expired'
	}

	const answer = await api.pickupDeviceApproval(
		account,
		request.id,
		request.secret,
	)
	const status = String(answer?.status ?? 'pending')
	if (status === 'approved' && answer?.sealedUnlockKey) {
		// One pickup only: the server has already cleared the sealed key.
		requests.delete(account.id)
		let raw
		try {
			raw = await openUnlockKey(
				answer.sealedUnlockKey,
				request.privateKey,
				request.publicKeyRaw,
				request.id,
			)
		} catch {
			throw new Error(
				'The approval could not be opened on this device. Start again.',
			)
		}
		try {
			await unlock(raw)
		} finally {
			raw.fill(0)
		}
		return 'unlocked'
	}
	if (FINAL.has(status)) {
		requests.delete(account.id)
	} else {
		request.status = status
	}
	return status
}

/**
 * Stop waiting: drop the key and end the request on the server, so it no
 * longer shows as pending on the user's other devices.
 *
 * @param {object} account The paired account.
 * @return {Promise<void>}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */
export async function cancel(account) {
	const request = requests.get(account.id)
	requests.delete(account.id)
	if (!request) return
	try {
		await api.endDeviceApproval(account, request.id)
	} catch {
		// The request expires on its own.
	}
}

/**
 * Drop an account's request without a server call (unpair).
 *
 * @param {string} accountId The account.
 * @return {void}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 */
export function forget(accountId) {
	requests.delete(accountId)
}
