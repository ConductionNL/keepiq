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
 *                  Nextcloud device list), through occ;
 *             POST /__e2e/assetlinks  the statements this front then serves
 *                  at /.well-known/assetlinks.json (Digital Asset Links for
 *                  the autofill test app, whose signing key is made per run);
 *             GET  /__e2e/login.html  a login form, for the autofill test's
 *                  WebView (the web-domain path).
 *   record  Talks to the test Nextcloud and writes the answers the iOS
 *           tests replay to mobile/e2e/fixtures/server.json.
 *   replay  An https stub that answers from those recordings, for the iOS
 *           simulator job, where no Docker runs.
 *
 *   node mobile/e2e/server.mjs proxy  --port 8443 --cert c.pem --key k.pem --upstream http://localhost:8188 --container kq-e2e-nc-1
 *   node mobile/e2e/server.mjs record --upstream http://localhost:8188 --container kq-e2e-nc-1
 *   node mobile/e2e/server.mjs replay --port 8443 --cert c.pem --key k.pem
 *
 * The demo account is admin with the password admin and the master password
 * Oj, the development vault of browser-extension/capture/setup.sh.
 */
import { execFileSync } from 'node:child_process'
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

// What /.well-known/assetlinks.json answers; the autofill test posts it.
let assetLinks = '[]'

// The WebView page of the autofill test: a login form that shows what was
// filled in, so the test can read it.
const LOGIN_PAGE = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Sign in</title>
<style>body{font:20px sans-serif;margin:16px}input{display:block;width:100%;font-size:24px;padding:12px;margin:8px 0}</style></head>
<body><form id="login" onsubmit="return false">
<label for="username">User name</label>
<input id="username" name="username" type="text" autocomplete="username" autofocus>
<label for="password">Password</label>
<input id="password" name="password" type="password" autocomplete="current-password">
<button type="submit">Sign in</button></form>
<p id="result">empty</p>
<script>
const show = () => { document.getElementById('result').textContent = 'filled: ' + document.getElementById('username').value + ' / ' + document.getElementById('password').value.length }
for (const id of ['username', 'password']) document.getElementById(id).addEventListener('input', show)
setInterval(show, 500)
</script></body></html>`

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
			if (req.url === '/__e2e/assetlinks' && req.method === 'POST') {
				assetLinks = JSON.stringify(await readJson(req))
				return send(res, 200, { ok: true })
			}
			if (req.url === '/.well-known/assetlinks.json') {
				return send(res, 200, assetLinks)
			}
			if (req.url.startsWith('/__e2e/login.html')) {
				return send(res, 200, LOGIN_PAGE, 'text/html; charset=utf-8')
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
	const pair = await call('POST', '/api/v1/extension/pair')
	const suites = await call('GET', '/api/v1/suites')
	const policy = await call('GET', '/api/v1/extension/policy')
	occ(o.container, 'config:app:set', 'keepiq', 'vault_require_two_factor', '--value=true', '--type=boolean')
	let blocked
	try {
		blocked = await call('GET', '/api/v1/suites')
	} finally {
		occ(o.container, 'config:app:delete', 'keepiq', 'vault_require_two_factor')
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
// A vault in memory for the iOS AutoFill test: the replay has no recorded
// items, so what the app saves (encrypted to the recorded suite's key) is
// kept here and listed back, also in the offline manifest.
const replaySecrets = []
const replayTypes = [{ id: 't-login', name: 'login', fields: [] }, { id: 't-totp', name: 'totp', fields: [] }]
function replayVault(method, path, body, f) {
	const keepiq = '/index.php/apps/keepiq/api/v1'
	if (method === 'GET' && path === `${keepiq}/offline/manifest`) {
		const suite = (Array.isArray(f.suites) ? f.suites : []).find((s) => s.status === 'active') || null
		return [200, { suite, secrets: replaySecrets, folders: [], types: replayTypes }]
	}
	if (method === 'GET' && path === `${keepiq}/folders`) return [200, []]
	if (method === 'GET' && path === `${keepiq}/secret-types`) return [200, replayTypes]
	if (method === 'GET' && path === `${keepiq}/secrets`) return [200, { items: replaySecrets, total: replaySecrets.length }]
	if (method === 'POST' && path === `${keepiq}/secrets`) {
		let item
		try {
			item = JSON.parse(body || '{}')
		} catch {
			return [400, { error: 'not json' }]
		}
		const saved = { ...item, id: `replay-${replaySecrets.length + 1}`, updatedAt: new Date().toISOString(), useOnly: false, readOnly: false, blocked: false }
		replaySecrets.push(saved)
		return [200, saved]
	}
	const one = path.startsWith(`${keepiq}/secrets/`) ? replaySecrets.find((s) => path === `${keepiq}/secrets/${s.id}`) : null
	if (method === 'GET' && one) return [200, one]
	return null
}

function replay(o) {
	const f = JSON.parse(readFileSync(FIXTURES, 'utf8'))
	const valid = new Set(['admin:stub-app-password', 'admin:manual-app-password', 'blocked:manual-app-password'])
	const polls = new Map()
	const fill = (value, origin) => JSON.parse(JSON.stringify(value).split('{origin}').join(origin))
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
		const vaultAnswer = replayVault(req.method, path, body, f)
		if (vaultAnswer) return send(res, vaultAnswer[0], vaultAnswer[1])
		return send(res, 404, { error: 'not recorded' })
	})
	server.listen(Number(o.port || 8443), '0.0.0.0', () => console.log(`[replay] on https://0.0.0.0:${o.port || 8443}`))
}

const o = options(process.argv.slice(2))
if (o._ === 'proxy') proxy(o)
else if (o._ === 'record') await record(o)
else if (o._ === 'replay') replay(o)
else {
	console.error('usage: server.mjs proxy|record|replay [--options]')
	process.exit(2)
}
