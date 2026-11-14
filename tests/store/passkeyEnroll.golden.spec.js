/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for enroll in the passkey store. Each case scripts the server,
 * the authenticator and the crypto helpers, and records every call in order
 * (requests with their bodies, create and get options, random draws, key
 * derivation and wrapping), what enroll returns or throws, and the store's
 * error and credentials, compared with golden/passkey-enroll.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-prf-support-is-feature-detected-and-degrades-gracefully
 */

import fs from 'node:fs'
import path from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const trace = []
let script = {}

/**
 * A JSON-safe copy of a value: byte buffers become number lists.
 *
 * @param {*} value Any value.
 * @return {*}
 */
function plain(value) {
	if (value instanceof ArrayBuffer) {
		return { bytes: Array.from(new Uint8Array(value)) }
	}
	if (ArrayBuffer.isView(value)) {
		return { bytes: Array.from(value) }
	}
	if (Array.isArray(value)) {
		return value.map(plain)
	}
	if (value && typeof value === 'object') {
		return Object.fromEntries(
			Object.entries(value).map(([k, v]) => [k, plain(v)]),
		)
	}
	return value === undefined ? '(undefined)' : value
}

/**
 * Settle a scripted step: throw its failure or return its value.
 *
 * @param {string} name The step name in the script.
 * @param {*} fallback The value when the script has none.
 * @return {*}
 */
function step(name, fallback) {
	const entry = script[name]
	if (entry && entry.fail) {
		throw entry.fail
	}
	return entry && 'value' in entry ? entry.value : fallback
}

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(async (url) => {
			trace.push(['get', url])
			if (url.endsWith('/login-options')) {
				return step('options', { data: { credentials: [] } })
			}
			if (url.endsWith('/challenge')) {
				return step('challenge', {
					data: { challenge: 'AQID', assertionChallenge: 'BAUG' },
				})
			}
			if (script.slowList) {
				await new Promise((resolve) => setTimeout(resolve, 5))
			}
			return step('list', { data: [] })
		}),
		post: vi.fn(async (url, body) => {
			trace.push(['post', url, plain(body)])
			return step('post', { data: { id: 'new', status: 'active' } })
		}),
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/x${url}` }))
vi.mock('../../src/crypto/aes.js', () => ({
	deriveUnlockKeyRaw: vi.fn(async (password, salt) => {
		trace.push(['derive', password, plain(salt)])
		return step('derive', new Uint8Array([9, 9]))
	}),
}))
vi.mock('../../src/crypto/envelope.js', () => ({
	decodeEnvelope: vi.fn((envelope) => {
		trace.push(['decode', envelope])
		return step('decode', { salt: new Uint8Array([5, 6]) })
	}),
}))
vi.mock('../../src/crypto/passkey.js', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		isPrfSupported: () => true,
		deriveKekFromPrf: vi.fn(async (prf, credentialId) => {
			trace.push(['kek', plain(prf), credentialId])
			return step('kek', 'KEK')
		}),
		wrapUnlockKey: vi.fn(async (kek, raw) => {
			trace.push(['wrap', kek, plain(raw)])
			return step('wrap', 'WRAPPED')
		}),
	}
})

const { usePasskeyStore } = await import('../../src/store/modules/passkey.js')
const { useSessionStore } = await import('../../src/store/modules/session.js')

const GOLDEN = path.join(__dirname, 'golden', 'passkey-enroll.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * A credential as create() returns it.
 *
 * @param {object} over Fields to change.
 * @return {object}
 */
function created(over = {}) {
	return {
		rawId: new Uint8Array([7, 8, 250]).buffer,
		getClientExtensionResults: () => ({ prf: { enabled: true } }),
		response: {
			getTransports: () => ['usb', 'nfc'],
			clientDataJSON: new Uint8Array([123, 125]).buffer,
			attestationObject: new Uint8Array([160]).buffer,
		},
		...over,
	}
}

/**
 * An assertion as get() returns it.
 *
 * @param {*} first The PRF output, or undefined.
 * @return {object}
 */
function assertion(first) {
	return {
		rawId: new Uint8Array([7, 8, 250]).buffer,
		response: {
			clientDataJSON: new Uint8Array([123, 34, 125]).buffer,
			authenticatorData: new Uint8Array([1, 2]).buffer,
			signature: new Uint8Array([48, 6]).buffer,
			userHandle: new Uint8Array([97]).buffer,
		},
		getClientExtensionResults: () => ({ prf: { results: { first } } }),
	}
}

const PRF = new Uint8Array([1, 1, 2, 3]).buffer
const invalidState = Object.assign(new Error('excluded'), {
	name: 'InvalidStateError',
})

const CASES = {
	'enrolls with a label': { args: ['pw', 'Laptop'] },
	'enrolls with no label': { args: ['pw', ''] },
	'enrolls with an undefined label': { args: ['pw', undefined] },
	'challenge with plus and slash': {
		script: {
			challenge: {
				value: { data: { challenge: 'a+b/c+/', assertionChallenge: 'd+e/' } },
			},
		},
	},
	'no current user': { oc: null },
	'current user without uid': { oc: { getCurrentUser: () => ({}) } },
	'current user missing': { oc: { getCurrentUser: () => null } },
	'excludes the active passkeys': {
		script: {
			options: {
				value: {
					data: {
						credentials: [
							{ credentialId: 'AQID' },
							{ credentialId: '_-8' },
						],
					},
				},
			},
		},
	},
	'options without credentials key': {
		script: { options: { value: { data: {} } } },
	},
	'options with null data': { script: { options: { value: { data: null } } } },
	'vault locked: no key': { session: { cryptoKey: null } },
	'vault locked: no envelope': { session: { encryptedPrivateKey: '' } },
	'challenge request fails': {
		script: { challenge: { fail: new Error('challenge down') } },
	},
	'options request fails': {
		script: { options: { fail: new Error('options down') } },
	},
	'authenticator already enrolled': { create: { fail: invalidState } },
	'authenticator refuses otherwise': {
		create: {
			fail: Object.assign(new Error('nope'), { name: 'NotAllowedError' }),
		},
	},
	'authenticator throws a non-error': { create: { fail: 'string failure' } },
	'create returns null': { create: { value: null } },
	'no prf extension': {
		create: { value: created({ getClientExtensionResults: () => ({}) }) },
	},
	'prf not enabled': {
		create: {
			value: created({
				getClientExtensionResults: () => ({ prf: { enabled: false } }),
			}),
		},
	},
	'extension results null': {
		create: { value: created({ getClientExtensionResults: () => null }) },
	},
	'no transports function': { create: { value: created({ response: {} }) } },
	'no response': { create: { value: created({ response: undefined }) } },
	'transports null': {
		create: { value: created({ response: { getTransports: () => null } }) },
	},
	'get fails': { get: { fail: new Error('get refused') } },
	'get without prf result': { get: { value: assertion(undefined) } },
	'get with no extension results': {
		get: { value: { getClientExtensionResults: () => undefined } },
	},
	'envelope decode fails': {
		script: { decode: { fail: new Error('bad envelope') } },
	},
	'derive fails': { script: { derive: { fail: new Error('derive failed') } } },
	'kek fails': { script: { kek: { fail: new Error('kek failed') } } },
	'wrap fails': { script: { wrap: { fail: new Error('wrap failed') } } },
	'post fails': {
		script: {
			post: {
				fail: Object.assign(new Error('post failed'), {
					response: { data: { message: 'Denied' } },
				}),
			},
		},
	},
	'list after enrol fails': {
		script: {
			list: {
				fail: Object.assign(new Error('list failed'), {
					response: { data: { message: 'List down' } },
				}),
			},
		},
	},
	'list after enrol has a stale passkey': {
		script: { list: { value: { data: [{ id: 'a', status: 'stale' }] } } },
	},
	'previous error is cleared': { error: 'old error' },
	'list after enrol answers late': {
		script: {
			slowList: true,
			list: { value: { data: [{ id: 'a', status: 'active' }] } },
		},
	},
	'list after enrol fails late': {
		script: {
			slowList: true,
			list: { fail: new Error('late failure') },
		},
	},
}

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
		if (!(error instanceof Error)) {
			return { threwValue: plain(error) }
		}
		return {
			threw: error.message,
			name: error.name,
			cause: error.cause ? error.cause.message : '(none)',
		}
	}
}

describe('passkey enroll (golden)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		trace.length = 0
		script = {}
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		delete window.OC
	})

	it.each(Object.keys(CASES))('%s', async (name) => {
		const c = CASES[name]
		script = c.script ?? {}
		const session = useSessionStore()
		session.cryptoKey = {}
		session.encryptedPrivateKey = 'ENVELOPE'
		Object.assign(session, c.session ?? {})
		window.OC = 'oc' in c ? c.oc : { getCurrentUser: () => ({ uid: 'alice' }) }
		let draws = 0
		vi.stubGlobal('crypto', {
			getRandomValues: (array) => {
				draws++
				trace.push(['random', array.length])
				array.forEach((_, i) => {
					array[i] = (i * 7 + draws) % 256
				})
				return array
			},
		})
		const settle = (label, entry, fallback) => async (options) => {
			trace.push([label, plain(options)])
			if (entry && 'fail' in entry) {
				throw entry.fail
			}
			return entry && 'value' in entry ? entry.value : fallback
		}
		vi.stubGlobal('navigator', {
			credentials: {
				create: settle('create', c.create, created()),
				get: settle('get', c.get, assertion(PRF)),
			},
		})
		const store = usePasskeyStore()
		store.error = c.error ?? null
		const result = await outcome(store.enroll(...(c.args ?? ['pw', 'Key'])))
		const actual = {
			result,
			error: store.error ?? '(null)',
			credentials: plain(store.credentials),
			hasActive: store.hasActive,
			trace: JSON.parse(JSON.stringify(trace)),
		}
		if (RECORD) {
			recorded[name] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
