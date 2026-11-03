import { describe, expect, it } from 'vitest'
import config from '../wxt.config'

type ManifestCallback = (env: { browser: string; command: 'build'; mode: string; manifestVersion: 2 | 3 }) => Promise<Record<string, unknown>> | Record<string, unknown>

// Why the callback must carry it: see the comment on content_security_policy in wxt.config.ts.
describe('manifest content security policy', () => {
	it.each([['chrome', 3], ['firefox', 2]] as const)('allows WebAssembly on %s (MV%i) production builds', async (browser, manifestVersion) => {
		const manifest = await (config.manifest as ManifestCallback)({ browser, command: 'build', mode: 'production', manifestVersion })
		expect(manifest.content_security_policy).toEqual({ extension_pages: "script-src 'self' 'wasm-unsafe-eval'; object-src 'self';" })
	})
})
