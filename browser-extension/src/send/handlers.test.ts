import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { setUnauthorizedHandler } from '@/src/api/client'
import { addAccount, getAccount, markLoggedOut, setActive } from '@/src/accounts/store'
import { pemToPkcs8 } from '@/src/crypto'
import { fromBase64, fromBase64Url, toBase64 } from '@/src/crypto/base64'
import type { SendCreateInput } from '@/src/messages'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { hasKey, putKey } from '@/src/vault/key-store'
import { syncStatus } from '@/src/vault/sync'
import { lock } from '@/src/vault/unlock'
import { argon2idHash, isArgon2Available } from './argon2'
import { wrapContentKey } from './crypto'
import { createSendFor, deleteSendFor, listSendsFor } from './handlers'

vi.mock('./argon2', async (importOriginal) => {
	const actual = await importOriginal<typeof import('./argon2')>()
	return { ...actual, isArgon2Available: vi.fn(actual.isArgon2Available) }
})

vi.mock('./crypto', async (importOriginal) => {
	const actual = await importOriginal<typeof import('./crypto')>()
	return { ...actual, wrapContentKey: vi.fn(actual.wrapContentKey) }
})

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const sent = () => fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${new URL(String(input)).pathname.replace('/index.php/apps/keepiq', '')}`)
const bodyOf = (line: string) => JSON.parse(String(fetchMock.mock.calls[sent().indexOf(line)]![1]!.body)) as Record<string, unknown>

const SERVER = 'https://cloud.example.org'
const TOKEN = 'f'.repeat(64)
const row = (overrides: Record<string, unknown> = {}) => ({
	id: 'b9c1', token: TOKEN, payloadType: 'text', hasPassword: false, maxViews: 1, viewCount: 0, remainingViews: 1,
	expiresAt: '2026-10-29T10:00:00+00:00', createdAt: '2026-10-22T10:00:00+00:00', ...overrides,
})
const input = (overrides: Partial<SendCreateInput> = {}): SendCreateInput => ({ payloadType: 'text', content: 'Pincode kluis: 0472', maxViews: 1, ttlSeconds: 604800, ...overrides })

/** The server echoes what a create sent, as `EphemeralSendController::create` does. */
function server() {
	fetchMock.mockImplementation(async (_input, init) => {
		if (init?.method === 'POST') {
			const body = JSON.parse(String(init.body)) as Record<string, unknown>
			return json(row({ payloadType: body.payloadType, hasPassword: body.hasPassword, maxViews: body.maxViews, remainingViews: body.maxViews }), 201)
		}
		return json([row(), row({ id: 'b2', token: 'e'.repeat(64), hasPassword: true, expiresAt: null })])
	})
}

async function open(key: CryptoKey, blob: string): Promise<string> {
	const bytes = fromBase64(blob)
	return new TextDecoder().decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: bytes.slice(0, 12) }, key, bytes.slice(12)))
}

/** What the recipient page derives to unwrap the content key. */
async function unwrap(password: string, body: Record<string, unknown>): Promise<CryptoKey> {
	const kek = await crypto.subtle.importKey('raw', await argon2idHash(password, fromBase64(body.argon2idSalt as string)) as Uint8Array<ArrayBuffer>, 'AES-GCM', false, ['decrypt'])
	const wrapped = fromBase64(body.wrappedKey as string)
	const raw = new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: wrapped.slice(0, 12) }, kek, wrapped.slice(12)))
	return crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
}

/** Everything both storage areas hold, as one string to search. */
const stored = async () => JSON.stringify([await browser.storage.local.get(null), await browser.storage.session.get(null)])
const sendKeys = async () => [...Object.keys(await browser.storage.local.get(null)), ...Object.keys(await browser.storage.session.get(null))].filter((key) => /send/i.test(key))

const CREDENTIAL = 'Username: alice\nPassword: hunter2'

let accountId: string

beforeEach(async () => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.mocked(isArgon2Available).mockClear()
	vi.mocked(wrapContentKey).mockClear()
	vi.spyOn(console, 'error').mockImplementation(() => {})
	accountId = (await addAccount({
		serverUrl: SERVER, uid: 'alice', loginName: 'alice', displayName: 'Alice', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })).id
	await putKey(accountId, toBase64(pemToPkcs8(envelope.privateKeyPem)))
})

describe('send.list', () => {
	it('lists the sends with a rebuilt link for password sends only', async () => {
		server()
		expect(await listSendsFor(accountId)).toEqual({
			ok: true,
			value: {
				sends: [row(), { ...row({ id: 'b2', token: 'e'.repeat(64), hasPassword: true, expiresAt: null }), link: `${SERVER}/index.php/apps/keepiq/public/send/${'e'.repeat(64)}` }],
				passwordProtection: true,
			},
		})
	})

	it('says when this browser cannot make a password send', async () => {
		server()
		vi.mocked(isArgon2Available).mockResolvedValueOnce(false)
		expect(await listSendsFor(accountId)).toMatchObject({ ok: true, value: { passwordProtection: false } })
	})
})

describe('send.create', () => {
	it('sends only ciphertext and hands back the link with the key in its fragment', async () => {
		server()
		const reply = await createSendFor(input({ maxViews: 3 }), accountId)
		if (!reply.ok) throw new Error(reply.error.code)
		const body = bodyOf('POST /api/v1/sends')
		expect(body).toEqual({ encryptedPayload: expect.any(String), payloadType: 'text', maxViews: 3, ttlSeconds: 604800, hasPassword: false })
		expect(JSON.stringify(body)).not.toContain('0472')
		expect(reply.value.row).toEqual(row({ maxViews: 3, remainingViews: 3 }))
		const [base, fragment] = reply.value.link.split('#k=')
		expect(base).toBe(`${SERVER}/index.php/apps/keepiq/public/send/${TOKEN}`)
		const key = await crypto.subtle.importKey('raw', fromBase64Url(fragment!), 'AES-GCM', false, ['decrypt'])
		expect(await open(key, body.encryptedPayload as string)).toBe('Pincode kluis: 0472')
	})

	it('wraps the key under the send password and hands back a link without one', async () => {
		server()
		const reply = await createSendFor(input({ payloadType: 'credential', content: CREDENTIAL, password: 'één wachtwoord' }), accountId)
		if (!reply.ok) throw new Error(reply.error.code)
		const body = bodyOf('POST /api/v1/sends')
		expect(body).toMatchObject({ payloadType: 'credential', hasPassword: true, wrappedKey: expect.any(String), argon2idSalt: expect.any(String) })
		expect(reply.value.link).toBe(`${SERVER}/index.php/apps/keepiq/public/send/${TOKEN}`)
		expect(reply.value.row.link).toBe(reply.value.link)
		expect(await open(await unwrap('één wachtwoord', body), body.encryptedPayload as string)).toBe(CREDENTIAL)
	}, 30_000)

	it('keeps nothing about the send in either storage area', async () => {
		server()
		const reply = await createSendFor(input(), accountId)
		if (!reply.ok) throw new Error(reply.error.code)
		const all = await stored()
		for (const secret of ['Pincode kluis', reply.value.link.split('#k=')[1]!, TOKEN, bodyOf('POST /api/v1/sends').encryptedPayload as string]) expect(all).not.toContain(secret)
		expect(await sendKeys()).toEqual([])
	})

	it('keeps nothing about a password send in either storage area, the password neither', async () => {
		server()
		const reply = await createSendFor(input({ payloadType: 'credential', content: CREDENTIAL, password: 'één wachtwoord' }), accountId)
		if (!reply.ok) throw new Error(reply.error.code)
		const body = bodyOf('POST /api/v1/sends')
		const all = await stored()
		for (const secret of ['hunter2', 'één wachtwoord', TOKEN, body.wrappedKey as string, body.argon2idSalt as string, body.encryptedPayload as string]) expect(all).not.toContain(secret)
		expect(await sendKeys()).toEqual([])
	}, 30_000)

	it.each<[string, () => Promise<void>, string]>([
		['the vault locks', () => lock(accountId), 'locked'],
		['the account logs out', () => markLoggedOut(accountId), 'locked'],
		['another account becomes active', async () => {
			const other = await addAccount({ serverUrl: 'https://other.example.org', uid: 'bob', loginName: 'bob', displayName: 'Bob', email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12' }, { ...suiteRow })
			await setActive(other.id)
		}, 'account_changed'],
	])('sends nothing when %s during Argon2id', async (_name, meanwhile, code) => {
		server()
		vi.mocked(wrapContentKey).mockImplementationOnce(async (rawKey, password) => {
			const result = await vi.importActual<typeof import('./crypto')>('./crypto').then((actual) => actual.wrapContentKey(rawKey, password))
			await meanwhile()
			return result
		})
		expect(await createSendFor(input({ password: 'secret' }), accountId)).toEqual({ ok: false, error: { code } })
		expect(sent()).not.toContain('POST /api/v1/sends')
	}, 30_000)

	it('refuses a password send whose Argon2id fails, without a request or clearing the offline flag', async () => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		await listSendsFor(accountId)
		expect(await syncStatus(accountId)).toMatchObject({ offline: true })
		fetchMock.mockClear()
		vi.mocked(wrapContentKey).mockRejectedValueOnce(new RangeError('WebAssembly.Memory(): could not allocate memory'))
		expect(await createSendFor(input({ password: 'secret' }), accountId)).toEqual({ ok: false, error: { code: 'argon2_unavailable' } })
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await syncStatus(accountId)).toMatchObject({ offline: true })
	})

	it('treats an empty send password as none', async () => {
		server()
		await createSendFor(input({ password: '' }), accountId)
		expect(bodyOf('POST /api/v1/sends')).toMatchObject({ hasPassword: false })
		expect(bodyOf('POST /api/v1/sends')).not.toHaveProperty('wrappedKey')
	})

	it('refuses a password send where Argon2id cannot run, without a request', async () => {
		vi.mocked(isArgon2Available).mockResolvedValueOnce(false)
		expect(await createSendFor(input({ password: 'secret' }), accountId)).toEqual({ ok: false, error: { code: 'argon2_unavailable' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it.each<[string, Partial<SendCreateInput>]>([
		['no content', { content: '  ' }],
		['an unknown type', { payloadType: 'file' as never }],
		['no views', { maxViews: 0 }],
		['more views than Keepiq allows', { maxViews: 101 }],
		['a fraction of a view', { maxViews: 1.5 }],
		['an expiry past Keepiq\'s cap', { ttlSeconds: 2592001 }],
		['no expiry', { ttlSeconds: 0 }],
		['an expiry under an hour', { ttlSeconds: 3599 }],
		['a credential without a password', { payloadType: 'credential', content: 'Username: alice\nPassword:  ' }],
		['a credential body that is not one', { payloadType: 'credential', content: 'alice / hunter2' }],
	])('refuses %s without a request', async (_name, overrides) => {
		expect(await createSendFor(input(overrides), accountId)).toEqual({ ok: false, error: { code: 'invalid' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('words a server refusal as a code, never with the server\'s text', async () => {
		fetchMock.mockResolvedValue(json({ message: 'maxViews must be between 1 and 100' }, 400))
		expect(await createSendFor(input(), accountId)).toEqual({ ok: false, error: { code: 'invalid' } })
	})

	it('refuses a malformed answer', async () => {
		fetchMock.mockResolvedValue(json({ id: 'b9c1' }, 201))
		expect(await createSendFor(input(), accountId)).toEqual({ ok: false, error: { code: 'server_error' } })
	})
})

describe('send.delete', () => {
	it('revokes the send', async () => {
		fetchMock.mockResolvedValue(json({ revoked: true }))
		expect(await deleteSendFor('b9c1', accountId)).toEqual({ ok: true, value: null })
		expect(sent()).toEqual(['DELETE /api/v1/sends/b9c1'])
	})

	it('takes a send that is already gone as removed', async () => {
		fetchMock.mockResolvedValue(json({ message: 'Send not found' }, 404))
		expect(await deleteSendFor('b9c1', accountId)).toEqual({ ok: true, value: null })
	})

	it('passes on a server error', async () => {
		fetchMock.mockResolvedValue(json({ message: 'Down' }, 500))
		expect(await deleteSendFor('b9c1', accountId)).toEqual({ ok: false, error: { code: 'server_error' } })
	})
})

describe('every send request', () => {
	const requests = [
		['list', () => listSendsFor(accountId)],
		['create', () => createSendFor(input(), accountId)],
		['delete', () => deleteSendFor('b9c1', accountId)],
	] as const

	it.each(requests)('%s refuses while locked without a request', async (_name, request) => {
		await lock(accountId)
		expect(await request()).toEqual({ ok: false, error: { code: 'locked' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it.each(requests)('%s refuses for an account that is no longer active, without a request', async (name) => {
		const other = { list: () => listSendsFor('another'), create: () => createSendFor(input(), 'another'), delete: () => deleteSendFor('b9c1', 'another') }[name]
		expect(await other()).toEqual({ ok: false, error: { code: 'account_changed' } })
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it.each(requests)('%s answers offline when the server does not answer, and marks the account offline', async (_name, request) => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		expect(await request()).toEqual({ ok: false, error: { code: 'offline' } })
		expect(await syncStatus(accountId)).toMatchObject({ offline: true })
	})

	it.each(requests)('%s logs the account out on a 401', async (_name, request) => {
		setUnauthorizedHandler((id) => markLoggedOut(id, true))
		try {
			fetchMock.mockResolvedValue(new Response('', { status: 401 }))
			expect(await request()).toEqual({ ok: false, error: { code: 'session_revoked' } })
			expect(await getAccount(accountId)).toMatchObject({ appPassword: null, revoked: true })
			expect(await hasKey(accountId)).toBe(false)
		} finally {
			setUnauthorizedHandler(async () => {})
		}
	})

	it.each(requests)('%s takes Nextcloud\'s HTML 404 for a missing Keepiq as not found', async (_name, request) => {
		fetchMock.mockResolvedValue(new Response('<html></html>', { status: 404 }))
		expect(await request()).toEqual({ ok: false, error: { code: 'not_found' } })
	})
})

describe('a worker restart', () => {
	it('finds no link of a send without a password, only rebuilt password links', async () => {
		server()
		const created = await createSendFor(input(), accountId)
		if (!created.ok) throw new Error(created.error.code)
		const reply = await listSendsFor(accountId)
		if (!reply.ok) throw new Error(reply.error.code)
		expect(reply.value.sends.map((send) => send.link)).toEqual([undefined, `${SERVER}/index.php/apps/keepiq/public/send/${'e'.repeat(64)}`])
		expect(await stored()).not.toContain('#k=')
	})
})
