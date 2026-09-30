/**
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/specs/clients-browser-builds/spec.md
 *
 * Each browser package carries the manifest keys that browser requires: a
 * module service worker for Chromium, background scripts and a gecko id for
 * Firefox.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	BROWSERS,
	GECKO_ID,
	manifestFor,
} from '../../browser-extension/manifests/browsers.mjs'

const base = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../browser-extension/manifest.json'),
		'utf8',
	),
)

describe('per-browser manifests', () => {
	it('builds Chromium and Firefox', () => {
		expect(BROWSERS).toEqual(['chromium', 'firefox'])
	})

	it('keeps the Chromium service worker', () => {
		const m = manifestFor(base, 'chromium')
		expect(m.manifest_version).toBe(3)
		expect(m.background).toEqual({
			service_worker: 'service-worker.js',
			type: 'module',
		})
		expect(m.browser_specific_settings).toBeUndefined()
	})

	it('gives Firefox background scripts and a gecko id', () => {
		const m = manifestFor(base, 'firefox')
		expect(m.manifest_version).toBe(3)
		expect(m.background).toEqual({ scripts: ['service-worker.js'] })
		expect(m.background.service_worker).toBeUndefined()
		expect(m.browser_specific_settings.gecko.id).toBe(GECKO_ID)
		expect(m.browser_specific_settings.gecko.strict_min_version).toMatch(
			/^\d+\.\d+$/,
		)
		expect(m.permissions).not.toContain('windows')
	})

	it('shares everything else with the base', () => {
		const c = manifestFor(base, 'chromium')
		const f = manifestFor(base, 'firefox')
		for (const key of [
			'name',
			'version',
			'action',
			'content_scripts',
			'web_accessible_resources',
			'host_permissions',
		]) {
			expect(c[key]).toEqual(base[key])
			expect(f[key]).toEqual(base[key])
		}
		expect(base.background).toBeUndefined()
	})

	it('refuses an unknown browser', () => {
		expect(() => manifestFor(base, 'netscape')).toThrow()
	})
})
