/**
 * @spec openspec/specs/extension-release/spec.md
 *
 * What a store package needs beyond the code: icons from the app's own
 * mark, Firefox's data collection declaration, and the notices for bundled
 * third-party material. Checked on a real build.
 */
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'
import {
	DATA_COLLECTION,
	manifestFor,
} from '../../browser-extension/manifests/browsers.mjs'

const ROOT = resolve(__dirname, '../..')
const out = mkdtempSync(join(tmpdir(), 'keepiq-release-'))
execFileSync(process.execPath, ['browser-extension/build.mjs', '--outdir', out], {
	cwd: ROOT,
	stdio: 'pipe',
})
afterAll(() => rmSync(out, { recursive: true, force: true }))

const base = JSON.parse(
	readFileSync(join(ROOT, 'browser-extension/manifest.json'), 'utf8'),
)

/**
 * Width and height of a PNG, from its header.
 *
 * @param {string} path The file.
 * @return {Array<number>}
 */
function pngSize(path) {
	const bytes = readFileSync(path)
	expect(bytes.subarray(1, 4).toString()).toBe('PNG')
	return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)]
}

describe('the package', () => {
	it.each(['chromium', 'firefox'])(
		'ships an icon of every size the manifest names, for the toolbar too (%s)',
		(browser) => {
			const manifest = JSON.parse(
				readFileSync(join(out, browser, 'manifest.json'), 'utf8'),
			)
			expect(Object.keys(manifest.icons)).toEqual(['16', '32', '48', '128'])
			expect(manifest.action.default_icon).toEqual(manifest.icons)
			for (const [size, file] of Object.entries(manifest.icons)) {
				expect(pngSize(join(out, browser, file))).toEqual([
					Number(size),
					Number(size),
				])
			}
		},
	)

	it('tells Firefox that logins and the current site leave the browser, and nothing else', () => {
		const firefox = manifestFor(base, 'firefox')
		expect(
			firefox.browser_specific_settings.gecko.data_collection_permissions,
		).toEqual({ required: ['authenticationInfo', 'browsingActivity'] })
		expect(DATA_COLLECTION).not.toContain('none')
		expect(
			manifestFor(base, 'chromium').browser_specific_settings,
		).toBeUndefined()
	})

	it.each(['chromium', 'firefox'])(
		'carries the notices for the bundled word list and Argon2 (%s)',
		(browser) => {
			const notices = readFileSync(
				join(out, browser, 'THIRD-PARTY-NOTICES.txt'),
				'utf8',
			)
			expect(notices).toContain('EFF large word list')
			expect(notices).toContain('CC BY 3.0 US')
			expect(notices).toContain('argon2-browser')
			expect(notices).toContain('Permission is hereby granted, free of charge')
		},
	)
})
