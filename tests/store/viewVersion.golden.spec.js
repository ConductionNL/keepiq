/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for viewVersion in the secretVersion store: version answers
 * with every field present, absent, empty or undecryptable, each recorded as
 * the version it returns or the error it throws, compared with
 * golden/view-version.json. rsaDecrypt is replaced by a marker, so the
 * record shows which fields were decrypted and in what order.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const answer = { data: null }

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			calls.push(`GET ${url}`)
			return { data: answer.data }
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('../../src/crypto/index.js', async (importOriginal) => ({
	...(await importOriginal()),
	rsaDecrypt: vi.fn(async (cipher, key) => {
		calls.push(`decrypt ${cipher}`)
		if (String(cipher).startsWith('bad')) {
			throw new Error(`cannot decrypt ${cipher}`)
		}
		return `plain(${cipher}|${key})`
	}),
}))

const { useSecretVersionStore } =
	await import('../../src/store/modules/secretVersion.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')

const GOLDEN = path.join(__dirname, 'golden', 'view-version.json')

const FULL = {
	id: 'v1',
	name: 'GitHub',
	url: 'https://github.com',
	key: 'k1',
	login: 'l1',
	additionalFields: 'a1',
	totp: 't1',
	passkeys: 'p1',
	createdAt: '2026-10-01T10:00:00Z',
}

const CASES = {
	'every field': { data: FULL },
	'no value fields': { data: { id: 'v2', name: 'Bare' } },
	'empty strings': {
		data: {
			...FULL,
			key: '',
			login: '',
			additionalFields: '',
			totp: '',
			passkeys: '',
		},
	},
	'null fields': {
		data: {
			...FULL,
			key: null,
			login: null,
			additionalFields: null,
			totp: null,
			passkeys: null,
		},
	},
	'additional fields are JSON': { data: { ...FULL, additionalFields: 'json' } },
	'additional fields do not decrypt': {
		data: { ...FULL, additionalFields: 'bad-a' },
	},
	'key does not decrypt': { data: { ...FULL, key: 'bad-k' } },
	'login does not decrypt': { data: { ...FULL, login: 'bad-l' } },
	'totp does not decrypt': { data: { ...FULL, totp: 'bad-t' } },
	'passkeys only': { data: { id: 'v3', passkeys: 'p3' } },
	'vault locked': { data: FULL, locked: true },
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * Run viewVersion for one case.
 *
 * @param {object} spec The case.
 * @return {Promise<object>} The version or the error, and the calls made.
 */
async function run(spec) {
	calls.length = 0
	answer.data = spec.data
	const session = useSessionStore()
	session.cryptoKey = spec.locked ? null : 'KEY'
	const { rsaDecrypt } = await import('../../src/crypto/index.js')
	rsaDecrypt.mockImplementation(async (cipher, key) => {
		calls.push(`decrypt ${cipher}`)
		if (String(cipher).startsWith('bad')) {
			throw new Error(`cannot decrypt ${cipher}`)
		}
		if (cipher === 'json') {
			return '{"notes":"n","n":1}'
		}
		return `plain(${cipher}|${key})`
	})
	try {
		const version = await useSecretVersionStore().viewVersion('v-id')
		return { version, keys: Object.keys(version), calls: [...calls] }
	} catch (error) {
		return { threw: `${error.name}: ${error.message}`, calls: [...calls] }
	}
}

describe('viewVersion (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		const actual = await run(CASES[name])
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
