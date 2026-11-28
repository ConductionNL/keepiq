import { execSync } from 'node:child_process'
import { createServer } from 'node:net'
import type { FullConfig } from '@playwright/test'
import { startTestSite } from '../scripts/test-site'

function assertFree(port: number): Promise<void> {
	const probe = createServer()
	return new Promise((resolve, reject) => {
		probe.once('error', () => reject(new Error(`Port ${port} is in use; set E2E_SITE_PORT to a free pair of ports.`)))
		probe.listen(port, '127.0.0.1', () => probe.close(() => resolve()))
	})
}

/** Builds the Chrome extension and serves the test site, which the fixture loads and visits. */
export default async function globalSetup(config: FullConfig): Promise<() => Promise<void>> {
	execSync('npm run build', { stdio: 'inherit' })
	const port = Number(new URL(config.projects[0]!.use.baseURL!).port)
	// The same-site frame on `port + 1` would otherwise stay empty, which reads as a pass.
	await assertFree(port + 1)
	const site = await startTestSite(port)
	return () => new Promise((resolve) => site.close(() => resolve()))
}
