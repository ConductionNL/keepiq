/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how saveShared fills the lists an `incomplete` answer asks
 * for: local copies (owner and user holders, refused skips, certificate
 * lookups) and partner shares (verified, unverified, gone, unreachable,
 * failing on this server, too old for a value field, agreed to be suspended).
 * A seeded generator writes several hundred save scenarios of up to three
 * `incomplete` rounds; a few hand-written ones cover the edges. Each case
 * records every request with its body, every lookup, certificate and
 * write-context call in order, and the result or error, compared with
 * golden/share-save-fill.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-an-unreachable-partner-stops-the-save-unless-the-user-saves-without-them
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
let world = {}

/**
 * An axios-shaped rejection.
 *
 * @param {number} status The HTTP status.
 * @param {object} data The body.
 * @return {Error}
 */
function httpError(status, data) {
	return Object.assign(new Error(`HTTP ${status}`), {
		response: { status, data, headers: {} },
	})
}

/**
 * A JSON-safe copy of a request body.
 *
 * @param {*} value Any value.
 * @return {*}
 */
function plain(value) {
	return JSON.parse(
		JSON.stringify(value, (key, v) => (v === undefined ? '(undefined)' : v)),
	)
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			trace.push(['get', url])
			if (world.rowFails) {
				throw new Error('row load failed')
			}
			return { data: world.row }
		}),
		put: vi.fn(async (url, body) => {
			trace.push(['put', url, plain(body)])
			const answer = world.rounds.shift()
			if (!answer) {
				return { data: { secret: { id: 's1' }, saved: true } }
			}
			throw httpError(answer.status ?? 409, answer.body)
		}),
		post: vi.fn(async (url, body) => {
			trace.push(['post', url, plain(body)])
			return {
				data: {
					recipients: body.userIds.map((userId) =>
						world.suites[userId]
							? {
									userId,
									shareable: true,
									certificate: world.suites[userId],
								}
							: {
									userId,
									shareable: false,
									reason: 'no_active_suite',
								},
					),
				},
			}
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/x${url}` }))
vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async (pem) => `pk(${pem})`),
	rsaEncrypt: vi.fn(async (text, key) => `E[${key}]${text}`),
	rsaDecrypt: vi.fn(async (text) => `D(${text})`),
}))
vi.mock('../../src/crypto/rsa.js', () => ({
	importSpkiPublicKey: vi.fn(async (spki) => {
		trace.push(['spki', spki])
		return `spki(${spki})`
	}),
}))
vi.mock('../../src/store/modules/share.js', () => ({
	useShareStore: () => ({
		fetchWriteContext: async (id) => {
			trace.push(['context', id])
			world.contextCalls = (world.contextCalls ?? 0) + 1
			return {
				...world.context,
				ownerCertificate:
					world.context.ownerCertificate === null
						? null
						: `${world.context.ownerCertificate}#${world.contextCalls}`,
			}
		},
	}),
}))
vi.mock('../../src/store/modules/federatedShare.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		useFederatedShareStore: () => ({
			lookup: async (cloudId) => {
				trace.push(['lookup', cloudId])
				const answer = world.partners[cloudId] ?? { fail: 'server' }
				if (answer.fail) {
					throw world.failures[answer.fail](cloudId)
				}
				return {
					cloudId,
					fingerprint: `AB:CD:${cloudId.length}`,
					spki: `spki-${cloudId}`,
					...(answer.valueFields
						? { valueFields: answer.valueFields }
						: {}),
				}
			},
		}),
	}
})

const { FederatedCertificateError } =
	await import('../../src/crypto/federatedCertificate.js')
const { CloudIdMismatchError } =
	await import('../../src/store/modules/federatedShare.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')
const { forgetVerifiedPartners, saveShared } =
	await import('../../src/services/shareSave.js')

const FAILURES = {
	unverified: () => new FederatedCertificateError('untrusted_root'),
	mismatch: (cloudId) => new CloudIdMismatchError(cloudId),
	gone: () => httpError(404, { message: 'unknown_recipient' }),
	notPartner: () => httpError(403, { message: 'not_a_partner' }),
	goneWrongStatus: () => httpError(500, { message: 'unknown_recipient' }),
	unreachable: () => httpError(502, { message: 'partner_unreachable' }),
	server: () => httpError(500, { message: 'boom' }),
	plain: () => new Error('network'),
}

/**
 * A seeded random source (mulberry32).
 *
 * @param {number} seed The seed.
 * @return {() => number}
 */
function random(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6d2b79f5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

const USERS = ['u1', 'u2', 'u3', 'u4']
const CLOUDS = ['a@p1', 'b@p2', 'c@p3', 'd@p4']
const PARTNER_ANSWERS = [
	{},
	{},
	{},
	{ valueFields: ['totp'] },
	{ valueFields: ['totp'] },
	{ valueFields: ['totp', 'passkeys'] },
	{ valueFields: [] },
	...Object.keys(FAILURES).map((fail) => ({ fail })),
]

/**
 * A generated save scenario.
 *
 * @param {number} seed The seed.
 * @return {object}
 */
function generated(seed) {
	const r = random(seed)
	const pick = (list) => list[Math.floor(r() * list.length)]
	const some = (list) => list.filter(() => r() < 0.5)
	const copyPath = r() < 0.2
	const holders = [
		{ secretId: 'c-own', holder: 'owner' },
		...USERS.map((u) => ({
			secretId: `c-${u}`,
			holder: 'user',
			targetUserId: u,
		})),
		{ secretId: 'c-none', holder: 'user' },
		{ secretId: 'c-u1b', holder: 'user', targetUserId: 'u1' },
		{ secretId: 'c-own2', holder: 'owner', targetUserId: 'u2' },
	]
	const shares = CLOUDS.map((cloud, i) => ({
		shareId: `f${i}`,
		recipientCloudId: cloud,
	}))
	const rounds = []
	const count = 1 + Math.floor(r() * 3)
	for (let i = 0; i < count; i++) {
		const required = some(holders)
		rounds.push({
			body: {
				error: 'incomplete',
				copies: {
					required,
					...(r() < 0.4
						? {
								skipRefused: some(required).map((h) =>
									r() < 0.5
										? h.secretId
										: { secretId: h.secretId },
								),
							}
						: {}),
				},
				...(r() < 0.8 ? { federated: { required: some(shares) } } : {}),
			},
		})
	}
	if (r() < 0.1) {
		rounds.push({ status: 404, body: { error: 'stage_unknown' } })
	}
	const diffs = [
		{ key: 'k2' },
		{ key: 'k2', totp: 'seed' },
		{ passkeys: 'pk-json' },
		{ totp: '' },
		{ login: null, totp: 'seed', passkeys: 'pk' },
	]
	return {
		copyPath,
		plainDiff: pick(diffs),
		withoutUnreachable: some(shares.map((s) => s.shareId)),
		world: {
			context: {
				sourceSecretId: copyPath ? 'src' : 's1',
				effectiveGrade: copyPath
					? pick(['write', 'manage', 'read'])
					: 'owner',
				ownerCertificate: r() < 0.7 ? 'OWNER' : null,
				sourceUpdatedAt: null,
			},
			suites: Object.fromEntries(some(USERS).map((u) => [u, `CERT-${u}`])),
			partners: Object.fromEntries(
				CLOUDS.map((cloud) => [cloud, pick(PARTNER_ANSWERS)]),
			),
			row: {
				key: 'ck',
				login: r() < 0.5 ? 'cl' : null,
				totp: r() < 0.5 ? 'ct' : null,
				passkeys: r() < 0.3 ? 'cp' : null,
				updatedAt:
					r() < 0.9 ? '2026-10-01T10:00:00Z' : '2026-10-01T10:00:01Z',
			},
			rounds,
		},
	}
}

const CASES = {}
for (let seed = 1; seed <= 360; seed++) {
	CASES[`generated ${seed}`] = () => generated(seed)
}
const base = () => generated(7)
Object.assign(CASES, {
	'row load fails': () => {
		const c = base()
		c.world.rowFails = true
		c.world.partners['a@p1'] = {}
		c.world.rounds = [
			{
				body: {
					error: 'incomplete',
					federated: {
						required: [{ shareId: 'f0', recipientCloudId: 'a@p1' }],
					},
				},
			},
		]
		c.copyPath = false
		c.world.context.sourceSecretId = 's1'
		return c
	},
	'partner list in a non-incomplete error': () => {
		const c = base()
		c.world.rounds = [
			{ status: 409, body: { error: 'other', federated: { required: [] } } },
		]
		return c
	},
	'copies without a required list': () => {
		const c = base()
		c.copyPath = false
		c.world.context.sourceSecretId = 's1'
		c.world.rounds = [
			{ body: { error: 'incomplete', copies: { skipRefused: ['c-u1'] } } },
		]
		return c
	},
	'skip refused for the owner twice': () => {
		const c = base()
		c.copyPath = false
		c.world.context.sourceSecretId = 's1'
		const owner = { secretId: 'c-own', holder: 'owner' }
		c.world.rounds = [
			{ body: { error: 'incomplete', copies: { required: [owner] } } },
			{
				body: {
					error: 'incomplete',
					copies: { required: [owner], skipRefused: [owner] },
				},
			},
		]
		return c
	},
	'one partner of each kind, all agreed': () => {
		const c = base()
		c.copyPath = false
		c.world.context.sourceSecretId = 's1'
		c.withoutUnreachable = ['f0', 'f1', 'f2', 'f3']
		c.world.partners = {
			'a@p1': { fail: 'unreachable' },
			'b@p2': { fail: 'gone' },
			'c@p3': { valueFields: ['totp', 'passkeys'] },
			'd@p4': { fail: 'mismatch' },
		}
		c.world.rounds = [
			{
				body: {
					error: 'incomplete',
					federated: {
						required: CLOUDS.map((cloud, i) => ({
							shareId: `f${i}`,
							recipientCloudId: cloud,
						})),
					},
				},
			},
		]
		return c
	},
})

const GOLDEN = path.join(__dirname, 'golden', 'share-save-fill.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * The value or error of one save.
 *
 * @param {Promise} call The pending save.
 * @return {Promise<object>}
 */
async function outcome(call) {
	try {
		return { returned: plain(await call) }
	} catch (error) {
		return {
			threw: error.message,
			name: error.name,
			code: error.code ?? '(none)',
			partners: plain(error.partners ?? '(none)'),
			fromShareSave: error.fromShareSave ?? false,
			status: error.response?.status ?? '(none)',
		}
	}
}

describe('saveShared fill (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		forgetVerifiedPartners()
		trace.length = 0
		useSessionStore().cryptoKey = 'VAULT'
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		const c = CASES[name]()
		world = { ...c.world, failures: FAILURES }
		const result = await outcome(
			saveShared({
				id: 's1',
				plainDiff: c.plainDiff,
				patch: { key: 'own-ct' },
				baseUpdatedAt: '2026-10-01T10:00:00Z',
				withoutUnreachable: c.withoutUnreachable,
			}),
		)
		const actual = { result, trace: plain(trace) }
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(
				GOLDEN,
				'{\n'
					+ Object.entries(recorded)
						.map(
							([k, v]) => `${JSON.stringify(k)}: ${JSON.stringify(v)}`,
						)
						.join(',\n')
					+ '\n}\n',
			)
		}
		expect(actual).toEqual(recorded[name])
	})
})
