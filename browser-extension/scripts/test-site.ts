import { createReadStream } from 'node:fs'
import { stat } from 'node:fs/promises'
import { createServer, type Server } from 'node:http'
import { extname, join, normalize } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export const TEST_SITE_PORT = Number(process.env.TEST_SITE_PORT ?? 8100)

const ROOT = fileURLToPath(new URL('../test-site/', import.meta.url))
const TYPES: Record<string, string> = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.svg': 'image/svg+xml',
}

export function startTestSite(port = TEST_SITE_PORT): Promise<Server> {
	const server = createServer(async (req, res) => {
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
	})
	return new Promise((resolve, reject) => {
		server.once('error', reject)
		server.listen(port, '127.0.0.1', () => resolve(server))
	})
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	await startTestSite()
	console.log(`Test site: http://localhost:${TEST_SITE_PORT}/`)
}
