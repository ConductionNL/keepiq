/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for migrateEmergencyContacts in the encryption-suite store.
 * Every contact state, suite binding and confirmation is crossed with every
 * failure of the carry steps (certificate lookup, envelope build, key proof,
 * re-point post), plus contact lists of mixed contacts and unusual answers.
 * Each case records every request with its parameters and body, every
 * envelope and proof call, and the residual list, compared with
 * golden/migrate-emergency-contacts.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
let world = {}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url, config) => {
			trace.push(['get', url, config ?? '(none)'])
			if (url.endsWith('/emergency-access/contacts')) {
				if (world.listFails) {
					throw new Error('list failed')
				}
				return { data: world.contacts }
			}
			const grantee = config.params.granteeUserId
			if (world.fail === 'cert' || world.failFor === grantee) {
				throw new Error('no active certificate')
			}
			return {
				data: {
					suiteId: `suite-of-${grantee}`,
					certificate: `CERT-${grantee}`,
				},
			}
		}),
		post: vi.fn(async (url, body, config) => {
			trace.push(['post', url, body, config])
			if (world.fail === 'post') {
				throw new Error('post failed')
			}
			return { data: {} }
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/x${url}` }))
vi.mock('../../src/store/modules/offline.js', () => ({
	useOfflineStore: () => ({ evict: async () => {} }),
}))
vi.mock('../../src/crypto/emergencyEnvelope.js', () => ({
	buildRecoveryEnvelope: vi.fn(async (pem, certificate) => {
		trace.push(['envelope', pem, certificate])
		if (world.fail === 'envelope') {
			throw new Error('envelope failed')
		}
		return `ENV(${certificate})`
	}),
}))
vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	buildKeyProofHeaders: vi.fn(async (args) => {
		trace.push(['proof', args])
		if (world.fail === 'proof') {
			throw new Error('proof failed')
		}
		return { 'X-Keepiq-Key-Proof': `SIG(${args.boundValues.join('|')})` }
	}),
}))

const { useEncryptionSuiteStore } =
	await import('../../src/store/modules/encryptionSuite.js')

const GOLDEN = path.join(__dirname, 'golden', 'migrate-emergency-contacts.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

const ROTATION = {
	migrationId: 'migr-1',
	oldSuiteId: 'old-suite',
	newPrivateKeyPem: 'NEW-PEM',
	newSuiteId: 'new-suite',
	newEncryptedPrivateKey: 'NEW-ENVELOPE',
	newPassword: 'new-pw',
}

const STATES = [
	'granted',
	'requested',
	'approved',
	'declined',
	'invalidated',
	'pending',
	undefined,
]
const SUITES = ['old-suite', 'prior-suite', undefined]
const FAILS = [null, 'cert', 'envelope', 'proof', 'post']

const CASES = {}
for (const state of STATES) {
	for (const suite of SUITES) {
		for (const ticked of [true, false]) {
			for (const fail of FAILS) {
				CASES[
					`one ${state} on ${suite}, ${ticked ? 'ticked' : 'unticked'}, fail ${fail}`
				] = {
					world: {
						fail,
						contacts: [
							{
								id: 'c1',
								granteeUserId: 'bob',
								state,
								grantorSuiteId: suite,
							},
						],
					},
					carry: ticked ? ['c1'] : ['other'],
				}
			}
		}
	}
}

const MIXED = [
	{
		id: 'c1',
		granteeUserId: 'bob',
		state: 'granted',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c2',
		granteeUserId: 'carol',
		state: 'requested',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c3',
		granteeUserId: 'dave',
		state: 'granted',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c4',
		granteeUserId: 'erin',
		state: 'declined',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c5',
		granteeUserId: 'frank',
		state: 'granted',
		grantorSuiteId: 'prior-suite',
	},
	{
		id: 'c6',
		granteeUserId: 'gina',
		state: 'invalidated',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c7',
		granteeUserId: 'hank',
		state: 'approved',
		grantorSuiteId: 'old-suite',
	},
	{
		id: 'c8',
		granteeUserId: 'ivy',
		state: 'granted',
		grantorSuiteId: 'old-suite',
	},
]
Object.assign(CASES, {
	'mixed, all ticked': {
		world: { contacts: MIXED },
		carry: MIXED.map((c) => c.id),
	},
	'mixed, some ticked': { world: { contacts: MIXED }, carry: ['c1', 'c2', 'c8'] },
	'mixed, one grantee unreachable': {
		world: { contacts: MIXED, failFor: 'dave' },
		carry: MIXED.map((c) => c.id),
	},
	'mixed, every post fails': {
		world: { contacts: MIXED, fail: 'post' },
		carry: MIXED.map((c) => c.id),
	},
	'mixed, no carry list': { world: { contacts: MIXED }, carry: undefined },
	'mixed, empty carry list': { world: { contacts: MIXED }, carry: [] },
	'same contact twice': {
		world: { contacts: [MIXED[0], MIXED[0]] },
		carry: ['c1'],
	},
	'contact list fails': {
		world: { listFails: true, contacts: MIXED },
		carry: ['c1'],
	},
	'contact list not an array': {
		world: { contacts: { c1: MIXED[0] } },
		carry: ['c1'],
	},
	'contact list null': { world: { contacts: null }, carry: ['c1'] },
	'contact list empty': { world: { contacts: [] }, carry: ['c1'] },
	'ticked id is a number': {
		world: { contacts: [{ ...MIXED[0], id: 1 }] },
		carry: ['1'],
	},
	'grantee without id': {
		world: {
			contacts: [{ id: 'c1', state: 'granted', grantorSuiteId: 'old-suite' }],
		},
		carry: ['c1'],
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
		return { returned: await call }
	} catch (error) {
		return { threw: error.message, name: error.name }
	}
}

describe('migrateEmergencyContacts (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		trace.length = 0
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		const c = CASES[name]
		world = c.world
		const rotation =
			c.carry === undefined
				? { ...ROTATION }
				: { ...ROTATION, carryContactIds: c.carry }
		const result = await outcome(
			useEncryptionSuiteStore().migrateEmergencyContacts(rotation),
		)
		const actual = JSON.parse(JSON.stringify({ result, trace }))
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
