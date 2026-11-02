/**
 * The account's Keepiq at `keepiq.invalid`, faked with `context.route`, which also catches the extension worker's
 * requests: the routes an unlock, a passkey save and the sync after it use (ADR-003), over the rows the seed cached,
 * on the oldest supported Keepiq. Every other request fails as offline, as without it.
 */
import { expect, type BrowserContext, type Route } from '@playwright/test'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import type { OpenSecretRow } from '@/src/api/types'
import { importPrivateKey, pemToPkcs8, rsaDecrypt } from '@/src/crypto'
import { typeRows } from '@/src/testing/vault'
import { envelope, suiteRow } from '@/src/testing/vectors'
import type { VaultSnapshot } from '@/src/vault/types'
import { extensionWorker } from './fixtures'

export interface KeepiqRequest {
	method: string
	/** After `/index.php/apps/keepiq`. */
	path: string
	body: Record<string, unknown> | null
}

export interface FakeKeepiq {
	/** The server's rows, changed by every create and share-save it accepted. */
	rows: OpenSecretRow[]
	requests: KeepiqRequest[]
}

const ORIGIN = 'https://keepiq.invalid'
const APP = '/index.php/apps/keepiq'

/** The vault the extension holds now, from its storage. */
export async function cachedVault(context: BrowserContext): Promise<VaultSnapshot | undefined> {
	return (await extensionWorker(context)).evaluate(async () => {
		const { storage } = (globalThis as unknown as { chrome: { storage: { local: { get(): Promise<Record<string, unknown>> } } } }).chrome
		const local = await storage.local.get()
		return local[`vaultCache.${local.activeAccountId}`] as VaultSnapshot | undefined
	})
}

/** Every row the seed cached, as the server holds it. */
export async function fakeKeepiq(context: BrowserContext): Promise<FakeKeepiq> {
	// The worker's start can drop `activeAccountId` as the fixture writes it, until `getActiveAccountId()` heals it.
	await expect.poll(() => cachedVault(context)).toBeDefined()
	const vault = (await cachedVault(context))!
	const server: FakeKeepiq = { rows: structuredClone(vault.secrets) as OpenSecretRow[], requests: [] }
	await context.route(`${ORIGIN}/**`, (route) => answer(server, vault, route))
	return server
}

/** Later than `before`, so the sync's probe and share-save's `baseUpdatedAt` see a change. */
const after = (before?: string) => new Date(Math.max(Date.now(), before ? Date.parse(before) + 1000 : 0)).toISOString()

async function answer(server: FakeKeepiq, vault: VaultSnapshot, route: Route): Promise<void> {
	const request = route.request()
	const url = new URL(request.url())
	const method = request.method()
	const path = url.pathname.startsWith(APP) ? url.pathname.slice(APP.length) : url.pathname
	const body = (request.postData() ? request.postDataJSON() : null) as Record<string, unknown> | null
	server.requests.push({ method, path: `${path}${url.search}`, body })
	const json = (status: number, value: unknown) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(value) })

	if (method === 'GET' && path === '/api/health') return json(200, { version: MIN_KEEPIQ_VERSION })
	if (method === 'GET' && path === '/api/v1/suites') return json(200, [suiteRow])
	if (method === 'GET' && path === '/api/v1/offline/manifest') {
		return json(200, { suite: suiteRow, secrets: server.rows, folders: vault.folders, types: typeRows })
	}
	if (method === 'POST' && path === '/api/v1/secrets') {
		const now = after()
		const row: OpenSecretRow = {
			id: `new-${server.rows.length + 1}`, name: String(body!.name), url: (body!.url as string | null) ?? null, typeId: String(body!.typeId),
			folderId: (body!.folderId as string | null) ?? null, key: String(body!.key), login: (body!.login as string | undefined) ?? null,
			additionalFields: (body!.additionalFields as string | undefined) ?? null, passkeys: (body!.passkeys as string | undefined) ?? null,
			blocked: false, encryptionSuiteId: suiteRow.id, ownerType: 'user', ownerId: 'tester', createdAt: now, updatedAt: now, keyUpdatedAt: null,
			expiresAt: null, possiblyCompromisedAt: null, tombstonedAt: null, tombstoneReason: null, useOnly: false,
		}
		server.rows.push(row)
		return json(201, row)
	}
	const secret = /^\/api\/v1\/secrets\/([^/]+)(\/write-context|\/share-save)?$/.exec(path)
	const row = secret && server.rows.find((candidate) => candidate.id === decodeURIComponent(secret[1]!))
	if (secret && !row) return json(404, { message: 'Not found' })
	if (row && method === 'GET' && !secret![2]) return json(200, row)
	if (row && method === 'GET' && secret![2] === '/write-context') {
		return json(200, { sourceSecretId: row.id, effectiveGrade: 'owner', ownerCertificate: null, sourceUpdatedAt: row.updatedAt })
	}
	if (row && method === 'PUT' && secret![2] === '/share-save') {
		if (body!.baseUpdatedAt !== row.updatedAt) return json(409, { message: 'Stale', code: 'stale' })
		Object.assign(row, body!.patch, { updatedAt: after(row.updatedAt) })
		return json(200, {})
	}
	return route.abort('internetdisconnected')
}

let vaultKey: Promise<CryptoKey> | undefined

/** What the seed's account key opens, as the server never could. */
export function decrypt(ciphertext: unknown): Promise<string> {
	vaultKey ??= importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
	return vaultKey.then((key) => rsaDecrypt(String(ciphertext), key))
}
