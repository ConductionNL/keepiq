import { describe, expect, it } from 'vitest'
import config from '../wxt.config'

type ManifestCallback = (env: { browser: string; command: 'build'; mode: string; manifestVersion: 2 | 3 }) => Promise<Record<string, unknown>> | Record<string, unknown>

const manifestOf = (browser: string, manifestVersion: 2 | 3) =>
	(config.manifest as ManifestCallback)({ browser, command: 'build', mode: 'production', manifestVersion }) as Record<string, unknown>

describe('per-browser manifest', () => {
	it.each([['chrome', 3], ['edge', 3]] as const)('gives %s the Chromium-only APIs', async (browser, manifestVersion) => {
		const manifest = await manifestOf(browser, manifestVersion)
		expect(manifest.permissions).toEqual(expect.arrayContaining(['idle', 'offscreen']))
		expect(manifest.permissions).not.toContain('nativeMessaging')
		expect(manifest.optional_permissions).toEqual(['privacy'])
		expect(manifest.optional_host_permissions).toEqual(['https://*/*', 'http://*/*'])
	})

	it('gives Firefox idle, and neither offscreen nor privacy', async () => {
		const manifest = await manifestOf('firefox', 2)
		expect(manifest.permissions).toContain('idle')
		expect(manifest.permissions).not.toContain('offscreen')
		expect(manifest.permissions).not.toContain('nativeMessaging')
		expect(manifest.optional_permissions).toEqual(['https://*/*', 'http://*/*'])
		expect(manifest.browser_specific_settings).toHaveProperty('gecko.strict_min_version', '109.0')
	})

	it('gives Safari native messaging and Safari 26, and none of idle, offscreen, privacy or gecko', async () => {
		const manifest = await manifestOf('safari', 3)
		expect(manifest.permissions).toContain('nativeMessaging')
		for (const api of ['idle', 'offscreen', 'privacy']) expect(manifest.permissions).not.toContain(api)
		expect(manifest).not.toHaveProperty('optional_permissions')
		expect(manifest.optional_host_permissions).toEqual(['https://*/*', 'http://*/*'])
		expect(manifest.browser_specific_settings).toEqual({ safari: { strict_min_version: '26.0' } })
	})
})
