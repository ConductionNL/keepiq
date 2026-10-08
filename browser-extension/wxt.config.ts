import type { Server } from 'node:http'
import { defineConfig } from 'wxt'
import { defaultBrowserBinaries } from './scripts/default-browser'
import { startTestSite, TEST_SITE_PORT } from './scripts/test-site'

let testSite: Server | undefined

export default defineConfig({
	hooks: {
		// Started before the browser opens, so the dev profile can land on it.
		'server:started': async (wxt) => {
			testSite = await startTestSite().catch((error) => {
				wxt.logger.warn(`Test site not started: ${error.message}`)
				return undefined
			})
		},
		'server:closed': () => {
			testSite?.close()
		},
	},
	webExt: {
		// A local web-ext.config.ts still overrides this.
		binaries: defaultBrowserBinaries(),
		startUrls: [`http://localhost:${TEST_SITE_PORT}/`],
		// A persistent profile keeps the paired account and settings across dev restarts.
		chromiumArgs: ['--user-data-dir=./.wxt/chrome-data'],
	},
	// No `manifestVersion` here on purpose: WXT's per-browser default (Chrome MV3,
	// Firefox MV2) is what keeps `wxt -b firefox` working. See WXT-AND-BROWSERS.md § 2.
	imports: {
		eslintrc: {
			enabled: 9,
		},
	},
	manifest: ({ mode }) => {
		// A separate name in dev means a dev build and a store build can sit side by
		// side in the same browser profile without you guessing which is which.
		const nameSuffix = mode === 'production' ? '' : ' (DEV)'
		return {
			name: `Keepiq${nameSuffix}`,
			description: 'Browser extension for Keepiq, the encrypted secrets manager for Nextcloud. Fill in logins, passkeys and one-time codes on any site, and save new ones as you go. Everything is encrypted — your master password and your secrets never reach the server.',
			// `version` is deliberately omitted — WXT derives it from package.json,
			// so there is only one place to bump.
			permissions: [
				'storage',
			],
			// Add `host_permissions` when the extension needs to reach page origins
			// beyond its content-script matches (fetch, cookies, tabs.executeScript).
			action: {
				default_title: `Keepiq${nameSuffix}`,
			},
			browser_specific_settings: {
				gecko: {
					id: 'keepiq@sudothijn',
					// 109 is the first Firefox with the MV3 APIs backported to MV2;
					// raise it if you adopt something newer (e.g. storage.session needs 115).
					strict_min_version: '109.0',
					// Mandatory for extensions new to AMO since 2025-11-03. `['none']`
					// is a claim Mozilla holds you to — if the extension starts
					// collecting anything, declare it here instead of leaving this.
					// https://extensionworkshop.com/documentation/develop/firefox-builtin-data-consent/
					data_collection_permissions: {
						required: ['none'],
					},
				},
			},
		}
	},
})
