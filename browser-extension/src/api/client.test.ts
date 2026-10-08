import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	ApiError, createClient, KeepiqNotInstalled, NotNextcloud, Offline, SessionRevoked, setUnauthorizedHandler, VaultWriteLocked,
} from './client'

const account = { id: 'a1', origin: 'https://cloud.example.org', uid: 'alice', loginName: 'alice@example.org', appPassword: 'secret' }
const fetchMock = vi.fn<typeof fetch>()

function reply(status: number, body?: unknown, headers: Record<string, string> = {}) {
	const text = typeof body === 'string' ? body : JSON.stringify(body)
	return new Response(body === undefined ? null : text, { status, headers })
}

beforeEach(() => {
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})
afterEach(() => setUnauthorizedHandler(async () => {}))

describe('request shape', () => {
	it('calls the Keepiq route with auth, the OCS header and no cookies', async () => {
		fetchMock.mockResolvedValue(reply(200, []))
		await createClient(account).listSuites()
		const [url, init] = fetchMock.mock.calls[0]!
		expect(url).toBe('https://cloud.example.org/index.php/apps/keepiq/api/v1/suites')
		expect(init?.credentials).toBe('omit')
		expect(init?.headers).toMatchObject({
			Authorization: `Basic ${btoa('alice@example.org:secret')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		})
	})

	it('sends a JSON body on writes', async () => {
		fetchMock.mockResolvedValue(reply(201, {}))
		await createClient(account).keepiq('POST', '/api/v1/secrets', { name: 'x' })
		const init = fetchMock.mock.calls[0]![1]
		expect(init?.body).toBe('{"name":"x"}')
		expect(init?.headers).toMatchObject({ 'Content-Type': 'application/json' })
	})

	it('unwraps the OCS identity', async () => {
		fetchMock.mockResolvedValue(reply(200, { ocs: { data: { id: 'alice', displayname: 'Alice', email: null } } }))
		expect(await createClient(account).fetchIdentity()).toEqual({ id: 'alice', displayname: 'Alice', email: null })
		expect(fetchMock.mock.calls[0]![0]).toBe('https://cloud.example.org/ocs/v2.php/cloud/user?format=json')
	})

	it('reports a server without the OCS envelope as not Nextcloud', async () => {
		fetchMock.mockResolvedValue(reply(200, '<html></html>'))
		await expect(createClient(account).fetchIdentity()).rejects.toBeInstanceOf(NotNextcloud)
	})
})

describe('errors', () => {
	it('treats 401 as revocation and calls the hook once', async () => {
		const hook = vi.fn(async () => {})
		setUnauthorizedHandler(hook)
		fetchMock.mockResolvedValue(reply(401, { message: 'Unauthorized' }))
		await expect(createClient(account).listSuites()).rejects.toBeInstanceOf(SessionRevoked)
		expect(hook).toHaveBeenCalledExactlyOnceWith('a1')
	})

	it('does not call the hook for an account that is not stored yet', async () => {
		const hook = vi.fn(async () => {})
		setUnauthorizedHandler(hook)
		fetchMock.mockResolvedValue(reply(401))
		await expect(createClient({ ...account, id: undefined }).fetchIdentity()).rejects.toBeInstanceOf(SessionRevoked)
		expect(hook).not.toHaveBeenCalled()
	})

	it('treats 423 as a write lock, not an auth failure', async () => {
		const hook = vi.fn(async () => {})
		setUnauthorizedHandler(hook)
		fetchMock.mockResolvedValue(reply(423, { message: 'Migrating' }))
		await expect(createClient(account).keepiq('PUT', '/api/v1/secrets/1', {})).rejects.toEqual(new VaultWriteLocked(423, 'Migrating'))
		expect(hook).not.toHaveBeenCalled()
	})

	it('treats a network failure as offline', async () => {
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		await expect(createClient(account).listSuites()).rejects.toBeInstanceOf(Offline)
	})

	it('carries the server message on other errors', async () => {
		fetchMock.mockResolvedValue(reply(400, { message: 'name is required' }))
		const error = await createClient(account).keepiq('POST', '/api/v1/secrets', {}).catch((e) => e)
		expect(error).toBeInstanceOf(ApiError)
		expect(error).toMatchObject({ status: 400, message: 'name is required' })
	})

	it('reports an HTML 404 on a Keepiq route as Keepiq not installed', async () => {
		fetchMock.mockResolvedValue(reply(404, '<html>Not found</html>'))
		await expect(createClient(account).listSuites()).rejects.toBeInstanceOf(KeepiqNotInstalled)
	})

	it('never sends a request for a logged out account', async () => {
		await expect(createClient({ ...account, appPassword: null }).listSuites()).rejects.toBeInstanceOf(SessionRevoked)
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('avatar', () => {
	it('returns a data URL', async () => {
		fetchMock.mockResolvedValue(new Response(new Uint8Array([1, 2, 3]), { status: 200, headers: { 'Content-Type': 'image/png' } }))
		expect(await createClient(account).fetchAvatarDataUrl()).toBe('data:image/png;base64,AQID')
		expect(fetchMock.mock.calls[0]![0]).toBe('https://cloud.example.org/index.php/avatar/alice/64')
	})

	it('returns null on failure', async () => {
		fetchMock.mockResolvedValue(reply(404))
		expect(await createClient(account).fetchAvatarDataUrl()).toBeNull()
		fetchMock.mockRejectedValue(new TypeError())
		expect(await createClient(account).fetchAvatarDataUrl()).toBeNull()
	})
})
