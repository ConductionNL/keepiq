#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
/**
 * The test server side of the mobile end-to-end tests
 * (.github/workflows/mobile-e2e.yml). Node only, no packages.
 *
 *   proxy   An https front for the test Nextcloud of
 *           browser-extension/capture/compose.yaml, so the apps pair with an
 *           https address as a real user would. Every request goes to the
 *           Nextcloud with its Host kept. Under /__e2e/ it also stands in for
 *           what a phone test cannot do itself:
 *             POST /__e2e/grant  {loginUrl, user}  signs in on that Login
 *                  Flow v2 page and grants access, as the user would in the
 *                  browser;
 *             GET  /__e2e/tokens  the user's app password names (the
 *                  Nextcloud device list), through occ.
 *   seed    Fills admin's vault with the demo items the vault tests use:
 *           logins on example.com, example.net, example.org and .example
 *           names, an authenticator (TOTP) item and a note, all clearly
 *           fake. Every value is encrypted to admin's suite as the apps do
 *           it. Run it again and it adds only what is missing.
 *   record  Talks to the seeded test Nextcloud and writes the answers the
 *           iOS tests replay to mobile/e2e/fixtures/server.json.
 *   replay  An https stub that answers from those recordings, for the iOS
 *           simulator job, where no Docker runs. It keeps the vault and the
 *           Sends in memory, so an item the test creates, edits or trashes
 *           behaves as on the real server.
 *
 *   node mobile/e2e/server.mjs proxy  --port 8443 --cert c.pem --key k.pem --upstream http://localhost:8188 --container kq-e2e-nc-1
 *   node mobile/e2e/server.mjs seed   --upstream http://localhost:8188
 *   node mobile/e2e/server.mjs record --upstream http://localhost:8188 --container kq-e2e-nc-1
 *   node mobile/e2e/server.mjs replay --port 8443 --cert c.pem --key k.pem
 *
 * The demo account is admin with the password admin and the master password
 * Oj, the development vault of browser-extension/capture/setup.sh.
 */
import { execFileSync } from 'node:child_process'
import { constants as cryptoConstants, publicEncrypt, randomUUID } from 'node:crypto'
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs'
import { request as httpRequest } from 'node:http'
import { createServer as createHttpsServer } from 'node:https'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const FIXTURES = join(HERE, 'fixtures', 'server.json')
const USER_AGENT = 'Keepiq for iOS (recorded)'

function options(argv) {
	const out = { _: argv[0] }
	for (let i = 1; i < argv.length; i += 2) out[argv[i].replace(/^--/, '')] = argv[i + 1]
	return out
}

/** One request to the Nextcloud, with the Host the caller chose. */
function nc(upstream, method, path, { host, jar, form, headers = {}, body } = {}) {
	const target = new URL(upstream)
	const payload = form ? new URLSearchParams(form).toString() : body
	const all = { host: host || target.host, ...headers }
	if (form) all['content-type'] = 'application/x-www-form-urlencoded'
	if (payload !== undefined) all['content-length'] = Buffer.byteLength(payload)
	if (jar && jar.size) all.cookie = [...jar].map(([k, v]) => `${k}=${v}`).join('; ')
	return new Promise((resolve, reject) => {
		const req = httpRequest({ hostname: target.hostname, port: target.port, method, path, headers: all }, (res) => {
			const chunks = []
			res.on('data', (c) => chunks.push(c))
			res.on('end', () => {
				for (const line of res.headers['set-cookie'] || []) {
					const [pair] = line.split(';')
					const at = pair.indexOf('=')
					const name = pair.slice(0, at).trim()
					const value = pair.slice(at + 1).trim()
					if (!jar) continue
					if (value === 'deleted' || /max-age=0|expires=thu, 01 jan 1970/i.test(line)) jar.delete(name)
					else jar.set(name, value)
				}
				resolve({ status: res.statusCode, headers: res.headers, text: Buffer.concat(chunks).toString('utf8') })
			})
		})
		req.on('error', reject)
		if (payload !== undefined) req.write(payload)
		req.end()
	})
}

function pathOf(url) {
	const u = new URL(url)
	return u.pathname + u.search
}

function initialState(html, app, key) {
	const m = html.match(new RegExp(`id="initial-state-${app}-${key}"\\s+value="([^"]+)"`))
	if (!m) throw new Error(`no initial state ${app}-${key} on the page`)
	return JSON.parse(Buffer.from(m[1], 'base64').toString('utf8'))
}

function requestToken(html) {
	const m = html.match(/data-requesttoken="([^"]+)"/)
	if (!m) throw new Error('no request token on the page')
	return m[1]
}

/**
 * What the user does in the browser after the app opened the login page:
 * open it, log in, and press "Grant access". Every step is the Nextcloud
 * page's own form (core/Controller/ClientFlowLoginV2Controller).
 */
export async function grant(upstream, loginUrl, user, password) {
	const host = new URL(loginUrl).host
	const jar = new Map()
	const step = async (method, path, opts = {}) => {
		// A browser's Accept, so Nextcloud answers pages and login redirects.
		// and the Origin a browser sends with a form, which the login form checks.
		const headers = { accept: 'text/html,application/xhtml+xml', 'user-agent': 'Mozilla/5.0 (e2e browser stand-in)' }
		if (method === 'POST') headers.origin = new URL(loginUrl).origin
		const res = await nc(upstream, method, path, { host, jar, headers, ...opts })
		if (res.status >= 400) throw new Error(`${method} ${path} answered ${res.status}`)
		return res
	}
	let res = await step('GET', pathOf(loginUrl))
	while (res.status >= 300 && res.status < 400) res = await step('GET', pathOf(new URL(res.headers.location, loginUrl).href))
	const auth = initialState(res.text, 'core', 'loginFlowAuth')

	// The grant page sends a visitor without a session to the login form.
	res = await step('GET', pathOf(auth.loginRedirectUrl))
	if (res.status >= 300 && res.status < 400) {
		const loginPage = new URL(res.headers.location, loginUrl)
		const form = await step('GET', pathOf(loginPage.href))
		res = await step('POST', '/index.php/login', {
			form: {
				user,
				password,
				requesttoken: requestToken(form.text),
				redirect_url: loginPage.searchParams.get('redirect_url') || '',
				timezone: 'UTC',
				timezone_offset: '0',
			},
		})
		if (!(res.status >= 300 && res.status < 400) || /\/login(\?|$)/.test(res.headers.location || '')) {
			throw new Error(`logging in failed (${res.status} ${res.headers.location || ''})`)
		}
		res = await step('GET', pathOf(new URL(res.headers.location, loginUrl).href))
	}
	const grantState = initialState(res.text, 'core', 'loginFlowGrant')
	res = await step('POST', pathOf(grantState.actionUrl), {
		form: { stateToken: grantState.stateToken, requesttoken: requestToken(res.text) },
	})
	return { client: grantState.client }
}

function occ(container, ...args) {
	return execFileSync('docker', ['exec', '-u', 'www-data', container, 'php', 'occ', ...args], { encoding: 'utf8' })
}

/** The names in the user's device list (app passwords and sessions). */
function tokenNames(container, user) {
	const out = occ(container, 'user:auth-tokens:list', user, '--output=json')
	return JSON.parse(out).map((t) => t.name)
}

function readJson(req) {
	return new Promise((resolve) => {
		const chunks = []
		req.on('data', (c) => chunks.push(c))
		req.on('end', () => {
			try {
				resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}'))
			} catch {
				resolve({})
			}
		})
	})
}

function send(res, status, body, type = 'application/json') {
	res.writeHead(status, { 'content-type': type })
	res.end(typeof body === 'string' ? body : JSON.stringify(body))
}

function proxy(o) {
	const upstream = o.upstream
	const server = createHttpsServer({ cert: readFileSync(o.cert), key: readFileSync(o.key) }, async (req, res) => {
		try {
			if (req.url === '/__e2e/grant' && req.method === 'POST') {
				const body = await readJson(req)
				const done = await grant(upstream, body.loginUrl, body.user || 'admin', o.password || 'admin')
				console.log(`[e2e] granted access to "${done.client}"`)
				return send(res, 200, { ok: true, client: done.client })
			}
			if (req.url === '/__e2e/tokens') {
				return send(res, 200, { names: tokenNames(o.container, o.user || 'admin') })
			}
		} catch (e) {
			console.error('[e2e]', e.message)
			return send(res, 500, { error: e.message })
		}
		// Forward, keeping the Host the phone used (a trusted domain).
		const target = new URL(upstream)
		const forward = httpRequest(
			{ hostname: target.hostname, port: target.port, method: req.method, path: req.url, headers: req.headers },
			(answer) => {
				res.writeHead(answer.statusCode || 502, answer.headers)
				answer.pipe(res)
			},
		)
		forward.on('error', () => send(res, 502, { error: 'upstream' }))
		req.pipe(forward)
	})
	server.listen(Number(o.port || 8443), '0.0.0.0', () => console.log(`[e2e] proxy on https://0.0.0.0:${o.port || 8443} -> ${upstream}`))
}

/** The demo vault (seed). Names say "(demo)"; every address is a reserved example name (RFC 2606). */
export const DEMO_FOLDERS = ['Personal', 'Work']
export const DEMO_ITEMS = [
	{ name: 'Webmail (demo)', type: 'login', url: 'https://webmail.example.com', login: 'anna.demo@example.com', key: 'Lantern-Orbit-42!', folder: null },
	{ name: 'Router admin (demo)', type: 'login', url: 'https://router.example', login: 'admin', key: 'Quiet-Meadow-19$', folder: null },
	{
		name: 'Authenticator (demo)',
		type: 'totp',
		url: 'https://webmail.example.com',
		login: '',
		key: 'otpauth://totp/Webmail%20demo:anna.demo%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Webmail%20demo',
		folder: null,
	},
	{ name: 'Wi-Fi at home (demo)', type: 'note', url: '', login: '', key: 'Network: demo-home\nThis is not a real network.', folder: null },
	{ name: 'Bank (demo)', type: 'login', url: 'https://bank.example.net', login: '40817265', key: 'Copper-Harbor-7#', folder: 'Personal' },
	{ name: 'Intranet (demo)', type: 'login', url: 'https://intranet.example.org', login: 'anna.demo', key: 'Silver-Comet-58%', folder: 'Work' },
	{ name: 'Project board (demo)', type: 'login', url: 'https://board.example.org', login: 'anna.demo@example.com', key: 'Amber-Valley-23&', folder: 'Work' },
]

/**
 * A field as the apps encrypt it (src/crypto/rsa.js rsaEncrypt, the shared
 * core's RsaFields): a 4-byte big-endian chunk count, then one 512-byte
 * RSA-OAEP-SHA256 block per 446 bytes of UTF-8, base64.
 */
export function encryptField(text, certificatePem) {
	const data = Buffer.from(text, 'utf8')
	const chunks = []
	for (let i = 0; i < Math.max(data.length, 1); i += 446) chunks.push(data.subarray(i, i + 446))
	const head = Buffer.alloc(4)
	head.writeUInt32BE(chunks.length)
	const blocks = chunks.map((c) => publicEncrypt({ key: certificatePem, padding: cryptoConstants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha256' }, c))
	return Buffer.concat([head, ...blocks]).toString('base64')
}

/** Keepiq's API as admin, for seed and record. */
function keepiqApi(upstream, password) {
	const headers = {
		authorization: 'Basic ' + Buffer.from(`admin:${password}`).toString('base64'),
		'ocs-apirequest': 'true',
		accept: 'application/json',
	}
	return async (method, path, body) => {
		const opts = { headers: { ...headers } }
		if (body !== undefined) {
			opts.headers['content-type'] = 'application/json'
			opts.body = JSON.stringify(body)
		}
		const r = await nc(upstream, method, '/index.php/apps/keepiq' + path, opts)
		if (r.status < 200 || r.status > 299) throw new Error(`${method} ${path} answered ${r.status}: ${r.text.slice(0, 200)}`)
		return r.text ? JSON.parse(r.text) : null
	}
}

const listOf = (data) => (Array.isArray(data) ? data : data?.items || [])

async function seed(o) {
	const call = keepiqApi(o.upstream, o.password || 'admin')
	const suite = listOf(await call('GET', '/api/v1/suites')).find((s) => s.status === 'active')
	if (!suite?.certificate) throw new Error('admin has no active suite with a certificate')
	const types = listOf(await call('GET', '/api/v1/secret-types'))
	const folders = listOf(await call('GET', '/api/v1/folders'))
	const folderId = {}
	for (const name of DEMO_FOLDERS) {
		const found = folders.find((f) => f.name === name && !f.parentId)
		folderId[name] = found ? found.id : (await call('POST', '/api/v1/folders', { name, parentId: null })).id
	}
	const manifest = await call('GET', '/api/v1/offline/manifest')
	const have = new Set((manifest.secrets || []).map((s) => s.name))
	let added = 0
	for (const item of DEMO_ITEMS) {
		if (have.has(item.name)) continue
		const type = types.find((t) => t.name === item.type)
		if (!type) throw new Error(`no secret type ${item.type}`)
		const body = {
			name: item.name,
			url: item.url || null,
			typeId: type.id,
			folderId: item.folder ? folderId[item.folder] : null,
			key: encryptField(item.key, suite.certificate),
		}
		if (item.login) body.login = encryptField(item.login, suite.certificate)
		await call('POST', '/api/v1/secrets', body)
		added++
	}
	console.log(`[e2e] seeded ${added} demo items (${DEMO_ITEMS.length - added} were there)`)
}

/**
 * Records what the iOS replay needs. App passwords are replaced by fixed
 * fake ones, and the recorded host by a placeholder the replay fills in.
 */
async function record(o) {
	const upstream = o.upstream
	const host = new URL(upstream).host
	const origin = `https://${host}`
	const keepiq = '/index.php/apps/keepiq'
	const json = (r) => JSON.parse(r.text)
	const basic = (pw) => ({ authorization: 'Basic ' + Buffer.from(`admin:${pw}`).toString('base64'), 'ocs-apirequest': 'true', accept: 'application/json' })
	const out = { recordedAt: new Date().toISOString().slice(0, 10), origin: '{origin}' }

	const init = await nc(upstream, 'POST', '/index.php/login/v2', { headers: { 'user-agent': USER_AGENT } })
	if (init.status !== 200) throw new Error(`login/v2 answered ${init.status}`)
	const flow = json(init)
	await grant(upstream, flow.login.replace(/^http:/, 'https:'), 'admin', o.password || 'admin')
	const poll = await nc(upstream, 'POST', '/index.php/login/v2/poll', { form: { token: flow.poll.token } })
	if (poll.status !== 200) throw new Error(`poll answered ${poll.status}`)
	const creds = json(poll)
	const appPassword = creds.appPassword

	const call = async (method, path) => {
		const r = await nc(upstream, method, keepiq + path, { headers: basic(appPassword) })
		if (r.status !== 200) throw new Error(`${method} ${path} answered ${r.status}: ${r.text.slice(0, 200)}`)
		return json(r)
	}
	const callJson = async (method, path, body) => {
		const r = await nc(upstream, method, keepiq + path, {
			headers: { ...basic(appPassword), 'content-type': 'application/json' },
			body: JSON.stringify(body),
		})
		if (r.status < 200 || r.status > 299) throw new Error(`${method} ${path} answered ${r.status}: ${r.text.slice(0, 200)}`)
		return json(r)
	}
	const pair = await call('POST', '/api/v1/extension/pair')
	const suites = await call('GET', '/api/v1/suites')
	const policy = await call('GET', '/api/v1/extension/policy')

	// The vault (seed first): the manifest, each item as GET answers it, the
	// generator policy, and one Send made and ended again for its shapes.
	const manifest = await call('GET', '/api/v1/offline/manifest')
	if (!(manifest.secrets || []).some((s) => s.name === DEMO_ITEMS[0].name)) throw new Error('the vault is not seeded: run `server.mjs seed` first')
	const detail = await call('GET', `/api/v1/secrets/${encodeURIComponent(manifest.secrets[0].id)}`)
	const generatorPolicy = await call('GET', '/api/settings/policy')
	const send = await callJson('POST', '/api/v1/sends', {
		encryptedPayload: 'recorded-payload-not-a-secret',
		payloadType: 'text',
		maxViews: 1,
		ttlSeconds: 3600,
		hasPassword: false,
	})
	const sends = await call('GET', '/api/v1/sends')
	await call('DELETE', `/api/v1/sends/${encodeURIComponent(send.id)}`)
	occ(o.container, 'config:app:set', 'keepiq', 'vault_require_two_factor', '--value=true', '--type=boolean')
	// The web server may read the setting from its cache for a moment.
	let blocked
	try {
		for (let i = 0; i < 30; i++) {
			blocked = await call('GET', '/api/v1/suites')
			if (listOf(blocked).some((s) => s.unlockBlocked)) break
			await new Promise((r) => setTimeout(r, 2000))
		}
		if (!listOf(blocked).some((s) => s.unlockBlocked)) throw new Error('the two-factor block never showed in /suites')
	} finally {
		occ(o.container, 'config:app:delete', 'keepiq', 'vault_require_two_factor')
	}
	for (let i = 0; i < 30 && listOf(await call('GET', '/api/v1/suites')).some((s) => s.unlockBlocked); i++) {
		await new Promise((r) => setTimeout(r, 2000))
	}
	const unpair = await call('POST', '/api/v1/extension/unpair')
	const revoke = await nc(upstream, 'DELETE', '/ocs/v2.php/core/apppassword', { headers: basic(appPassword) })
	const after = await nc(upstream, 'POST', keepiq + '/api/v1/extension/pair', { headers: basic(appPassword) })

	const placeholder = (text) => text.split(origin).join('{origin}').split(`http://${host}`).join('{origin}')
	out.loginInit = JSON.parse(placeholder(JSON.stringify(flow)))
	out.loginInit.poll.token = 'recorded-poll-token'
	out.loginInit.login = out.loginInit.login.replace(/flow\/[^/?#]+/, 'flow/recorded-login-token')
	out.poll = { ...creds, server: '{origin}', appPassword: 'stub-app-password' }
	out.pair = pair
	out.suites = suites
	out.suitesTwoFactorRequired = blocked
	out.policy = policy
	out.manifest = manifest
	// GET of one item answers the manifest row plus its tags.
	out.secretTags = detail.tags ?? []
	out.generatorPolicy = generatorPolicy
	out.sendCreated = send
	out.sendListed = listOf(sends).find((s) => s.id === send.id) || listOf(sends)[0] || null
	out.unpair = unpair
	out.revoke = { status: revoke.status, body: JSON.parse(revoke.text) }
	out.afterRevoke = { status: after.status, body: JSON.parse(after.text || '{}') }
	mkdirSync(dirname(FIXTURES), { recursive: true })
	const text = JSON.stringify(out, null, '\t') + '\n'
	if (text.includes(appPassword)) throw new Error('the real app password is still in the recording')
	writeFileSync(FIXTURES, text)
	console.log(`[e2e] wrote ${FIXTURES}`)
}

/**
 * Answers as the recorded server. Accepted app passwords: stub-app-password
 * (from the login flow) and manual-app-password (typed by hand), for admin;
 * the user "blocked" gets the suite with the two-factor block. A revoked
 * password answers 401 afterwards, as the real server does.
 */
function replay(o) {
	const f = JSON.parse(readFileSync(FIXTURES, 'utf8'))
	const valid = new Set(['admin:stub-app-password', 'admin:manual-app-password', 'blocked:manual-app-password'])
	const polls = new Map()
	const fill = (value, origin) => JSON.parse(JSON.stringify(value).split('{origin}').join(origin))
	const vault = new Map((f.manifest?.secrets || []).map((row) => [row.id, { ...row }]))
	const sends = new Map()
	const server = createHttpsServer({ cert: readFileSync(o.cert), key: readFileSync(o.key) }, async (req, res) => {
		const origin = `https://${req.headers.host}`
		// Nextcloud links its Login Flow routes with and without /index.php
		// (the recording has /login/v2/poll), so the replay accepts both.
		const path = req.url.split('?')[0].replace(/^\/index\.php(?=\/login\/)/, '')
		const body = await new Promise((resolve) => {
			const chunks = []
			req.on('data', (c) => chunks.push(c))
			req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
		})
		console.log(`[replay] ${req.method} ${path}`)
		if (req.method === 'POST' && path === '/login/v2') return send(res, 200, fill(f.loginInit, origin))
		if (req.method === 'POST' && path === '/login/v2/poll') {
			// Pending twice, as while the user signs in, then granted.
			const token = new URLSearchParams(body).get('token') || ''
			const n = (polls.get(token) || 0) + 1
			polls.set(token, n)
			return n < 3 ? send(res, 404, '[]') : send(res, 200, fill(f.poll, origin))
		}
		if (path.startsWith('/login/v2/flow')) {
			return send(res, 200, '<!doctype html><title>Nextcloud</title><h1>Log in to Nextcloud (test stub)</h1>', 'text/html')
		}
		const auth = Buffer.from((req.headers.authorization || '').replace(/^Basic /, ''), 'base64').toString('utf8')
		if (!valid.has(auth)) return send(res, 401, { error: 'unauthorized' })
		const user = auth.split(':')[0]
		if (req.method === 'DELETE' && path === '/ocs/v2.php/core/apppassword') {
			valid.delete(auth)
			return send(res, f.revoke.status, f.revoke.body)
		}
		const keepiq = '/index.php/apps/keepiq/api/v1'
		if (req.method === 'POST' && path === `${keepiq}/extension/pair`) return send(res, 200, { ...f.pair, user })
		if (req.method === 'POST' && path === `${keepiq}/extension/unpair`) return send(res, 200, f.unpair)
		if (req.method === 'GET' && path === `${keepiq}/extension/policy`) return send(res, 200, f.policy)
		if (req.method === 'GET' && path === `${keepiq}/suites`) return send(res, 200, user === 'blocked' ? f.suitesTwoFactorRequired : f.suites)
		const answer = user === 'admin' ? vaultAnswer(f, vault, sends, req.method, path, req.url, body) : null
		if (answer) return send(res, answer[0], answer[1])
		return send(res, 404, { error: 'not recorded' })
	})
	server.listen(Number(o.port || 8443), '0.0.0.0', () => console.log(`[replay] on https://0.0.0.0:${o.port || 8443}`))
}

const now = () => new Date().toISOString().replace(/\.\d{3}Z$/, '+00:00')

/**
 * The vault and Send endpoints of the replay: the recorded manifest, kept in
 * memory and changed by the writes the tests make. Field values arrive
 * encrypted by the app and are kept as they are; the replay never sees a
 * plaintext. Answers [status, body], or null for a path it does not know.
 */
function vaultAnswer(f, vault, sends, method, path, url, body) {
	const api = '/index.php/apps/keepiq/api/v1'
	const json = () => {
		try {
			return JSON.parse(body || '{}')
		} catch {
			return {}
		}
	}
	const rows = () => [...vault.values()].sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
	if (method === 'GET' && path === '/index.php/apps/keepiq/api/settings/policy') return [200, f.generatorPolicy]
	if (method === 'GET' && path === `${api}/offline/manifest`) return [200, { ...f.manifest, secrets: rows(), syncedAt: now() }]
	if (method === 'GET' && path === `${api}/folders`) return [200, f.manifest.folders]
	if (method === 'GET' && path === `${api}/secret-types`) return [200, f.manifest.types]
	if (method === 'GET' && path === `${api}/secrets`) {
		const limit = Number(new URL(url, 'https://replay').searchParams.get('limit') || 100)
		return [200, { items: rows().slice(0, limit), total: vault.size }]
	}
	if (method === 'POST' && path === `${api}/secrets`) {
		const b = json()
		const template = f.manifest.secrets[0]
		const at = now()
		const row = {
			...template,
			id: randomUUID(),
			name: b.name,
			url: b.url ?? null,
			typeId: b.typeId ?? null,
			folderId: b.folderId ?? null,
			key: b.key,
			login: b.login ?? null,
			additionalFields: b.additionalFields ?? null,
			createdAt: at,
			updatedAt: at,
			keyUpdatedAt: at,
		}
		vault.set(row.id, row)
		return [201, row]
	}
	const secret = path.match(new RegExp(`^${api}/secrets/([^/]+)$`))
	if (secret) {
		const id = decodeURIComponent(secret[1])
		const row = vault.get(id)
		if (!row) return [404, { error: 'not found' }]
		if (method === 'GET') return [200, { ...row, tags: f.secretTags || [] }]
		if (method === 'PUT') {
			const b = json()
			for (const field of ['name', 'url', 'folderId', 'key', 'login', 'additionalFields']) if (field in b) row[field] = b[field]
			row.updatedAt = now()
			if ('key' in b) row.keyUpdatedAt = row.updatedAt
			return [200, row]
		}
		if (method === 'DELETE') {
			vault.delete(id)
			return [200, { ...row, trashedAt: now() }]
		}
	}
	if (method === 'GET' && path === `${api}/sends`) return [200, [...sends.values()]]
	if (method === 'POST' && path === `${api}/sends`) {
		const b = json()
		const id = randomUUID()
		const at = new Date()
		const listed = {
			...(f.sendListed || {}),
			id,
			payloadType: b.payloadType || 'text',
			maxViews: b.maxViews ?? 1,
			viewCount: 0,
			hasPassword: b.hasPassword === true,
			createdAt: now(),
			expiresAt: new Date(at.getTime() + (b.ttlSeconds || 86400) * 1000).toISOString().replace(/\.\d{3}Z$/, '+00:00'),
		}
		sends.set(id, listed)
		return [201, { ...f.sendCreated, ...listed, token: randomUUID().replace(/-/g, '') }]
	}
	const oneSend = path.match(new RegExp(`^${api}/sends/([^/]+)$`))
	if (oneSend && method === 'DELETE') {
		sends.delete(decodeURIComponent(oneSend[1]))
		return [200, { success: true }]
	}
	return null
}

const o = options(process.argv.slice(2))
if (o._ === 'proxy') proxy(o)
else if (o._ === 'seed') await seed(o)
else if (o._ === 'record') await record(o)
else if (o._ === 'replay') replay(o)
else {
	console.error('usage: server.mjs proxy|seed|record|replay [--options]')
	process.exit(2)
}
