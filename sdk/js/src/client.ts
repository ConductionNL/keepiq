/**
 * Keepiq machine API client. Every value is decrypted and encrypted in this
 * process: only ciphertext and a signed RFC 7523 assertion cross the network,
 * and the private key never does.
 */

import {
	SCHEME,
	base64Url,
	certificateFingerprint,
	certificateMatches,
	decryptField,
	encryptField,
	importKeySet,
	signRS256,
	type KeySet,
} from './crypto.js'
import {
	AmbiguousNameError,
	ApiError,
	KeyMismatchError,
	NotFoundError,
	NotModifiedError,
	UnauthorizedError,
	type Candidate,
} from './errors.js'

export const DISCOVERY_PATH = '/apps/keepiq/api/v1/app/.well-known/keepiq'
const SECRETS = '/apps/keepiq/api/v1/app/secrets'
const SECRET_FIELDS = ['key', 'login', 'additionalFields'] as const
const METADATA_FIELDS = ['name', 'url', 'typeId'] as const

export interface Lease {
	id: string
	expires: string
}

/** One decrypted secret with its metadata. */
export interface Secret {
	id: string
	name: string
	url?: string | null
	folderPath: string
	type?: string | null
	createdAt?: string | null
	updatedAt?: string | null
	keyUpdatedAt?: string | null
	key: string
	login: string
	additionalFields: string
	etag?: string
	lease?: Lease
}

/** `name`, `url`, `typeId` are sent as they are; `key`, `login`, `additionalFields` are encrypted first. */
export type WriteFields = Partial<Record<(typeof SECRET_FIELDS)[number] | (typeof METADATA_FIELDS)[number], string>>

export interface ClientOptions {
	/** The application's certificate: must belong to the key; envelopes for another certificate are refused. */
	certificatePem?: string
	/** Replace fetch, for tests or a custom agent. */
	fetch?: typeof fetch
	/** Replace Date.now (milliseconds), for tests. */
	now?: () => number
}

interface Discovery {
	tokenEndpoint: string
	grantType?: string
	assertion?: { audience?: string }
	secrets?: Partial<Record<'list' | 'byId' | 'byName' | 'create' | 'update', string>>
}

interface Envelope {
	secret?: Record<string, string | null>
	encryption?: { scheme?: string, certificateFingerprint?: string | null }
	ciphertext?: Record<string, string | null>
}

export class Client {
	private readonly base: string
	private readonly origin: string
	private readonly fetchFn: typeof fetch
	private readonly now: () => number
	private keys?: Promise<KeySet>
	private fingerprint?: Promise<string | undefined>
	private discovery?: Promise<Discovery>
	private token?: string
	private tokenExpiry = 0
	private readonly etags = new Map<string, string>()

	/**
	 * @param url The Nextcloud address (include `/index.php` without pretty URLs)
	 * @param applicationId The approved application's id
	 * @param privateKeyPem The application's RSA private key (PKCS#8 or PKCS#1)
	 */
	constructor(url: string, private readonly applicationId: string, private readonly privateKeyPem: string, private readonly options: ClientOptions = {}) {
		const parsed = new URL(url)
		if (!applicationId) throw new Error('application id is required')
		this.base = stripTrailingSlashes(url)
		this.origin = parsed.origin
		this.fetchFn = options.fetch ?? globalThis.fetch.bind(globalThis)
		this.now = options.now ?? Date.now
	}

	async getByName(name: string, folder?: string): Promise<Secret> {
		let addr = await this.endpoint('byName', `${SECRETS}/by-name/{name}`, '{name}', name)
		if (folder) addr += '?folder=' + encodeURIComponent(folder)
		return this.read(addr, name)
	}

	async getById(id: string): Promise<Secret> {
		return this.read(await this.endpoint('byId', `${SECRETS}/{id}`, '{id}', id), id)
	}

	/** Every secret, or only those updated strictly after `updatedSince`. */
	async list(updatedSince?: Date): Promise<Secret[]> {
		let addr = await this.endpoint('list', SECRETS)
		if (updatedSince) addr += '?updated_since=' + encodeURIComponent(updatedSince.toISOString().replace(/\.\d{3}Z$/, 'Z'))
		const res = await this.request('GET', addr)
		if (res.status !== 200) throw await this.statusError(res, '')
		const page = await res.json() as { items?: Envelope[] }
		return Promise.all((page.items ?? []).map((env) => this.open(env)))
	}

	/** File a new secret. `name` and `key` are required. */
	async create(fields: WriteFields): Promise<Secret> {
		const res = await this.request('POST', await this.endpoint('create', SECRETS), await this.payload(fields))
		if (res.status !== 201) throw await this.statusError(res, '')
		return this.decode(res)
	}

	/** Replace the given fields of one secret; others stay as they are. */
	async update(id: string, fields: WriteFields): Promise<Secret> {
		const res = await this.request('PUT', await this.endpoint('update', `${SECRETS}/{id}`, '{id}', id), await this.payload(fields))
		if (res.status !== 200) throw await this.statusError(res, '')
		return this.decode(res)
	}

	// --- internals ---

	private keySet(): Promise<KeySet> {
		if (!this.keys) this.keys = importKeySet(this.privateKeyPem)
		return this.keys
	}

	private certFingerprint(): Promise<string | undefined> {
		if (!this.fingerprint) {
			const cert = this.options.certificatePem
			this.fingerprint = cert
				? this.keySet().then(async (keys) => {
					if (!(await certificateMatches(cert, keys))) {
						throw new KeyMismatchError('the certificate does not belong to the private key')
					}
					return certificateFingerprint(cert)
				})
				: Promise.resolve(undefined)
		}
		return this.fingerprint
	}

	private async payload(fields: WriteFields): Promise<Record<string, string>> {
		const keys = await this.keySet()
		const out: Record<string, string> = {}
		for (const [name, value] of Object.entries(fields)) {
			if (value === undefined) continue
			if ((SECRET_FIELDS as readonly string[]).includes(name)) {
				out[name] = await encryptField(value, keys.encrypt)
			} else if ((METADATA_FIELDS as readonly string[]).includes(name)) {
				out[name] = value
			} else {
				throw new Error(`unknown field "${name}" (allowed: ${[...METADATA_FIELDS, ...SECRET_FIELDS].join(', ')})`)
			}
		}
		return out
	}

	private async read(addr: string, label: string): Promise<Secret> {
		const res = await this.request('GET', addr, undefined, this.etags.get(addr))
		if (res.status === 304) throw new NotModifiedError(label)
		if (res.status !== 200) throw await this.statusError(res, label)
		const secret = await this.decode(res)
		if (secret.etag) this.etags.set(addr, secret.etag)
		return secret
	}

	private async decode(res: Response): Promise<Secret> {
		const secret = await this.open(await res.json() as Envelope)
		const etag = res.headers.get('ETag')
		if (etag) secret.etag = etag
		const leaseId = res.headers.get('Doriath-Lease-Id')
		if (leaseId) secret.lease = { id: leaseId, expires: res.headers.get('Doriath-Lease-Expires') ?? '' }
		return secret
	}

	private async open(env: Envelope): Promise<Secret> {
		if (env.encryption?.scheme !== SCHEME) {
			throw new ApiError(0, `unsupported encryption scheme "${env.encryption?.scheme}"`)
		}
		const mine = await this.certFingerprint()
		const theirs = env.encryption.certificateFingerprint
		if (mine && theirs && mine.toLowerCase() !== theirs.toLowerCase()) {
			throw new KeyMismatchError('the envelope is encrypted to a different certificate')
		}
		const keys = await this.keySet()
		const ct = env.ciphertext ?? {}
		const value = async (name: string) => (ct[name] ? decryptField(ct[name] as string, keys.decrypt) : '')
		const meta = env.secret ?? {}
		return {
			id: meta.id ?? '',
			name: meta.name ?? '',
			url: meta.url,
			folderPath: meta.folderPath ?? '',
			type: meta.type,
			createdAt: meta.createdAt,
			updatedAt: meta.updatedAt,
			keyUpdatedAt: meta.keyUpdatedAt,
			key: await value('key'),
			login: await value('login'),
			additionalFields: await value('additionalFields'),
		}
	}

	private async statusError(res: Response, label: string): Promise<Error> {
		const text = await res.text()
		let data: { message?: string, candidates?: Candidate[] } = {}
		try {
			data = JSON.parse(text)
		} catch {
			// not JSON
		}
		if (res.status === 404) return new NotFoundError(label)
		if (res.status === 401 || res.status === 403) return new UnauthorizedError(data.message ?? 'unauthorized')
		if (res.status === 409 && Array.isArray(data.candidates)) {
			return new AmbiguousNameError(label, data.candidates.map((c) => ({ id: c.id, name: c.name, folderPath: c.folderPath ?? '', updatedAt: c.updatedAt })))
		}
		return new ApiError(res.status, data.message ?? text.trim())
	}

	private async endpoint(kind: keyof NonNullable<Discovery['secrets']>, fallback: string, placeholder?: string, value?: string): Promise<string> {
		const advertised = (await this.discover()).secrets?.[kind]
		let path = advertised || fallback
		if (placeholder) path = path.replace(placeholder, encodeURIComponent(value ?? ''))
		return advertised ? this.resolve(path) : this.base + path
	}

	/** Discovery paths are absolute and already carry the web root. */
	private resolve(path: string): string {
		return /^https?:\/\//.test(path) ? path : this.origin + path
	}

	private discover(): Promise<Discovery> {
		if (!this.discovery) {
			this.discovery = (async () => {
				const res = await this.fetchFn(this.base + DISCOVERY_PATH, { headers: { Accept: 'application/json' } })
				if (res.status !== 200) throw new ApiError(res.status, 'discovery: ' + (await res.text()).trim())
				const doc = await res.json() as Discovery
				if (!doc.tokenEndpoint) throw new ApiError(res.status, 'discovery has no tokenEndpoint')
				return doc
			})()
			this.discovery.catch(() => { this.discovery = undefined })
		}
		return this.discovery
	}

	private async bearer(): Promise<string> {
		const doc = await this.discover()
		const now = this.now()
		if (this.token && now < this.tokenExpiry) return this.token
		const body = new URLSearchParams({
			grant_type: doc.grantType || 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			assertion: await this.assertion(doc, Math.floor(now / 1000)),
		})
		const res = await this.fetchFn(this.resolve(doc.tokenEndpoint), {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		})
		const text = await res.text()
		if ([400, 401, 403].includes(res.status)) throw new UnauthorizedError(`token exchange answered ${res.status}: ${text.trim()}`)
		if (res.status !== 200) throw new ApiError(res.status, 'token exchange: ' + text.trim())
		const tok = JSON.parse(text) as { access_token?: string, expires_in?: number }
		if (!tok.access_token) throw new ApiError(res.status, 'token exchange returned no access_token')
		const life = (tok.expires_in || 60) * 1000
		const margin = life > 60000 ? 30000 : life / 2
		this.token = tok.access_token
		this.tokenExpiry = now + life - margin
		return this.token
	}

	private async assertion(doc: Discovery, now: number): Promise<string> {
		const enc = (v: unknown) => base64Url(new TextEncoder().encode(JSON.stringify(v)))
		const jti = Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, '0')).join('')
		const app = this.applicationId
		const input = enc({ alg: 'RS256', typ: 'JWT' }) + '.' + enc({
			iss: app, sub: app, aud: doc.assertion?.audience || 'keepiq', iat: now, exp: now + 300, jti,
		})
		return input + '.' + (await signRS256(input, (await this.keySet()).sign))
	}

	/** One authenticated request; a 401 drops the token and retries once. */
	private async request(method: string, addr: string, payload?: Record<string, string>, etag?: string): Promise<Response> {
		for (let attempt = 0; ; attempt++) {
			const headers: Record<string, string> = { Authorization: 'Bearer ' + (await this.bearer()), Accept: 'application/json' }
			if (payload) headers['Content-Type'] = 'application/json'
			if (etag) headers['If-None-Match'] = etag
			const res = await this.fetchFn(addr, { method, headers, body: payload ? JSON.stringify(payload) : undefined })
			if (res.status === 401 && attempt === 0) {
				this.token = undefined
				continue
			}
			return res
		}
	}
}

/**
 * The address without its trailing slashes.
 *
 * A loop rather than `/\/+$/`: that pattern backtracks quadratically on a long
 * run of slashes that is not at the end, and the address is caller input.
 *
 * @param url The address
 */
function stripTrailingSlashes(url: string): string {
	let end = url.length
	while (end > 0 && url[end - 1] === '/') end--
	return url.slice(0, end)
}
