import { describe, expect, it } from 'vitest'
import {
	buildPasskeyCredential,
	parsePasskey,
	passkeyEntries,
	serializePasskey,
	withCounter,
	withoutPasskey,
	withPasskey,
	type PasskeyCredential,
} from './credential'

// The web app's own modules, so a drift in either shows here.
const webModule = (name: string) => import(/* @vite-ignore */ new URL(`../../../src/passkey/${name}`, import.meta.url).pathname)
const web = await webModule('passkey.js')
const webLogin = await webModule('loginPasskeys.js')

const FULL_CREDENTIAL: PasskeyCredential = {
	credentialId: 'BASE64URL_CREDENTIAL_ID_HERE',
	rpId: 'example.com',
	rpName: 'Example',
	userName: 'alice',
	userDisplayName: 'Alice Example',
	userHandle: 'BASE64URL_USER_HANDLE_HERE',
	privateKey: 'BASE64URL_PRIVATE_KEY_HERE',
	algorithm: -7,
	counter: 0,
	transports: ['internal', 'hybrid'],
	createdAt: '2026-07-01T00:00:00.000Z',
}

const A = { credentialId: 'a', rpId: 'a.example', privateKey: 'K1' }
const B = { credentialId: 'b', rpId: 'b.example', privateKey: 'K2' }
const STRANGE = ['opaque-string', 42, null, ['nested']]

describe("the web app's passkey.js cases", () => {
	it('round-trips every canonical field', () => {
		expect(parsePasskey(serializePasskey(FULL_CREDENTIAL))).toEqual(FULL_CREDENTIAL)
	})

	it('defaults only the documented extension fields', () => {
		const minimal = buildPasskeyCredential({ credentialId: 'ID', rpId: 'example.com', privateKey: 'KEY' })!
		expect(minimal.counter).toBe(0)
		expect(minimal.transports).toEqual([])
		expect(minimal.algorithm).toBe(-7)
		expect(minimal.createdAt).toBeTypeOf('string')
		expect(minimal.userName).toBe('')
	})

	it('yields null for malformed JSON and missing required fields', () => {
		expect(parsePasskey('not-json')).toBeNull()
		expect(parsePasskey('')).toBeNull()
		expect(parsePasskey('{"rpId":"example.com"}')).toBeNull()
		expect(serializePasskey({ credentialId: 'ID', rpId: 'example.com' })).toBeNull()
		expect(buildPasskeyCredential(null)).toBeNull()
	})
})

describe('against the web app', () => {
	const createdAt = '2026-01-02T03:04:05.000Z'
	const inputs: unknown[] = [
		FULL_CREDENTIAL,
		{ ...A, createdAt },
		{ privateKey: 'K', createdAt, rpId: 'r', credentialId: 'c', extra: 'dropped', counter: 1.5, algorithm: '-7' },
		{ ...A, createdAt, rpName: 12, userName: null, userDisplayName: undefined, userHandle: false, transports: [1, 'usb'] },
		{ ...A, createdAt, rpName: 'Ëxâmple "quoted" \\ /  ', userName: 'ålice@例え.jp' },
		{ ...A, createdAt: '' },
		{ ...A, credentialId: '' },
		{ ...A, rpId: 7 },
		[A],
		'string',
		42,
	]

	it.each(inputs.map((input, i) => [i, input]))('builds case %i as the web app does', (_i, input) => {
		const ours = buildPasskeyCredential(input)
		const theirs = web.buildPasskeyCredential(input)
		if (theirs === null) return expect(ours).toBeNull()
		// A missing `createdAt` is "now" on both sides, which may be a millisecond apart.
		expect(JSON.stringify({ ...ours, createdAt: '' })).toBe(JSON.stringify({ ...theirs, createdAt: '' }))
	})

	it('serialises byte for byte as the web app does', () => {
		for (const input of inputs.filter((input) => (input as { createdAt?: string }).createdAt)) {
			expect(serializePasskey(input)).toBe(web.serializePasskey(input))
		}
	})

	it('writes a passkeys value the web app reads back to the same bytes', () => {
		const json = withPasskey(withPasskey('', FULL_CREDENTIAL)!, { ...FULL_CREDENTIAL, credentialId: 'second', userName: 'bøb' })!
		const entries = webLogin.passkeyEntries(json)
		expect(entries.map((entry: { index: number }) => entry.index)).toEqual([0, 1])
		for (const { credential } of entries) {
			expect(web.serializePasskey(credential)).toBe(JSON.stringify(credential))
			expect(web.parsePasskey(JSON.stringify(credential))).toEqual(credential)
		}
		expect(JSON.stringify(entries.map((entry: { credential: unknown }) => entry.credential))).toBe(json)
	})
})

describe("the web app's loginPasskeys.js cases", () => {
	it('lists the object entries with their stored position', () => {
		expect(passkeyEntries(JSON.stringify([STRANGE[0], A, STRANGE[1], B]))).toEqual([
			{ index: 1, credential: A },
			{ index: 3, credential: B },
		])
	})

	it.each(['', 'not json', '{"credentialId":"a"}', null])('lists nothing for %j', (value) => {
		expect(passkeyEntries(value)).toEqual([])
	})

	it('keeps every entry it does not drop, whatever its shape', () => {
		const json = JSON.stringify([STRANGE[0], A, ...STRANGE.slice(1), B])
		expect(JSON.parse(withoutPasskey(json, 1))).toEqual([STRANGE[0], ...STRANGE.slice(1), B])
	})

	it("returns '' only when nothing remains", () => {
		expect(withoutPasskey(JSON.stringify([A]), 0)).toBe('')
		expect(JSON.parse(withoutPasskey(JSON.stringify([A, 'x']), 0))).toEqual(['x'])
	})

	it('returns a value that is not an array unchanged', () => {
		expect(withoutPasskey('not json', 0)).toBe('not json')
		expect(withoutPasskey('{"a":1}', 0)).toBe('{"a":1}')
	})
})

describe('changing a passkeys value', () => {
	// As the web app stores them: its serialiser, then JSON.stringify of the array.
	const first = web.buildPasskeyCredential({ ...FULL_CREDENTIAL, rpName: 'Ëxâmple / "x"', newerField: undefined })
	const imported = { ...web.buildPasskeyCredential({ ...FULL_CREDENTIAL, credentialId: 'imp', counter: 41 }), newerField: { nested: [1, 'ü'] } }
	const stored = JSON.stringify([first, 'opaque', imported])
	const firstBytes = JSON.stringify(first)

	it('appends, keeping every other entry byte for byte', () => {
		const json = withPasskey(stored, { ...FULL_CREDENTIAL, credentialId: 'new' })!
		expect(json.startsWith(stored.slice(0, -1) + ',')).toBe(true)
		expect(JSON.parse(json)[3].credentialId).toBe('new')
	})

	it('starts an array on null and empty', () => {
		expect(withPasskey(null, FULL_CREDENTIAL)).toBe(`[${JSON.stringify(FULL_CREDENTIAL)}]`)
		expect(withPasskey('', FULL_CREDENTIAL)).toBe(`[${JSON.stringify(FULL_CREDENTIAL)}]`)
	})

	it('replaces in place', () => {
		const json = withPasskey(stored, { ...FULL_CREDENTIAL, credentialId: 'swap' }, 2)!
		expect(JSON.parse(json).map((entry: { credentialId?: string } | string) => (typeof entry === 'string' ? entry : entry.credentialId))).toEqual([
			first.credentialId,
			'opaque',
			'swap',
		])
		expect(json.startsWith(`[${firstBytes},"opaque",`)).toBe(true)
	})

	it.each([
		['not json', undefined],
		['{"a":1}', undefined],
		[stored, 3],
		[stored, -1],
		[stored, 0.5],
	])('refuses %j at %j rather than lose it', (json, replace) => {
		expect(withPasskey(json, FULL_CREDENTIAL, replace)).toBeNull()
	})

	it('changes only the counter, keeping a newer client field', () => {
		const json = withCounter(stored, 2, 42)!
		expect(json).toBe(stored.replace('"counter":41', '"counter":42'))
		expect(json.startsWith(`[${firstBytes},"opaque",`)).toBe(true)
	})

	it.each([
		[1, 42],
		[3, 42],
		[2, -1],
		[2, 1.5],
	])('refuses a counter at entry %i of %i', (index, counter) => {
		expect(withCounter(stored, index, counter)).toBeNull()
	})

	it('round-trips an unchanged value byte for byte', () => {
		expect(withCounter(stored, 0, first.counter)).toBe(stored)
	})
})
