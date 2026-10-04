/**
 * Per-browser manifests (clients-browser-builds). One base manifest
 * (`manifest.json`) plus a small overlay per browser, so the differences are
 * few and reviewable:
 *  - Chromium (Chrome, Edge): a module service worker.
 *  - Firefox: background `scripts` (Firefox MV3 has no extension service
 *    worker) and a gecko id with a minimum version.
 *  - Safari: the Chromium package is the input of Apple's converter; see
 *    `safari/README.md`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/specs/clients-browser-builds/spec.md
 */

/** The add-on id Firefox signs and updates under. */
export const GECKO_ID = 'keepiq@conduction.nl'

/** The oldest Firefox with the MV3 features the extension uses. */
export const GECKO_MIN_VERSION = '115.0'

/** The browsers a package is built for. */
export const BROWSERS = Object.freeze(['chromium', 'firefox'])

/**
 * The manifest for one browser.
 *
 * @param {object} base The base manifest.
 * @param {string} browser chromium or firefox.
 * @return {object} A new manifest object.
 */
export function manifestFor(base, browser) {
	const manifest = structuredClone(base)
	if (browser === 'chromium') {
		manifest.background = { service_worker: 'service-worker.js', type: 'module' }
		return manifest
	}
	if (browser === 'firefox') {
		// The worker bundle is built as a classic script for Firefox.
		manifest.background = { scripts: ['service-worker.js'] }
		manifest.browser_specific_settings = {
			gecko: { id: GECKO_ID, strict_min_version: GECKO_MIN_VERSION },
		}
		// Firefox has no `windows` permission (the API needs none) and warns on
		// it, and no offscreen documents (its background page has a document).
		manifest.permissions = (manifest.permissions || []).filter(
			(p) => p !== 'windows' && p !== 'offscreen',
		)
		return manifest
	}
	throw new Error('unknown browser: ' + browser)
}
