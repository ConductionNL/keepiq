/**
 * Local stand-ins for the websites the capture visits, so no third-party
 * site is ever opened.
 *
 * One https server answers every *.example.com name by its Host header:
 * demo login pages, a WebAuthn demo page, and cloud.example.com, which
 * forwards to the capture Nextcloud so the extension pairs with an https
 * address as a real user would. The certificate is self-signed and made per
 * run; the browsers are told to accept it. Chromium reaches the server through
 * --host-resolver-rules, Firefox through a small CONNECT proxy.
 */
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { createServer as createHttpServer, request as httpRequest } from 'node:http'
import { createServer as createHttpsServer } from 'node:https'
import { connect } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

/** The demo sites, by host. */
export const SITES = {
	'webmail.example.com': { title: 'Webmail (demo)', field: 'Email address' },
	'bank.example.com': { title: 'Bank (demo)', field: 'Customer number' },
	'forum.example.com': { title: 'Community forum (demo)', field: 'Username' },
}

export const PASSKEY_HOST = 'passkeys.example.com'
export const CLOUD_HOST = 'cloud.example.com'

const STYLE = `
	body { font: 16px/1.5 system-ui, sans-serif; margin: 0; background: #f3f5f7; color: #1d2733; }
	header { background: #1d4f91; color: #fff; padding: 14px 32px; font-weight: 600; }
	main { max-width: 380px; margin: 56px auto; background: #fff; padding: 28px 32px;
		border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
	h1 { font-size: 22px; margin: 0 0 18px; }
	label { display: block; margin: 12px 0 4px; font-weight: 600; font-size: 14px; }
	input { width: 100%; box-sizing: border-box; padding: 9px 10px; font: inherit;
		border: 1px solid #8a96a3; border-radius: 6px; }
	button { margin-top: 20px; width: 100%; padding: 10px; font: inherit; font-weight: 600;
		color: #fff; background: #1d4f91; border: 0; border-radius: 6px; cursor: pointer; }
	p.note { color: #4a5663; font-size: 14px; }
	#result { margin-top: 16px; font-weight: 600; }`

function page(title, body) {
	return `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${title}</title>
<style>${STYLE}</style></head><body><header>${title}</header><main>${body}</main></body></html>`
}

function loginPage(site) {
	return page(
		site.title,
		`<h1>Sign in</h1>
<form method="post" action="/signed-in" autocomplete="on">
	<label for="user">${site.field}</label>
	<input id="user" name="username" autocomplete="username">
	<label for="password">Password</label>
	<input id="password" name="password" type="password" autocomplete="current-password">
	<button type="submit">Sign in</button>
</form>
<p class="note">A demo page for the Keepiq screenshots. Nothing is sent anywhere.</p>`,
	)
}

function signedInPage(site) {
	return page(
		site.title,
		`<h1>Welcome back</h1><p class="note">You are signed in to the demo site.</p>`,
	)
}

// The WebAuthn demo: the browser's own API, answered by the extension.
// Nothing is verified server-side; the page only shows what came back.
const PASSKEY_PAGE = page(
	'Passkey demo',
	`<h1>Passkeys</h1>
<p class="note">Create a passkey for this demo site, then sign in with it.</p>
<button id="create" type="button">Create a passkey</button>
<button id="signin" type="button">Sign in with a passkey</button>
<p id="result" role="status"></p>
<script>
const out = document.getElementById('result')
const rand = (n) => crypto.getRandomValues(new Uint8Array(n))
document.getElementById('create').addEventListener('click', async () => {
	out.textContent = ''
	try {
		const cred = await navigator.credentials.create({ publicKey: {
			rp: { id: location.hostname, name: 'Passkey demo' },
			user: { id: rand(16), name: 'anna.demo@example.com', displayName: 'Anna Demo' },
			challenge: rand(32),
			pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
		} })
		out.textContent = cred ? 'Passkey created for anna.demo@example.com.' : 'No passkey was created.'
	} catch (e) { out.textContent = 'No passkey was created: ' + e.message }
})
document.getElementById('signin').addEventListener('click', async () => {
	out.textContent = ''
	try {
		const cred = await navigator.credentials.get({ publicKey: {
			rpId: location.hostname, challenge: rand(32),
		} })
		out.textContent = cred ? 'Signed in with your passkey.' : 'Not signed in.'
	} catch (e) { out.textContent = 'Not signed in: ' + e.message }
})
</script>`,
)

/**
 * Make a self-signed certificate for *.example.com.
 *
 * @param {string} dir Where to write it.
 * @return {{key: Buffer, cert: Buffer}}
 */
function makeCertificate(dir) {
	const key = join(dir, 'key.pem')
	const cert = join(dir, 'cert.pem')
	execFileSync('openssl', [
		'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '2',
		'-subj', '/CN=*.example.com',
		'-addext', 'subjectAltName=DNS:*.example.com,DNS:example.com',
		'-keyout', key, '-out', cert,
	], { stdio: 'ignore' })
	return { key: readFileSync(key), cert: readFileSync(cert) }
}

/**
 * Start the demo sites.
 *
 * @param {object} options The ports and the Nextcloud to forward to.
 * @param {number} options.httpsPort The https server's port.
 * @param {number} options.proxyPort The CONNECT proxy's port (Firefox).
 * @param {string} options.nextcloud The capture Nextcloud, http://localhost:8188.
 * @return {Promise<{close: Function}>}
 */
export async function startFixtures({ httpsPort, proxyPort, nextcloud }) {
	const dir = mkdtempSync(join(tmpdir(), 'keepiq-capture-cert-'))
	const tls = makeCertificate(dir)
	const upstream = new URL(nextcloud)

	const server = createHttpsServer(tls, (req, res) => {
		const host = String(req.headers.host || '').split(':')[0]
		if (host === CLOUD_HOST) {
			// Forward to the capture Nextcloud, keeping the Host it trusts.
			const forward = httpRequest(
				{
					hostname: upstream.hostname,
					port: upstream.port,
					method: req.method,
					path: req.url,
					headers: { ...req.headers, host: CLOUD_HOST },
				},
				(answer) => {
					res.writeHead(answer.statusCode || 502, answer.headers)
					answer.pipe(res)
				},
			)
			forward.on('error', () => {
				res.writeHead(502)
				res.end()
			})
			req.pipe(forward)
			return
		}
		res.setHeader('content-type', 'text/html; charset=utf-8')
		if (host === PASSKEY_HOST) {
			res.end(PASSKEY_PAGE)
			return
		}
		const site = SITES[host]
		if (!site) {
			res.writeHead(404)
			res.end('not a demo site')
			return
		}
		if (req.method === 'POST' || req.url.startsWith('/signed-in')) {
			req.resume()
			// A real sign-in takes a moment; the extension decides its save
			// offer in that time, and the next page shows it.
			setTimeout(() => res.end(signedInPage(site)), 1500)
			return
		}
		res.end(loginPage(site))
	})
	await new Promise((resolve) => server.listen(httpsPort, '127.0.0.1', resolve))

	// Firefox has no host resolver rules: it sends example.com through this
	// proxy, which tunnels every CONNECT to the https server above.
	const proxy = createHttpServer((req, res) => {
		res.writeHead(405)
		res.end()
	})
	proxy.on('connect', (req, socket, head) => {
		const target = connect(httpsPort, '127.0.0.1', () => {
			socket.write('HTTP/1.1 200 Connection Established\r\n\r\n')
			target.write(head)
			target.pipe(socket)
			socket.pipe(target)
		})
		target.on('error', () => socket.destroy())
		socket.on('error', () => target.destroy())
	})
	await new Promise((resolve) => proxy.listen(proxyPort, '127.0.0.1', resolve))

	return {
		async close() {
			server.closeAllConnections?.()
			proxy.closeAllConnections?.()
			await Promise.all([
				new Promise((resolve) => server.close(resolve)),
				new Promise((resolve) => proxy.close(resolve)),
			])
			rmSync(dir, { recursive: true, force: true })
		},
	}
}
