import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, createClient } from '@/src/api/client'
import { WriteFailure } from '@/src/vault/write-error'
import { deleteSend, listSends } from './api'

const fetchMock = vi.fn<typeof fetch>()
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status })
const client = createClient({ id: 'a1', serverUrl: 'https://cloud.example.org', uid: 'alice', loginName: 'alice', appPassword: 'pw' })

const row = {
	id: 'b9c1', token: 'f'.repeat(64), payloadType: 'text', hasPassword: false, maxViews: 3, viewCount: 1, remainingViews: 2,
	expiresAt: '2026-10-29T10:00:00+00:00', createdAt: '2026-10-22T10:00:00+00:00',
}

beforeEach(() => {
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

describe('listSends', () => {
	it('keeps only the fields a row may show and leaves out a malformed row', async () => {
		fetchMock.mockResolvedValue(json([{ ...row, encryptedPayload: 'never' }, { ...row, id: 'b2', expiresAt: null }, { ...row, id: 'b3', payloadType: 'file' }, null]))
		expect(await listSends(client)).toEqual([row, { ...row, id: 'b2', expiresAt: null }])
	})

	it.each<[string, Record<string, unknown> | string]>([
		['an id other than text', { id: 7 }], ['no token', { token: '' }], ['a token other than text', { token: 7 }],
		['a credential type', { payloadType: 'credential' }], ['an unknown type', { payloadType: 'file' }], ['a password flag other than a boolean', { hasPassword: 'no' }],
		['a negative view limit', { maxViews: -1 }], ['a fraction of a view', { viewCount: 1.5 }], ['remaining views other than a count', { remainingViews: '2' }],
		['an expiry other than text or null', { expiresAt: 0 }], ['no creation time', { createdAt: null }], ['a row other than an object', 'b9c1'],
	])('reads a row with %s as it reads it', async (_name, change) => {
		const value = typeof change === 'string' ? change : { ...row, ...change }
		fetchMock.mockResolvedValue(json([value]))
		const kept = typeof change === 'object' && change.payloadType === 'credential'
		expect(await listSends(client)).toEqual(kept ? [{ ...row, payloadType: 'credential' }] : [])
	})

	it('refuses an answer that is not a list', async () => {
		fetchMock.mockResolvedValue(json({ sends: [] }))
		await expect(listSends(client)).rejects.toBeInstanceOf(WriteFailure)
	})
})

describe('deleteSend', () => {
	it('encodes the id in the path', async () => {
		fetchMock.mockResolvedValue(json({ revoked: true }))
		await deleteSend(client, 'a/b')
		expect(String(fetchMock.mock.calls[0]![0])).toBe('https://cloud.example.org/index.php/apps/keepiq/api/v1/sends/a%2Fb')
		expect(fetchMock.mock.calls[0]![1]!.method).toBe('DELETE')
	})

	it('takes the server\'s 404 as already gone', async () => {
		fetchMock.mockResolvedValue(json({ message: 'Send not found' }, 404))
		await expect(deleteSend(client, 'b9c1')).resolves.toBeUndefined()
	})

	it('does not take Nextcloud\'s HTML 404 for a missing Keepiq as gone', async () => {
		fetchMock.mockResolvedValue(new Response('<html></html>', { status: 404 }))
		await expect(deleteSend(client, 'b9c1')).rejects.toBeInstanceOf(ApiError)
	})
})
