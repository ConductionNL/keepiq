import { beforeEach, describe, expect, it, vi } from 'vitest'
import { suiteRow } from '@/src/testing/vectors'
import { verifyCredentials } from './verify'

const fetchMock = vi.fn<typeof fetch>()
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status })
const identity = json(200, { ocs: { data: { id: 'alice', displayname: 'Alice A', email: '' } } })

/** Answers by route: identity, suites, avatar. */
function server(routes: { identity?: Response | Error; suites?: Response; avatar?: Response }) {
	fetchMock.mockImplementation(async (url) => {
		const path = String(url)
		const answer = path.includes('/cloud/user') ? routes.identity
			: path.includes('/api/v1/suites') ? routes.suites
				: routes.avatar ?? new Response(null, { status: 404 })
		if (answer instanceof Error) throw answer
		return answer!.clone()
	})
}

beforeEach(() => {
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})

const verify = () => verifyCredentials('https://cloud.example.org', 'alice@example.org', 'pw')

describe('verifyCredentials', () => {
	it('returns the identity from the server and the active suite', async () => {
		server({ identity, suites: json(200, [{ ...suiteRow, id: 'old', status: 'revoked' }, suiteRow]) })
		expect(await verify()).toEqual({
			uid: 'alice',
			loginName: 'alice@example.org',
			displayName: 'Alice A',
			email: null,
			avatarDataUrl: null,
			suite: suiteRow,
		})
		// The avatar is fetched by uid, not by the typed login name.
		expect(fetchMock.mock.calls.map(([url]) => String(url))).toContain('https://cloud.example.org/index.php/avatar/alice/64')
	})

	it.each([
		['unreachable', { identity: new TypeError('fetch failed') }],
		['not_nextcloud', { identity: new Response('<html/>', { status: 200 }) }],
		['unauthorized', { identity: json(401, { message: 'no' }) }],
		['keepiq_missing', { identity, suites: new Response('<html/>', { status: 404 }) }],
		['no_active_suite', { identity, suites: json(200, []) }],
		['unlock_blocked', { identity, suites: json(200, [{ ...suiteRow, privateKey: undefined, unlockBlocked: '2fa' }]) }],
	] as const)('fails with %s', async (code, routes) => {
		server(routes)
		await expect(verify()).rejects.toMatchObject({ code })
	})
})
