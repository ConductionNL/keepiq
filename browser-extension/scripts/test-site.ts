import { createReadStream } from 'node:fs'
import { stat } from 'node:fs/promises'
import { createServer, type IncomingMessage, type Server, type ServerResponse } from 'node:http'
import { extname, join, normalize } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export const TEST_SITE_PORT = Number(process.env.TEST_SITE_PORT ?? 8100)

const ROOT = fileURLToPath(new URL('../test-site/', import.meta.url))
/** The code steps `totp-login.html` can lead to. */
const CODE_STEPS = new Set(['otp', 'otp-halves', 'otp-split'])
const TYPES: Record<string, string> = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.svg': 'image/svg+xml',
}

async function serve(req: IncomingMessage, res: ServerResponse): Promise<void> {
	const url = new URL(req.url ?? '/', 'http://localhost')

	if (url.pathname === '/redirect') {
		req.resume()
		res.writeHead(302, { Location: `/signed-in.html?from=${encodeURIComponent(url.searchParams.get('from') ?? '')}` })
		res.end()
		return
	}
	// Submitted values are drained and dropped, never logged.
	if (req.method === 'POST') {
		req.resume()
		req.on('end', () => {
			if (url.pathname === '/totp-login') {
				const next = url.searchParams.get('next') ?? ''
				res.writeHead(303, { Location: `/${CODE_STEPS.has(next) ? next : 'otp'}.html` })
				res.end()
				return
			}
			if (url.pathname === '/api/login') {
				res.writeHead(200, { 'Content-Type': 'application/json' })
				res.end('{"ok":true}')
				return
			}
			// Two hops, so the save bar has a redirect chain to survive.
			res.writeHead(303, { Location: `/redirect?from=${encodeURIComponent(url.pathname)}` })
			res.end()
		})
		return
	}

	const path = normalize(join(ROOT, url.pathname.endsWith('/') ? `${url.pathname}index.html` : url.pathname))
	if (!path.startsWith(ROOT) || !(await stat(path).catch(() => null))?.isFile()) {
		res.writeHead(404, { 'Content-Type': 'text/plain' })
		res.end('Not found')
		return
	}
	res.writeHead(200, { 'Content-Type': TYPES[extname(path)] ?? 'application/octet-stream' })
	createReadStream(path).pipe(res)
}

function listen(port: number): Promise<Server> {
	const server = createServer(serve)
	return new Promise((resolve, reject) => {
		server.once('error', reject)
		server.listen(port, '127.0.0.1', () => resolve(server))
	})
}

/** Also serves `port + 1`, the same site on another origin for `inline-list.html`; closing the returned server closes both. */
export async function startTestSite(port = TEST_SITE_PORT): Promise<Server> {
	const server = await listen(port)
	const other = await listen(port + 1).catch((error: Error) => {
		console.warn(`Test site: port ${port + 1} not served, so the same-site iframe stays empty: ${error.message}`)
		return null
	})
	if (other) server.once('close', () => other.close())
	return server
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	await startTestSite()
	console.log(`Test site: http://localhost:${TEST_SITE_PORT}/ (and ${TEST_SITE_PORT + 1} for the same-site iframe)`)
}
