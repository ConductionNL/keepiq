/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for fetchSecrets in the secret store: the request it sends
 * online for every mix of passed options and stored filters, the list it
 * serves offline from the snapshot, the offline trash and archive views, and
 * the fallback to the snapshot on a network error. Each case records the
 * request, the store's list, total and page, and any error, compared with
 * golden/fetch-secrets.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const requests = []
const answer = { value: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url, config) => {
			requests.push({ url, params: config?.params })
			if (answer.value instanceof Error) {
				throw answer.value
			}
			return { data: answer.value }
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

const { useSecretStore } = await import('../../src/store/modules/secret.js')
const { useOfflineStore } = await import('../../src/store/modules/offline.js')

const GOLDEN = path.join(__dirname, 'golden', 'fetch-secrets.json')

const VAULT = {
	secrets: [
		{
			id: 'a',
			name: 'Alpha Mail',
			url: 'https://mail.test',
			folderId: 'f1',
			typeId: 't1',
			favourite: true,
			tags: ['work'],
		},
		{
			id: 'b',
			name: 'beta',
			url: 'https://BANK.test',
			folderId: 'f2',
			typeId: 't2',
			favourite: false,
			tags: [],
		},
		{ id: 'c', name: null, url: null, folderId: null, typeId: 't1', tags: null },
		{
			id: 'd',
			name: 'Delta mail',
			folderId: 'f1',
			typeId: 't2',
			favourite: true,
		},
	],
	folders: [],
}
const PAGE = { items: [{ id: 'x' }], total: 41, page: 3 }

/**
 * @return {Error} A browser network failure.
 */
function networkError() {
	return Object.assign(new Error('Network Error'), { code: 'ERR_NETWORK' })
}

const ONLINE = {
	defaults: [{}],
	'every option': [
		{
			page: 2,
			limit: 10,
			sort: 'updatedAt',
			direction: 'desc',
			folderId: 'f1',
			search: 'mail',
			typeId: 't1',
			state: 'archived',
			favourite: 1,
			tag: 'work',
		},
	],
	'stored filters': [
		{},
		{
			filters: {
				folderId: 'f9',
				search: 'q',
				typeId: 't9',
				state: 'trashed',
				favourite: true,
				tag: 'home',
			},
			sort: { field: 'last_used_at', direction: 'desc' },
			page: 4,
			limit: 25,
		},
	],
	'options win over stored filters': [
		{
			folderId: null,
			search: '',
			typeId: null,
			state: 'live',
			favourite: false,
			tag: null,
		},
		{
			filters: {
				folderId: 'f9',
				search: 'q',
				typeId: 't9',
				state: 'trashed',
				favourite: true,
				tag: 'home',
			},
		},
	],
	'state kept': [{ state: 'kept' }],
	'empty state falls back to live': [{ state: '' }],
	'answer without fields': [{}, {}, {}],
	'server error': [
		{},
		{},
		Object.assign(new Error('boom'), { response: { status: 500 } }),
	],
	'network error with a snapshot': [{}, {}, networkError(), true],
	'network error without a snapshot': [{}, {}, networkError(), false],
	'request without a response': [
		{},
		{},
		Object.assign(new Error('x'), { request: {} }),
		true,
	],
}

const OFFLINE = {
	'whole vault': [{}],
	folder: [{ folderId: 'f1' }],
	'explicit null folder over stored one': [{ folderId: null }, { folderId: 'f2' }],
	'stored folder': [{}, { folderId: 'f2' }],
	'search, any case, name or url': [{ search: 'MAIL' }],
	'search on the url': [{ search: 'bank' }],
	'stored search': [{}, { search: 'Delta' }],
	'null search': [{ search: null }],
	type: [{ typeId: 't2' }],
	favourites: [{ favourite: true }],
	tag: [{ tag: 'work' }],
	'all filters': [
		{
			folderId: 'f1',
			typeId: 't1',
			favourite: true,
			tag: 'work',
			search: 'alpha',
		},
	],
	'trash view': [{ state: 'trashed' }],
	'archive from stored state': [{}, { state: 'archived' }],
	'kept view': [{ state: 'kept' }],
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * Run fetchSecrets for one case and record what it did.
 *
 * @param {object} options The options passed.
 * @param {object} setup Stored store state, the answer and the snapshot.
 * @return {Promise<object>}
 */
async function run(options, setup) {
	requests.length = 0
	const store = useSecretStore()
	const offline = useOfflineStore()
	Object.assign(store.filters, setup.filters ?? {})
	Object.assign(store.sort, setup.sort ?? {})
	if (setup.page) store.page = setup.page
	if (setup.limit) store.limit = setup.limit
	offline.vault = setup.vault ? JSON.parse(JSON.stringify(VAULT)) : null
	offline.servedFromCache = setup.offline === true
	answer.value = setup.answer ?? PAGE
	let error = null
	try {
		await store.fetchSecrets(options)
	} catch (e) {
		error = e.message
	}
	return JSON.parse(
		JSON.stringify({
			requests,
			ids: store.secrets.map((s) => s.id),
			total: store.totalCount,
			page: store.page,
			loading: store.loading,
			servedFromCache: offline.servedFromCache,
			error,
		}),
	)
}

/**
 * Record or compare one case.
 *
 * @param {string} name The case name.
 * @param {object} actual The outcome.
 * @return {void}
 */
function check(name, actual) {
	if (RECORD) {
		recorded[name] = actual
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	expect(actual).toEqual(recorded[name])
}

describe('fetchSecrets (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it.each(Object.keys(ONLINE))('online: %s', async (name) => {
		const [options, stored = {}, answerValue, vault] = ONLINE[name]
		const answerFor = name === 'answer without fields' ? {} : answerValue
		check(
			`online: ${name}`,
			await run(options, { ...stored, answer: answerFor, vault }),
		)
	})

	it.each(Object.keys(OFFLINE))('offline: %s', async (name) => {
		const [options, filters = {}] = OFFLINE[name]
		check(
			`offline: ${name}`,
			await run(options, { filters, vault: true, offline: true }),
		)
	})
})
