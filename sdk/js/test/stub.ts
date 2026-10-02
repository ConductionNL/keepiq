// A small in-memory Keepiq machine API for the tests. TEST ONLY. Same
// behaviour as sdk/testdata/stub_server.py: discovery, the token exchange
// (RS256 signature checked against the test key), and the secrets routes with
// ETags, 304, lease headers and 409 candidates. Request bodies are recorded.
import { createPublicKey, createVerify, createHash } from 'node:crypto'
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'

export const WEBROOT = '/index.php'
const API = WEBROOT + '/apps/keepiq/api/v1/app/secrets'
const TOKEN = WEBROOT + '/apps/keepiq/api/v1/app/token'
const DISCOVERY = WEBROOT + '/apps/keepiq/api/v1/app/.well-known/keepiq'

type Env = Record<string, any>

export class Stub {
	envelopes = new Map<string, Env>()
	exchanges = 0
	bodies: string[] = []
	revokeNext = false
	private server?: Server
	private readonly fingerprint: string

	constructor(private readonly fixture: Env, private readonly app = 'billing') {
		const env = structuredClone(fixture.envelope)
		this.fingerprint = env.encryption.certificateFingerprint
		this.envelopes.set(env.secret.id, env)
	}

	newEnvelope(id: string, f: Record<string, string | undefined>): Env {
		return {
			format: 'doriath-machine-secret-v1',
			secret: { id, name: f.name, url: f.url ?? null, folderPath: '', type: f.typeId ?? null, createdAt: '2026-10-02T10:00:00+00:00', updatedAt: '2026-10-02T10:00:00+00:00', keyUpdatedAt: '2026-10-02T10:00:00+00:00' },
			encryption: { suiteId: 'suite-cli-fixture', certificateFingerprint: this.fingerprint, scheme: 'rsa-oaep-sha256-chunked-v1' },
			ciphertext: { key: f.key ?? null, login: f.login ?? null, additionalFields: f.additionalFields ?? null },
		}
	}

	private validAssertion(a: string): boolean {
		const parts = a.split('.')
		if (parts.length !== 3) return false
		const ok = createVerify('RSA-SHA256').update(parts[0] + '.' + parts[1]).verify(createPublicKey(this.fixture.privateKeyPem), Buffer.from(parts[2], 'base64url'))
		if (!ok) return false
		const c = JSON.parse(Buffer.from(parts[1], 'base64url').toString())
		return c.iss === this.app && c.sub === this.app && c.aud === 'keepiq' && !!c.jti
	}

	async start(): Promise<string> {
		this.server = createServer((req, res) => {
			let body = ''
			req.on('data', (d) => { body += d })
			req.on('end', () => {
				if (body) this.bodies.push(body)
				const url = new URL(req.url ?? '/', 'http://x')
				const path = url.pathname
				const send = (code: number, payload?: unknown, headers: Record<string, string> = {}) => {
					res.writeHead(code, { ...headers, ...(payload !== undefined ? { 'Content-Type': 'application/json' } : {}) })
					res.end(payload !== undefined ? JSON.stringify(payload) : undefined)
				}
				const envelope = (env: Env) => {
					const etag = '"' + createHash('sha256').update(JSON.stringify(env)).digest('hex').slice(0, 16) + '"'
					const h = { ETag: etag, 'Doriath-Lease-Id': 'lease-7', 'Doriath-Lease-Expires': '2026-10-02T13:00:00+00:00' }
					return req.headers['if-none-match'] === etag ? send(304, undefined, h) : send(200, env, h)
				}
				if (path === DISCOVERY) {
					return send(200, {
						apiVersion: 1, tokenEndpoint: TOKEN, grantType: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						assertion: { alg: 'RS256', audience: 'keepiq' },
						secrets: { list: API, byId: API + '/{id}', byName: API + '/by-name/{name}', create: API, update: API + '/{id}' },
					})
				}
				if (path === TOKEN && req.method === 'POST') {
					const form = new URLSearchParams(body)
					if (form.get('grant_type') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer' || !this.validAssertion(form.get('assertion') ?? '')) {
						return send(401, { error: 'invalid_grant' })
					}
					this.exchanges++
					return send(200, { access_token: `tok-${this.exchanges}`, token_type: 'Bearer', expires_in: 300 })
				}
				if (this.exchanges === 0 || req.headers.authorization !== `Bearer tok-${this.exchanges}` || this.revokeNext) {
					this.revokeNext = false
					return send(401, { message: 'Bearer token required' })
				}
				if (path === API && req.method === 'GET') {
					const since = url.searchParams.get('updated_since')
					const items = [...this.envelopes.values()].filter((e) => !since || e.secret.updatedAt > since)
					return send(200, { format: 'doriath-machine-secret-v1', items, total: items.length })
				}
				if (path.startsWith(API + '/by-name/')) {
					const name = decodeURIComponent(path.slice((API + '/by-name/').length))
					const hits = [...this.envelopes.values()].filter((e) => e.secret.name === name)
					if (hits.length === 0) return send(404, { message: 'Secret not found' })
					if (hits.length > 1) {
						return send(409, { message: 'Multiple secrets match this name', candidates: hits.map((e) => ({ id: e.secret.id, name: e.secret.name, folderPath: e.secret.folderPath, updatedAt: e.secret.updatedAt })) })
					}
					return envelope(hits[0])
				}
				if (path === API && req.method === 'POST') {
					const env = this.newEnvelope(`sec-new-${this.envelopes.size}`, JSON.parse(body || '{}'))
					this.envelopes.set(env.secret.id, env)
					return send(201, env)
				}
				if (path.startsWith(API + '/')) {
					const env = this.envelopes.get(decodeURIComponent(path.slice(API.length + 1)))
					if (!env) return send(404, { message: 'Secret not found' })
					if (req.method === 'PUT') {
						const f = JSON.parse(body || '{}')
						for (const k of ['key', 'login', 'additionalFields']) if (k in f) env.ciphertext[k] = f[k]
						env.secret.updatedAt = '2026-10-02T12:00:00+00:00'
					}
					return envelope(env)
				}
				return send(404, { message: 'no route ' + path })
			})
		})
		await new Promise<void>((r) => this.server!.listen(0, '127.0.0.1', r))
		return `http://127.0.0.1:${(this.server!.address() as AddressInfo).port}${WEBROOT}`
	}

	stop(): Promise<void> {
		return new Promise((r) => this.server ? this.server.close(() => r()) : r())
	}
}

