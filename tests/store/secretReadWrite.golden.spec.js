/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the secret store's read and write steps: decryptSecret
 * (locked vault, ended access, every value field set, empty or failing,
 * pending extra-field blobs), fetchSecret (online, from the snapshot, the
 * network fallback, the merge write-back it starts), persistMergedPending
 * (a write-back running, read on its base or not, saving or failing),
 * contributeSecret and createSecret (online and into the offline queue,
 * passkey url mirroring). Crypto is replaced by readable stand-ins; each
 * case records every request, encryption, decryption, queue and save call
 * in order with what the action returns or throws and the store state,
 * compared with golden/secret-read-write.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
let world = {}

/**
 * A JSON-safe copy.
 *
 * @param {*} value Any value.
 * @return {*}
 */
function plain(value) {
	return JSON.parse(
		JSON.stringify(value ?? '(nullish)', (key, v) =>
			v === undefined ? '(undefined)' : v,
		),
	)
}

/**
 * Answer a scripted request: its failure, its value, or a default.
 *
 * @param {string} key The script key.
 * @param {*} fallback The default answer.
 * @return {Promise<object>}
 */
async function answer(key, fallback) {
	const entry = world[key]
	if (entry?.fail) {
		throw entry.fail
	}
	return { data: entry && 'data' in entry ? entry.data : fallback }
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			trace.push(['get', url])
			if (url.includes('contribution-context')) {
				return answer('context', {
					ownerCertificate: 'OWNER',
					recipients: [{ userId: 'u1', certificate: 'C1' }],
				})
			}
			await world.beforeAnswer?.()
			return answer('secret', { id: 's1', key: 'ck', updatedAt: 'v1' })
		}),
		put: vi.fn(async (url, body) => {
			trace.push(['put', url, plain(body)])
			return answer('put', { id: 's1', updatedAt: 'v8' })
		}),
		post: vi.fn(async (url, body) => {
			trace.push(['post', url, plain(body)])
			return answer('post', { id: 'new', created: true })
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/x${url}` }))
vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async (pem) => {
		trace.push(['importKey', pem])
		return `pk(${pem})`
	}),
	rsaEncrypt: vi.fn(async (text, key) => `E[${key}]${text}`),
	rsaDecrypt: vi.fn(async (text, key) => {
		trace.push(['decrypt', text, key])
		if (String(text).startsWith('bad')) {
			throw new Error(`cannot decrypt ${text}`)
		}
		return String(text).startsWith('J:') ? text.slice(2) : `D(${text})`
	}),
}))
vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => world.offline,
}))
vi.mock('../../src/services/shareSave.js', async (importOriginal) => ({
	...(await importOriginal()),
	encryptSparse: vi.fn(async (diff, certificate) => {
		trace.push(['encryptSparse', plain(diff), certificate])
		return Object.fromEntries(
			Object.entries(diff).map(([k, v]) => [
				k,
				v === null ? null : `S[${certificate}]${v}`,
			]),
		)
	}),
	saveShared: vi.fn(async (options) => {
		trace.push(['saveShared', plain(options)])
		if (world.shared?.fail) {
			throw world.shared.fail
		}
		return (
			world.shared?.value ?? {
				secret: { id: 's1', updatedAt: 'v9' },
				sourceUpdatedAt: 'sv1',
			}
		)
	}),
	notifyOwnerOnly: vi.fn(async (result) => {
		trace.push(['notify', plain(result)])
	}),
}))
vi.mock('../../src/store/modules/share.js', () => ({
	useShareStore: () => ({
		encryptForRecipient: async (fields, certificate) => {
			trace.push(['encryptFor', plain(fields), certificate])
			if (world.encryptFails === certificate) {
				throw new Error(`no key for ${certificate}`)
			}
			return (
				world.blob?.(certificate) ?? {
					key: `k@${certificate}`,
					login: `l@${certificate}`,
				}
			)
		},
	}),
}))

const { useSecretStore } = await import('../../src/store/modules/secret.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')
const { useSecretTypeStore } = await import('../../src/store/modules/secretType.js')

const GOLDEN = path.join(__dirname, 'golden', 'secret-read-write.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

const PASSKEY = JSON.stringify({
	credentialId: 'Y3JlZA',
	rpId: 'login.example.org',
	userHandle: 'dQ',
	userName: 'ann',
	privateKey: 'cHJpdg',
	signCount: 0,
	createdAt: '2026-01-01T00:00:00Z',
})

/**
 * @return {Error} A browser network failure.
 */
function networkError() {
	return Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' })
}

const ROWS = {
	'only a key': { id: 's1', key: 'ck', updatedAt: 'v1' },
	'every field': {
		id: 's1',
		key: 'ck',
		login: 'cl',
		additionalFields: 'J:{"pin":"1"}',
		totp: 'ct',
		passkeys: 'cp',
		updatedAt: 'v1',
	},
	'extra fields not json': { id: 's1', key: 'ck', additionalFields: 'raw' },
	'extra fields a list': { id: 's1', additionalFields: 'J:[1,2]' },
	'empty strings': {
		id: 's1',
		key: '',
		login: '',
		additionalFields: '',
		totp: '',
	},
	'key fails to decrypt': { id: 's1', key: 'bad-key' },
	'pending blobs': {
		id: 's1',
		key: 'ck',
		additionalFields: 'J:{"pin":"1","a":"x"}',
		pendingAdditionalFields: ['J:{"a":"y"}', 'J:{"b":"z"}'],
		updatedAt: 'v1',
	},
	'pending with a bad blob': {
		id: 's1',
		additionalFields: 'J:{"pin":"1"}',
		pendingAdditionalFields: ['J:{"a":"y"}', 'bad-blob', 'J:[1]'],
		updatedAt: 'v1',
	},
	'pending without own fields': {
		id: 's1',
		pendingAdditionalFields: ['J:{"a":"y"}'],
		updatedAt: 'v1',
	},
	'pending onto a list': {
		id: 's1',
		additionalFields: 'J:[1]',
		pendingAdditionalFields: ['J:{"a":"y"}'],
		updatedAt: 'v1',
	},
	'pending not a list': {
		id: 's1',
		additionalFields: 'J:{}',
		pendingAdditionalFields: 'J:{"a":"y"}',
	},
	'pending empty': { id: 's1', key: 'ck', pendingAdditionalFields: [] },
	'access ended': { id: 's1', key: 'ck', accessExpiresAt: '2020-01-01T00:00:00Z' },
	'access ends later': {
		id: 's1',
		key: 'ck',
		accessExpiresAt: '2999-01-01T00:00:00Z',
	},
	'access end not a date': { id: 's1', key: 'ck', accessExpiresAt: 'soon' },
}

const OFFLINE_VAULT = {
	secrets: [
		{ id: 's1', key: 'cached-key', updatedAt: 'v0' },
		{ id: 's2', key: 'other' },
	],
}

/**
 * An offline store double.
 *
 * @param {object} over Fields to set.
 * @return {object}
 */
function offlineStore(over = {}) {
	return {
		servedFromCache: false,
		vault: null,
		enqueue: async (entry) => {
			trace.push(['enqueue', plain(entry)])
		},
		...over,
	}
}

const CASES = {}

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
		let x = a
		x = Math.imul(x ^ (x >>> 15), x | 1)
		x ^= x + Math.imul(x ^ (x >>> 7), x | 61)
		return ((x ^ (x >>> 14)) >>> 0) / 4294967296
	}
}

const ABSENT = Symbol('absent')
const VALUE_FIELD_NAMES = ['key', 'login', 'additionalFields', 'totp', 'passkeys']
const UPDATE_FIELDS = {
	name: [ABSENT, 'New name', ''],
	url: [ABSENT, 'https://new', null, ''],
	typeId: [ABSENT, 'tp', 't1', null],
	folderId: [ABSENT, 'f2', null],
	key: [ABSENT, ABSENT, 'k2', '', 0, 'PASSKEY'],
	login: [ABSENT, ABSENT, 'l2', '', null, 0],
	additionalFields: [ABSENT, ABSENT, null, '{"a":1}', { b: 2 }, ''],
	totp: [ABSENT, ABSENT, 'seed', '', null],
	passkeys: [ABSENT, ABSENT, '[]', ''],
	mergedPending: [ABSENT, 0, 2],
}

/**
 * A generated updateSecret case.
 *
 * @param {number} seed The seed.
 * @return {object}
 */
function updateCase(seed) {
	const r = random(seed)
	const pick = (list) => list[Math.floor(r() * list.length)]
	const data = {}
	const valueEdit = r() < 0.55
	for (const [field, values] of Object.entries(UPDATE_FIELDS)) {
		const value =
			valueEdit || !VALUE_FIELD_NAMES.includes(field) ? pick(values) : ABSENT
		if (value !== ABSENT) {
			data[field] = value === 'PASSKEY' ? PASSKEY : value
		}
	}
	const options = {}
	if (r() < 0.2) {
		options.writeBack = true
	}
	if (r() < 0.5) {
		options.baseUpdatedAt = pick(['b1', ''])
	}
	if (r() < 0.4) {
		options.sourceBaseUpdatedAt = pick(['sb', null])
	}
	if (r() < 0.3) {
		options.withoutUnreachable = ['f1']
	}
	return {
		run: 'update',
		data,
		options,
		hooks: { onWait: r() < 0.5, onSourceSaved: r() < 0.6 },
		locked: r() < 0.1,
		offline: pick([
			{},
			{},
			{},
			{ servedFromCache: true, vault: OFFLINE_VAULT },
			{ servedFromCache: true, vault: { secrets: [] } },
			{ servedFromCache: true, vault: null },
		]),
		current: pick([
			null,
			{ id: 's1', updatedAt: 'v1', typeId: 'tp' },
			{ id: 's1', updatedAt: 'v1', typeId: 't1' },
			{ id: 's2', updatedAt: 'v2' },
		]),
		listed: pick([[], [{ id: 's1', updatedAt: 'l1' }]]),
		world: {
			shared: pick([
				undefined,
				{ value: { secret: { id: 's1' } } },
				{ value: { secret: null, sourceUpdatedAt: null } },
				{ fail: new Error('share save refused') },
			]),
			put: pick([
				undefined,
				{ data: {} },
				{ data: null },
				{ fail: new Error('put refused') },
			]),
		},
	}
}
for (let seed = 1; seed <= 400; seed++) {
	CASES[`update ${seed}`] = updateCase(seed)
}

for (const [label, row] of Object.entries(ROWS)) {
	CASES[`decrypt: ${label}`] = { run: 'decrypt', row }
	CASES[`fetch: ${label}`] = { run: 'fetch', world: { secret: { data: row } } }
}
Object.assign(CASES, {
	'decrypt: locked': { run: 'decrypt', row: ROWS['every field'], locked: true },
	'decrypt: locked and ended': {
		run: 'decrypt',
		row: ROWS['access ended'],
		locked: true,
	},
	'fetch: from the snapshot': {
		run: 'fetch',
		offline: { servedFromCache: true, vault: OFFLINE_VAULT },
	},
	'fetch: snapshot without the secret': {
		run: 'fetch',
		id: 's9',
		offline: { servedFromCache: true, vault: OFFLINE_VAULT },
		world: { secret: { data: { id: 's9', key: 'k9' } } },
	},
	'fetch: served from cache without a vault': {
		run: 'fetch',
		offline: { servedFromCache: true, vault: null },
	},
	'fetch: network error, snapshot has it': {
		run: 'fetch',
		offline: { vault: OFFLINE_VAULT },
		world: { secret: { fail: networkError() } },
	},
	'fetch: network error, snapshot lacks it': {
		run: 'fetch',
		id: 's9',
		offline: { vault: OFFLINE_VAULT },
		world: { secret: { fail: networkError() } },
	},
	'fetch: network error, no snapshot': {
		run: 'fetch',
		world: { secret: { fail: networkError() } },
	},
	'fetch: request without a response': {
		run: 'fetch',
		offline: { vault: OFFLINE_VAULT },
		world: { secret: { fail: Object.assign(new Error('x'), { request: {} }) } },
	},
	'fetch: server error': {
		run: 'fetch',
		offline: { vault: OFFLINE_VAULT },
		world: {
			secret: {
				fail: Object.assign(new Error('HTTP 500'), {
					request: {},
					response: { status: 500 },
				}),
			},
		},
	},
	'fetch: cached secret fails to decrypt': {
		run: 'fetch',
		offline: {
			servedFromCache: true,
			vault: { secrets: [{ id: 's1', key: 'bad-cached' }] },
		},
	},
	'fetch: pending merged, write-back saves': {
		run: 'fetch',
		world: { secret: { data: ROWS['pending blobs'] } },
		update: { value: { updatedAt: 'v2' } },
	},
	'fetch: pending merged, write-back saves without a version': {
		run: 'fetch',
		world: { secret: { data: ROWS['pending blobs'] } },
		update: { value: null },
	},
	'fetch: pending merged, write-back fails': {
		run: 'fetch',
		world: { secret: { data: ROWS['pending blobs'] } },
		update: { fail: new Error('refused') },
	},
	'fetch: pending incomplete, no write-back': {
		run: 'fetch',
		world: { secret: { data: ROWS['pending with a bad blob'] } },
	},
})

for (const [label, row] of [
	['no merge', { id: 's1', mergedPending: 0, updatedAt: 'v1' }],
	['another secret', { id: 's2', mergedPending: 2, updatedAt: 'v1' }],
	['merged count not a number', { id: 's1', mergedPending: 'x', updatedAt: 'v1' }],
	[
		'merged two',
		{ id: 's1', mergedPending: 2, updatedAt: 'v1', additionalFields: { a: 1 } },
	],
]) {
	for (const [runLabel, running] of [
		['nothing running', null],
		[
			'running on its base, saves',
			{ base: 'v1', count: 2, updatedAt: 'v5', saves: true },
		],
		[
			'running on its base, fails',
			{ base: 'v1', count: 2, updatedAt: 'v5', saves: false },
		],
		[
			'running on another base',
			{ base: 'v0', count: 1, updatedAt: 'v5', saves: true },
		],
		[
			'running, saved without a version',
			{ base: 'v1', count: 1, updatedAt: null, saves: true },
		],
	]) {
		for (const where of running ? ['map', 'sent'] : ['none']) {
			CASES[`persist: ${label}, ${runLabel}, ${where}`] = {
				run: 'persist',
				current: row,
				running,
				where,
				update: { value: { updatedAt: 'v3' } },
			}
		}
	}
}
Object.assign(CASES, {
	'persist: no open secret': { run: 'persist', current: null },
	'persist: save reports waits and a source version': {
		run: 'persist',
		current: {
			id: 's1',
			mergedPending: 1,
			updatedAt: 'v1',
			additionalFields: {},
		},
		update: {
			value: { updatedAt: 'v3' },
			waits: [1000, 2000],
			source: 'src-v7',
		},
	},
	'persist: save fails after a wait': {
		run: 'persist',
		current: { id: 's1', mergedPending: 1, updatedAt: 'v1' },
		update: { fail: new Error('limit'), waits: [500] },
	},
})

for (const [label, update] of [
	['saves with a source version', { value: { updatedAt: 'v3' }, source: 'src-1' }],
	['saves with waits', { value: { updatedAt: 'v3' }, waits: [700, 900] }],
	['saves without a version', { value: null, waits: [10], source: 'src-2' }],
	['fails after waits', { fail: new Error('limit'), waits: [300] }],
]) {
	CASES[`persist watched: ${label}`] = {
		run: 'persist',
		where: 'watched',
		current: {
			id: 's1',
			mergedPending: 1,
			updatedAt: 'v1',
			additionalFields: {},
		},
		update,
	}
}
Object.assign(CASES, {
	'fetch: write-back ends while the read is out': {
		run: 'fetch',
		raceWriteBack: true,
		world: { secret: { data: ROWS['pending blobs'] } },
		update: { value: { updatedAt: 'v2' } },
	},
	'fetch: write-back still running when it opens': {
		run: 'fetch',
		holdWriteBack: true,
		world: { secret: { data: ROWS['pending blobs'] } },
		update: { value: { updatedAt: 'v2' } },
	},
	'update: passkey key, open type tp, data type ABSENT': {
		run: 'update',
		data: { key: PASSKEY },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 'tp' },
		listed: [],
		world: {},
	},
	'update: passkey key, open type tp, data type t1': {
		run: 'update',
		data: { key: PASSKEY, typeId: 't1' },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 'tp' },
		listed: [],
		world: {},
	},
	'update: passkey key, open type tp, data type tp': {
		run: 'update',
		data: { key: PASSKEY, typeId: 'tp' },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 'tp' },
		listed: [],
		world: {},
	},
	'update: passkey key, open type t1, data type ABSENT': {
		run: 'update',
		data: { key: PASSKEY },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 't1' },
		listed: [],
		world: {},
	},
	'update: passkey key, open type t1, data type t1': {
		run: 'update',
		data: { key: PASSKEY, typeId: 't1' },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 't1' },
		listed: [],
		world: {},
	},
	'update: passkey key, open type t1, data type tp': {
		run: 'update',
		data: { key: PASSKEY, typeId: 'tp' },
		options: { baseUpdatedAt: 'b1' },
		hooks: {},
		offline: {},
		current: { id: 's1', updatedAt: 'v1', typeId: 't1' },
		listed: [],
		world: {},
	},
	'update: a write-back save does not wait for another': {
		run: 'updateDuringWriteBack',
		options: { writeBack: true },
	},
	'update: an edit waits for the write-back': {
		run: 'updateDuringWriteBack',
		options: {},
	},
	'list: server error with a snapshot': {
		run: 'list',
		offline: { vault: OFFLINE_VAULT },
		world: {
			secret: {
				fail: Object.assign(new Error('HTTP 500'), {
					request: {},
					response: { status: 500 },
				}),
			},
		},
	},
	'list: network error with a snapshot': {
		run: 'list',
		offline: { vault: OFFLINE_VAULT },
		world: { secret: { fail: networkError() } },
	},
})

const CONTRIBUTE_DATA = {
	full: {
		name: 'N',
		url: 'https://u',
		typeId: 't1',
		folderId: 'f1',
		key: 'k',
		login: 'l',
		additionalFields: { a: 1 },
		totp: 'seed',
		passkeys: '[]',
	},
	bare: { name: 'N' },
}
for (const [label, data] of Object.entries(CONTRIBUTE_DATA)) {
	CASES[`contribute: ${label}`] = { run: 'contribute', data }
	CASES[`contribute: ${label}, no recipients`] = {
		run: 'contribute',
		data,
		world: { context: { data: { ownerCertificate: 'OWNER' } } },
	}
	CASES[`contribute: ${label}, three recipients, full blobs`] = {
		run: 'contribute',
		data,
		world: {
			context: {
				data: {
					ownerCertificate: 'OWNER',
					recipients: [
						{ userId: 'u1', certificate: 'C1' },
						{ userId: 'u2', certificate: 'C2' },
						{ userId: 'u3', certificate: 'C3' },
					],
				},
			},
		},
		blob: 'full',
	}
	CASES[`contribute: ${label}, owner blob empty`] = {
		run: 'contribute',
		data,
		blob: 'empty',
	}
}
Object.assign(CASES, {
	'contribute: context fails': {
		run: 'contribute',
		data: CONTRIBUTE_DATA.full,
		world: { context: { fail: new Error('forbidden') } },
	},
	'contribute: a recipient cannot be encrypted for': {
		run: 'contribute',
		data: CONTRIBUTE_DATA.full,
		encryptFails: 'C1',
	},
	'contribute: post fails': {
		run: 'contribute',
		data: CONTRIBUTE_DATA.full,
		world: { post: { fail: new Error('denied') } },
	},
})

const CREATE_DATA = {
	full: CONTRIBUTE_DATA.full,
	bare: { name: 'N' },
	'login empty': { name: 'N', key: 'k', login: '' },
	'login null': { name: 'N', key: 0, login: null },
	'login zero': { name: 'N', key: 'k', login: 0 },
	'extra fields as text': { name: 'N', additionalFields: '{"a":1}' },
	'extra fields empty': { name: 'N', additionalFields: '' },
	'blob fields empty': { name: 'N', totp: '', passkeys: '' },
	'passkey without url': { name: 'P', typeId: 'tp', key: PASSKEY },
	'passkey with url': {
		name: 'P',
		typeId: 'tp',
		key: PASSKEY,
		url: 'https://kept',
	},
	'passkey with a bad key': { name: 'P', typeId: 'tp', key: 'not json' },
	'passkey without key': { name: 'P', typeId: 'tp' },
	'login type without url': { name: 'L', typeId: 't1', key: PASSKEY },
	'unknown type': { name: 'L', typeId: 'missing', key: PASSKEY },
}
for (const [label, data] of Object.entries(CREATE_DATA)) {
	CASES[`create: ${label}`] = { run: 'create', data }
	CASES[`create offline: ${label}`] = {
		run: 'create',
		data,
		offline: { servedFromCache: true },
	}
}
Object.assign(CASES, {
	'create: locked': { run: 'create', data: CREATE_DATA.full, locked: true },
	'create: post fails': {
		run: 'create',
		data: CREATE_DATA.full,
		world: { post: { fail: new Error('denied') } },
	},
	'create offline: queue fails': {
		run: 'create',
		data: CREATE_DATA.full,
		offline: {
			servedFromCache: true,
			enqueue: async () => {
				throw new Error('queue sealed')
			},
		},
	},
})

/**
 * The value or error of one call.
 *
 * @param {Promise} call The pending call.
 * @return {Promise<object>}
 */
async function outcome(call) {
	try {
		return { returned: plain(await call) }
	} catch (error) {
		return { threw: error.message, name: error.name }
	}
}

/**
 * A scripted updateSecret: reports its waits and source version, then saves
 * or fails.
 *
 * @param {object} update The script.
 * @return {Function}
 */
function updateSecretDouble(update) {
	return async (id, data, options) => {
		trace.push(['update', id, plain(data), plain(options)])
		for (const ms of update?.waits ?? []) {
			options.onWait(ms)
		}
		if (update?.source) {
			options.onSourceSaved(update.source)
		}
		await Promise.resolve()
		if (update?.fail) {
			throw update.fail
		}
		return update?.value
	}
}

/**
 * Open a secret while a merge write-back of it runs: one that ends while the
 * read is out (held until the read is sent), or one held until the open
 * has returned.
 *
 * @param {object} store The secret store.
 * @param {object} c The case.
 * @return {Promise<object>}
 */
async function runFetchWithWriteBack(store, c) {
	let release
	const gate = new Promise((resolve) => {
		release = resolve
	})
	const double = updateSecretDouble(c.update)
	store.updateSecret = async (...args) => {
		await gate
		return double(...args)
	}
	store.currentSecret = {
		id: 's1',
		mergedPending: 1,
		updatedAt: 'v1',
		additionalFields: {},
	}
	const running = store.persistMergedPending('s1')
	trace.push(['write-back started'])
	if (c.raceWriteBack) {
		world.beforeAnswer = async () => {
			release()
			await running
		}
	}
	const opened = await outcome(store.fetchSecret('s1'))
	opened.loadingAtReturn = store.loading
	release()
	await running
	world.beforeAnswer = null
	return opened
}

/**
 * Run a write-back while a caller waits for it with writeBackSettled: the
 * save is held until the caller is registered, then reports its waits and
 * source version and saves or fails.
 *
 * @param {object} store The secret store.
 * @param {object} c The case.
 * @return {Promise<object>}
 */
async function runWatched(store, c) {
	let release
	const gate = new Promise((resolve) => {
		release = resolve
	})
	store.updateSecret = async (...args) => {
		await gate
		return updateSecretDouble(c.update)(...args)
	}
	store.currentSecret = { ...c.current }
	const first = outcome(store.persistMergedPending('s1'))
	const settled = outcome(
		store.writeBackSettled('s1', (ms) => trace.push(['waiter', ms])),
	)
	release()
	return {
		first: await first,
		settled: await settled,
		current: plain(store.currentSecret),
	}
}

/**
 * Run one persistMergedPending case, with a write-back already running when
 * the case says so: started through the store (in its write-back map), or
 * only handed in as the one running when the read went out.
 *
 * @param {object} store The secret store.
 * @param {object} c The case.
 * @return {Promise<object>}
 */
async function runPersist(store, c) {
	if (c.where === 'watched') {
		return runWatched(store, c)
	}
	if (c.where === 'map') {
		let release
		const gate = new Promise((resolve) => {
			release = resolve
		})
		store.updateSecret = async (...args) => {
			await gate
			return updateSecretDouble(
				c.running.saves
					? { value: { updatedAt: c.running.updatedAt } }
					: { fail: new Error('first refused') },
			)(...args)
		}
		store.currentSecret = {
			...c.current,
			updatedAt: c.running.base,
			mergedPending: c.running.count,
		}
		const first = outcome(store.persistMergedPending('s1'))
		store.currentSecret = { ...c.current }
		const second = outcome(store.persistMergedPending('s1'))
		release()
		return {
			first: await first,
			second: await second,
			current: plain(store.currentSecret),
			settled: plain(await store.writeBackSettled('s1')),
		}
	}
	store.currentSecret = c.current ? { ...c.current } : null
	const runningAtSend = c.running
		? {
				...c.running,
				done: Promise.resolve(
					c.running.saves ? { sourceUpdatedAt: null } : null,
				),
				waiting: null,
				waiters: new Set(),
			}
		: null
	const result = await outcome(store.persistMergedPending('s1', runningAtSend))
	return {
		result,
		current: plain(store.currentSecret),
		settled: plain(await store.writeBackSettled('s1')),
	}
}

describe('secret read and write (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		trace.length = 0
		vi.stubGlobal('crypto', {
			...globalThis.crypto,
			randomUUID: () => 'uuid-1',
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		const c = CASES[name]
		world = {
			...(c.world ?? {}),
			offline: offlineStore(c.offline),
			encryptFails: c.encryptFails,
			blob: {
				full: (cert) => ({
					key: `k@${cert}`,
					login: `l@${cert}`,
					additionalFields: `a@${cert}`,
					totp: `t@${cert}`,
					passkeys: `p@${cert}`,
				}),
				empty: () => ({}),
			}[c.blob],
		}
		const session = useSessionStore()
		session.cryptoKey = c.locked ? null : 'VAULT'
		session.certificate = c.locked ? null : 'MINE'
		useSecretTypeStore().types = [
			{ id: 'tp', name: 'passkey' },
			{ id: 't1', name: 'login' },
		]
		const store = useSecretStore()
		if (!c.run.startsWith('update')) {
			store.updateSecret = updateSecretDouble(c.update)
		}
		let result
		if (c.run === 'decrypt') {
			result = await outcome(store.decryptSecret(c.row))
		} else if (c.run === 'updateDuringWriteBack') {
			let release
			const gate = new Promise((resolve) => {
				release = resolve
			})
			const real = store.updateSecret
			store.updateSecret = async () => {
				await gate
				trace.push(['write-back save'])
				return { updatedAt: 'v4' }
			}
			store.currentSecret = {
				id: 's1',
				mergedPending: 1,
				updatedAt: 'v1',
				additionalFields: {},
			}
			const running = store.persistMergedPending('s1')
			store.updateSecret = real
			const edit = outcome(store.updateSecret('s1', { name: 'x' }, c.options))
			await new Promise((resolve) => setTimeout(resolve, 0))
			trace.push(['released'])
			release()
			await running
			result = await edit
		} else if (c.run === 'list') {
			result = await outcome(store.fetchSecrets({}))
			result.list = plain({
				secrets: store.secrets,
				total: store.totalCount,
				page: store.page,
				servedFromCache: world.offline.servedFromCache,
			})
		} else if (c.run === 'fetch') {
			if (c.raceWriteBack || c.holdWriteBack) {
				result = await runFetchWithWriteBack(store, c)
			} else {
				result = await outcome(store.fetchSecret(c.id ?? 's1'))
			}
			await new Promise((resolve) => setTimeout(resolve, 0))
			await new Promise((resolve) => setTimeout(resolve, 0))
			result.current = plain(store.currentSecret)
			result.loading = store.loading
			result.servedFromCache = world.offline.servedFromCache
		} else if (c.run === 'persist') {
			result = await runPersist(store, c)
		} else if (c.run === 'update') {
			store.currentSecret = c.current ? { ...c.current } : null
			store.secrets = c.listed
			const options = { ...c.options }
			if (c.hooks.onWait) {
				options.onWait = (ms) => trace.push(['onWait', ms])
			}
			if (c.hooks.onSourceSaved) {
				options.onSourceSaved = (version) =>
					trace.push(['onSourceSaved', version])
			}
			result = await outcome(store.updateSecret('s1', c.data, options))
			result.current = plain(store.currentSecret)
		} else if (c.run === 'contribute') {
			result = await outcome(store.contributeSecret('tf1', c.data))
		} else {
			result = await outcome(store.createSecret(c.data))
		}
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
