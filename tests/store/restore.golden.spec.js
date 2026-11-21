/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for restore in the secretVersion store: versions with every
 * mix of a missing, null or set url and key, and a totp and passkeys that are
 * missing, null, empty or set, restored over a head that holds its own totp.
 * Each case records the calls restore makes in order: the settle, the
 * version and head reads, the share save with its patch, plain diff and full
 * value, the owner-only notice and the reloads. The share save and the
 * encryption are doubles that echo what they get, compared with
 * golden/restore.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/secret-version-history/spec.md#requirement-a-restore-is-one-share-save-of-the-version
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
const rows = {}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			calls.push(`GET ${url}`)
			return { data: rows[url.split('/').pop()] ?? [] }
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('../../src/crypto/index.js', () => ({
	rsaDecrypt: vi.fn(async (cipher, key) => `dec(${cipher}|${key})`),
}))
vi.mock('../../src/store/modules/secret.js', () => ({
	useSecretStore: () => ({
		writeBackSettled: async (id) => calls.push(`settle ${id}`),
		baseOf: (id) => `base-${id}`,
		fetchSecret: async (id) => calls.push(`fetchSecret ${id}`),
	}),
}))
vi.mock('../../src/services/shareSave.js', () => ({
	plainOf: async (row) => {
		const out = {}
		for (const field of [
			'key',
			'login',
			'additionalFields',
			'totp',
			'passkeys',
		]) {
			out[field] = row[field] ? `p:${row[field]}` : null
		}
		return out
	},
	encryptSparse: async (diff, certificate) =>
		Object.fromEntries(
			Object.entries(diff).map(([field, value]) => [
				field,
				value === '' ? '' : `E(${value}|${certificate})`,
			]),
		),
	saveShared: async (args) => {
		const full = await args.loadFull()
		calls.push({
			saveShared: { ...args, loadFull: undefined },
			patchKeys: Object.keys(args.patch),
			full,
		})
		return { ok: 1 }
	},
	notifyOwnerOnly: async (result) => calls.push({ notify: result }),
	shareSaveErrorText: () => null,
}))

const { useSecretVersionStore } =
	await import('../../src/store/modules/secretVersion.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')

const GOLDEN = path.join(__dirname, 'golden', 'restore.json')

const BLOBS = { missing: undefined, null: null, empty: '', set: 'x' }
const CASES = {}
for (const [urlName, url] of Object.entries({
	'no url': undefined,
	'null url': null,
	url: 'https://u',
})) {
	for (const [keyName, key] of Object.entries({ 'no key': null, key: 'k' })) {
		for (const [totpName, totp] of Object.entries(BLOBS)) {
			for (const [passkeysName, passkeys] of Object.entries(BLOBS)) {
				CASES[
					`${urlName}, ${keyName}, totp ${totpName}, passkeys ${passkeysName}`
				] = {
					id: 'v1',
					name: 'Version name',
					url,
					key,
					login: 'l',
					totp,
					passkeys,
				}
			}
		}
	}
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('restore (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		calls.length = 0
		rows.v1 = CASES[name]
		rows.src = { id: 'src', name: 'Head name', totp: 'ht', passkeys: null }
		const session = useSessionStore()
		session.cryptoKey = 'KEY'
		session.certificate = 'CERT'
		const store = useSecretVersionStore()
		await store.restore('v1', 'src')
		const actual = {
			calls: JSON.parse(JSON.stringify(calls)),
			error: store.error,
		}
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
