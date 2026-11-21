import { beforeEach, describe, expect, it, vi } from 'vitest'
import { Failure } from '@/src/failure'
import { awaitLoginFlow, deleteAppPassword, LOGIN_FLOW_POLL_MS, LOGIN_FLOW_TIMEOUT_MS, pollLoginFlow, startLoginFlow, type LoginFlowDeps } from './login-flow'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const server = 'https://cloud.example.org'
const flow = { loginUrl: `${server}/index.php/login/v2/flow/abc`, token: 'poll-token', endpoint: `${server}/index.php/login/v2/poll` }
const signedIn = { server, loginName: 'alice', appPassword: 'app-pw' }

beforeEach(() => {
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})

/** A clock that moves by each wait, and a tab that stays open unless told otherwise. */
function deps(overrides: Partial<LoginFlowDeps> = {}): LoginFlowDeps & { waited: () => number } {
	let time = 0
	return {
		tabOpen: async () => true,
		wait: async (ms) => { time += ms },
		now: () => time,
		signal: new AbortController().signal,
		waited: () => time,
		...overrides,
	}
}

describe('startLoginFlow', () => {
	it('posts to login/v2 without cookies or credentials', async () => {
		fetchMock.mockResolvedValue(json({ login: flow.loginUrl, poll: { token: flow.token, endpoint: flow.endpoint } }))
		expect(await startLoginFlow(server)).toEqual(flow)
		const [url, init] = fetchMock.mock.calls[0]!
		expect(url).toBe(`${server}/index.php/login/v2`)
		expect(init).toMatchObject({ method: 'POST', credentials: 'omit' })
		expect(init?.headers).not.toHaveProperty('Authorization')
	})

	it('refuses a poll endpoint or login page on another origin', async () => {
		fetchMock.mockResolvedValue(json({ login: flow.loginUrl, poll: { token: flow.token, endpoint: 'https://elsewhere.example/poll' } }))
		await expect(startLoginFlow(server)).rejects.toMatchObject({ code: 'not_nextcloud' })
		fetchMock.mockResolvedValue(json({ login: 'https://elsewhere.example/login', poll: { token: flow.token, endpoint: flow.endpoint } }))
		await expect(startLoginFlow(server)).rejects.toMatchObject({ code: 'not_nextcloud' })
	})

	it('names an unreachable server, a server error and a non-Nextcloud answer', async () => {
		fetchMock.mockRejectedValueOnce(new TypeError('offline'))
		await expect(startLoginFlow(server)).rejects.toMatchObject({ code: 'unreachable' })
		fetchMock.mockResolvedValueOnce(new Response(null, { status: 503 }))
		await expect(startLoginFlow(server)).rejects.toMatchObject({ code: 'server_error' })
		fetchMock.mockResolvedValueOnce(new Response('<html></html>', { status: 200 }))
		await expect(startLoginFlow(server)).rejects.toMatchObject({ code: 'not_nextcloud' })
	})
})

describe('pollLoginFlow', () => {
	it('sends the token form-encoded and reads 404 as not yet', async () => {
		fetchMock.mockResolvedValue(new Response(null, { status: 404 }))
		expect(await pollLoginFlow(flow)).toBeNull()
		const [url, init] = fetchMock.mock.calls[0]!
		expect(url).toBe(flow.endpoint)
		expect(init).toMatchObject({ method: 'POST', credentials: 'omit', body: 'token=poll-token' })
	})

	it('returns the login name and app password once signed in', async () => {
		fetchMock.mockResolvedValue(json(signedIn))
		expect(await pollLoginFlow(flow)).toEqual({ loginName: 'alice', appPassword: 'app-pw' })
	})

	it('refuses an answer without an app password', async () => {
		fetchMock.mockResolvedValue(json({ server, loginName: 'alice' }))
		await expect(pollLoginFlow(flow)).rejects.toBeInstanceOf(Failure)
	})
})

describe('awaitLoginFlow', () => {
	it('polls until the user signed in', async () => {
		fetchMock.mockResolvedValueOnce(new Response(null, { status: 404 })).mockResolvedValueOnce(json(signedIn))
		const run = deps()
		expect(await awaitLoginFlow(flow, run)).toEqual({ loginName: 'alice', appPassword: 'app-pw' })
		expect(run.waited()).toBe(2 * LOGIN_FLOW_POLL_MS)
	})

	it('stops without credentials when the login tab is closed', async () => {
		fetchMock.mockResolvedValue(new Response(null, { status: 404 }))
		let open = 2
		expect(await awaitLoginFlow(flow, deps({ tabOpen: async () => open-- > 0 }))).toBeNull()
		expect(fetchMock).toHaveBeenCalledTimes(2)
	})

	it('stops after 20 minutes', async () => {
		fetchMock.mockResolvedValue(new Response(null, { status: 404 }))
		const run = deps()
		expect(await awaitLoginFlow(flow, run)).toBeNull()
		expect(run.waited()).toBe(LOGIN_FLOW_TIMEOUT_MS)
	})

	it('retries a network error and stops on a server refusal', async () => {
		fetchMock.mockRejectedValueOnce(new TypeError('offline')).mockResolvedValueOnce(json(signedIn))
		expect(await awaitLoginFlow(flow, deps())).toEqual({ loginName: 'alice', appPassword: 'app-pw' })
		fetchMock.mockResolvedValueOnce(new Response(null, { status: 500 }))
		await expect(awaitLoginFlow(flow, deps())).rejects.toMatchObject({ code: 'server_error' })
	})

	it('stops when aborted by a newer flow', async () => {
		const controller = new AbortController()
		controller.abort()
		expect(await awaitLoginFlow(flow, deps({ signal: controller.signal }))).toBeNull()
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

it('deletes an unused app password with that app password', async () => {
	fetchMock.mockResolvedValue(json({}))
	await deleteAppPassword(server, { loginName: 'alice', appPassword: 'app-pw' })
	const [url, init] = fetchMock.mock.calls[0]!
	expect(url).toBe(`${server}/ocs/v2.php/core/apppassword`)
	expect(init).toMatchObject({ method: 'DELETE', credentials: 'omit', headers: { Authorization: `Basic ${btoa('alice:app-pw')}`, 'OCS-APIRequest': 'true' } })
})
