/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A fake owner server for `PUT /secrets/{id}/share-save` as the contract of
 * keepiq#1254 describes it: 409 `incomplete` until the lists are exactly the
 * required sets, staged chunks above 100 entries, and a 400 above the bound,
 * on an empty chunk, past ceil(rows/100) chunks or for a chunk with a patch.
 */
import axios from '@nextcloud/axios'
import { vi } from 'vitest'

export const BOUND = 100
export const SAVED_AT = '2026-10-01T10:00:05+00:00'

const LISTS = ['copies', 'skipped', 'federated', 'suspend']

/**
 * An axios-shaped rejection.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The body.
 * @param {object} [headers] Response headers, lower-case as axios gives them.
 * @return {object}
 */
export function httpError(status, data, headers = {}) {
	return Object.assign(new Error(`HTTP ${status}`), {
		response: { status, data, headers },
	})
}

/**
 * Settle a promise under `vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] })`,
 * moving the clock only while the code waits on a timer, so real crypto in
 * between never sees time pass.
 *
 * @param {Promise} promise The work.
 * @return {Promise<*>} Its value, or the error it rejected with.
 */
export async function settleWithTimers(promise) {
	let done = false
	const out = promise.then(
		(value) => value,
		(error) => error,
	)
	out.finally(() => (done = true))
	while (!done) {
		if (vi.getTimerCount() > 0) {
			await vi.runOnlyPendingTimersAsync()
		} else {
			await new Promise((resolve) => setImmediate(resolve))
		}
	}
	return out
}

/**
 * Wire axios to a fake server.
 *
 * @param {object} options The server.
 * @param {string} options.id The row being saved.
 * @param {object} [options.context] Write-context fields over an owner default.
 * @param {Array<object>|((n: number) => Array<object>)} [options.required] Required copies, or a function of the save number.
 * @param {Array<object>} [options.partners] Required partner shares.
 * @param {object} [options.suites] userId to certificate for users with a vault key.
 * @param {object} [options.lookup] cloudId to a lookup answer, or `{ reject }`.
 * @param {object} [options.rows] GET answers by the URL's last segment.
 * @param {object} [options.posts] Other POST answers by URL suffix, or a function of the body that may throw.
 * @param {{perMinute: number, retryAfter?: string}} [options.lookupLimit] The partner lookup limit, a 429 above it.
 * @param {(body: object, n: number) => void} [options.onSave] Called with each save body; may throw.
 * @return {{saves: Array, lookups: Array, plainPuts: Array, partnerLookups: Array}}
 */
export function shareSaveServer({
	id,
	context = {},
	required = [],
	partners = [],
	suites = {},
	lookup = {},
	rows = {},
	posts = {},
	lookupLimit = null,
	onSave = null,
}) {
	const log = { saves: [], lookups: [], plainPuts: [], partnerLookups: [] }
	let stage = null
	const lookupTimes = []

	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.endsWith('/write-context')) {
			return {
				data: {
					sourceSecretId: id,
					effectiveGrade: 'owner',
					ownerCertificate: null,
					sourceUpdatedAt: null,
					...context,
				},
			}
		}
		const rowId = url.split('/').pop()
		if (rows[rowId]) {
			return { data: rows[rowId] }
		}
		throw new Error(`unexpected GET ${url}`)
	})

	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.endsWith('/shares/recipient-certificates')) {
			log.lookups.push(body.userIds)
			return {
				data: {
					recipients: body.userIds.map((userId) =>
						suites[userId]
							? {
									userId,
									shareable: true,
									certificate: suites[userId],
								}
							: {
									userId,
									shareable: false,
									reason: 'no_active_suite',
								},
					),
				},
			}
		}
		if (url.endsWith('/federation/recipient-certificate')) {
			log.partnerLookups.push(body.cloudId)
			if (lookupLimit) {
				const recent = lookupTimes.filter((at) => Date.now() - at < 60000)
				if (recent.length >= lookupLimit.perMinute) {
					throw httpError(
						429,
						{ message: 'Too Many Requests' },
						lookupLimit.retryAfter === undefined
							? {}
							: { 'retry-after': lookupLimit.retryAfter },
					)
				}
				lookupTimes.push(Date.now())
			}
			const answer = lookup[body.cloudId]
			if (!answer || answer.reject) {
				throw (
					answer?.reject
					?? httpError(502, { message: 'partner_unreachable' })
				)
			}
			return { data: answer }
		}
		const other = Object.keys(posts).find((suffix) => url.endsWith(suffix))
		if (other) {
			const answer = posts[other]
			return { data: typeof answer === 'function' ? answer(body) : answer }
		}
		throw new Error(`unexpected POST ${url}`)
	})

	vi.spyOn(axios, 'put').mockImplementation(async (url, body) => {
		if (!url.endsWith('/share-save')) {
			log.plainPuts.push([url, body])
			return { data: { id, updatedAt: SAVED_AT } }
		}
		log.saves.push(JSON.parse(JSON.stringify(body)))
		onSave?.(body, log.saves.length)
		const local = (body.copies?.length ?? 0) + (body.skipped?.length ?? 0)
		const remote = (body.federated?.length ?? 0) + (body.suspend?.length ?? 0)
		if (local > BOUND || remote > BOUND) {
			throw httpError(400, { error: 'too_many_entries' })
		}
		const req =
			typeof required === 'function' ? required(log.saves.length) : required
		if (body.stage && body.stage.final === false) {
			if ('patch' in body || 'restoredFrom' in body) {
				throw httpError(400, { error: 'invalid_body' })
			}
			if (local + remote === 0) {
				throw httpError(400, { error: 'empty_chunk' })
			}
			if (body.stage.id && !stage) {
				throw httpError(404, { error: 'stage_unknown' })
			}
			if (!body.stage.id) {
				stage = {
					chunks: 0,
					...Object.fromEntries(LISTS.map((k) => [k, []])),
				}
			}
			const reach = Math.max(req.length, partners.length)
			if (++stage.chunks > Math.ceil(reach / BOUND)) {
				throw httpError(400, { error: 'too_many_chunks' })
			}
			for (const k of LISTS) {
				stage[k].push(...(body[k] ?? []))
			}
			log.chunks = (log.chunks ?? 0) + 1
			return { status: 202, data: { stageId: 'stage-1' } }
		}

		const all = (k) => [
			...(body.stage?.id ? (stage?.[k] ?? []) : []),
			...(body[k] ?? []),
		]
		const whole = (entry) =>
			['key', 'login', 'additionalFields'].every((f) => f in entry)
		if (
			'restoredFrom' in body
			&& (!whole(body.patch) || !all('copies').every(whole))
		) {
			throw httpError(400, { error: 'invalid_body' })
		}
		const reqIds = req.map((r) => r.secretId)
		const sent = [...all('copies'), ...all('skipped')].map((e) => e.secretId)
		const fedIds = partners.map((p) => p.shareId)
		const fedSent = [...all('federated'), ...all('suspend')].map(
			(e) => e.shareId,
		)
		const copies = {
			required: req,
			missing: reqIds.filter((x) => !sent.includes(x)),
			unexpected: sent.filter((x) => !reqIds.includes(x)),
			skipRefused: all('skipped')
				.filter((e) => {
					const holder = req.find((r) => r.secretId === e.secretId)
					return holder && suites[holder.targetUserId]
				})
				.map((e) => e.secretId),
		}
		const federated = {
			required: partners,
			missing: fedIds.filter((x) => !fedSent.includes(x)),
			unexpected: fedSent.filter((x) => !fedIds.includes(x)),
		}
		if (
			[copies.missing, copies.unexpected, copies.skipRefused].some(
				(l) => l.length,
			)
			|| [federated.missing, federated.unexpected].some((l) => l.length)
		) {
			throw httpError(409, { error: 'incomplete', copies, federated })
		}
		stage = null
		return {
			data: {
				secret: { id, updatedAt: SAVED_AT },
				sourceUpdatedAt: SAVED_AT,
				copies: {
					written: all('copies').length,
					skipped: all('skipped').length,
				},
				federated: {
					written: all('federated').length,
					suspended: all('suspend').length,
					ownerOnly: context.ownerOnly === true,
				},
			},
		}
	})

	return log
}
