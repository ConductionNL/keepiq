/**
 * The offline edit queue at rest and its coalescing (keepiq#785).
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
 */
import { describe, expect, it } from 'vitest'
import {
	importPrivateKey,
	importPublicKey,
	rsaEncrypt,
} from '../../src/crypto/rsa.js'
import {
	applyQueue,
	classifyReplayError,
	coalesce,
	openEntry,
	PLAIN_FIELDS,
	replayOrder,
	sealEntry,
} from '../../src/offline/queue.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
} from './fixtures/rsa-fixtures.js'

const CERT = RSA4096_PUBLIC_KEY_SPKI_PEM

async function body() {
	const pub = await importPublicKey(CERT)
	return {
		name: 'Pump station router',
		url: 'https://router.pump.example',
		key: await rsaEncrypt('new-router-password', pub),
	}
}

describe('a sealed entry', () => {
	it('stores no plain value, name or URL, and opens with the private key', async () => {
		const b = await body()
		const stored = await sealEntry(
			{
				op: 'update',
				secretId: 's1',
				baseUpdatedAt: '2026-10-01T10:00:00+00:00',
				suiteId: 'suite-1',
				body: b,
			},
			CERT,
		)

		expect(Object.keys(stored).sort()).toEqual([...PLAIN_FIELDS].sort())
		const atRest = JSON.stringify(stored)
		expect(atRest).not.toContain('Pump station router')
		expect(atRest).not.toContain('router.pump.example')
		expect(atRest).not.toContain('new-router-password')
		expect(atRest).not.toContain(b.key)

		const opened = await openEntry(
			stored,
			await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM),
		)
		expect(opened.body).toEqual(b)
		expect(opened.secretId).toBe('s1')
		expect(opened.baseUpdatedAt).toBe('2026-10-01T10:00:00+00:00')
	})
})

describe('coalescing', () => {
	const upd = (body, base, at) => ({
		entryId: 'e-' + at,
		op: 'update',
		secretId: 's1',
		baseUpdatedAt: base,
		queuedAt: at,
		body,
	})

	it('keeps the earliest base and merges the values of an update chain', () => {
		const first = upd({ name: 'A' }, 'base-1', '1')
		const second = upd({ key: 'k2' }, 'base-2', '2')
		const third = upd({ name: 'C' }, 'base-3', '3')
		const result = coalesce(coalesce(first, second), third)
		expect(result.entryId).toBe('e-1')
		expect(result.baseUpdatedAt).toBe('base-1')
		expect(result.body).toEqual({ name: 'C', key: 'k2' })
	})

	it('keeps a create followed by updates as one create', () => {
		const create = {
			entryId: 'c',
			op: 'create',
			secretId: 'local-1',
			body: { name: 'New', key: 'k1' },
		}
		const result = coalesce(create, {
			op: 'update',
			secretId: 'local-1',
			body: { key: 'k2' },
		})
		expect(result.op).toBe('create')
		expect(result.body).toEqual({ name: 'New', key: 'k2' })
	})

	it('drops a create followed by a delete', () => {
		const create = {
			entryId: 'c',
			op: 'create',
			secretId: 'local-1',
			body: { name: 'New' },
		}
		expect(
			coalesce(create, { op: 'delete', secretId: 'local-1', body: {} }),
		).toBeNull()
	})

	it('turns an update followed by a delete into a delete on the first base', () => {
		const result = coalesce(upd({ name: 'A' }, 'base-1', '1'), {
			op: 'delete',
			secretId: 's1',
			baseUpdatedAt: 'base-2',
			body: {},
		})
		expect(result.op).toBe('delete')
		expect(result.baseUpdatedAt).toBe('base-1')
		expect(result.entryId).toBe('e-1')
	})
})

describe('the offline view and replay helpers', () => {
	it('overlays the queue on the cached secrets, marked not synced', () => {
		const cached = [
			{ id: 's1', name: 'Old' },
			{ id: 's2', name: 'Gone' },
		]
		const list = applyQueue(cached, [
			{ op: 'update', secretId: 's1', body: { name: 'New' } },
			{ op: 'delete', secretId: 's2', body: {} },
			{ op: 'create', secretId: 'local-1', body: { name: 'Fresh' } },
		])
		expect(list).toEqual([
			{ id: 's1', name: 'New', pendingSync: true },
			{ id: 'local-1', name: 'Fresh', pendingSync: true, localOnly: true },
		])
	})

	it('replays oldest first and classifies the answers', () => {
		expect(
			replayOrder([{ queuedAt: '2' }, { queuedAt: '1' }]).map(
				(e) => e.queuedAt,
			),
		).toEqual(['1', '2'])
		expect(classifyReplayError({ response: { status: 409 } })).toBe('conflict')
		expect(classifyReplayError({ response: { status: 403 } })).toBe('failed')
		expect(classifyReplayError({ response: { status: 404 } })).toBe('failed')
		expect(classifyReplayError({ response: { status: 423 } })).toBe('retry')
		expect(classifyReplayError(new Error('Network Error'))).toBe('retry')
	})
})
