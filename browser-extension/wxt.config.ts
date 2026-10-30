import type { Server } from 'node:http'
import { resolve } from 'node:path'
import { defineConfig } from 'wxt'
import { defaultBrowserBinaries } from './scripts/default-browser'
import { prepareDevProfile } from './scripts/dev-profile'
import { startTestSite, TEST_SITE_PORT } from './scripts/test-site'

let testSite: Server | undefined
const chromiumProfile = resolve('.wxt/chrome-data')

export default defineConfig({
	hooks: {
		// Started before the browser opens, so the dev profile can land on it.
		'server:started': async (wxt) => {
			prepareDevProfile(chromiumProfile)
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
		chromiumProfile,
		keepProfileChanges: true,
		// Open only the start URL, not the tabs of the last run; 5 is "New Tab page".
		chromiumPref: { session: { restore_on_startup: 5 } },
		// Stopping dev kills the browser, which it would otherwise report as a crash.
		chromiumArgs: ['--hide-crash-restore-bubble'],
	},
	// No `manifestVersion` here on purpose: WXT's per-browser default (Chrome MV3,
	// Firefox MV2) is what keeps `wxt -b firefox` working. See WXT-AND-BROWSERS.md § 2.
	imports: {
		eslintrc: {
			enabled: 9,
		},
	},
	modules: ['@wxt-dev/module-react', '@wxt-dev/i18n/module'],
	manifest: ({ browser, mode, manifestVersion }) => {
		// A separate name in dev means a dev build and a store build can sit side by
		// side in the same browser profile without you guessing which is which.
		const nameSuffix = mode === 'production' ? '' : ' (DEV)'
		// Each Keepiq server is granted at add time, from the popup (ADR-003).
		const serverOrigins = ['https://*/*', 'http://*/*']
		// Only to turn off Chrome's own password manager (ext-default-password-manager); Firefox's cannot hide its dropdowns.
		const optionalApis = browser === 'firefox' ? [] : ['privacy']
		return {
			name: `Keepiq${nameSuffix}`,
			description: '__MSG_extensionDescription__',
			// The catalogs live in locales/; the browser picks one by its UI language.
			default_locale: 'en',
			// `version` is deliberately omitted — WXT derives it from package.json,
			// so there is only one place to bump.
			permissions: [
				'storage',
				// Firefox caps storage.local at 5 MB, which a few thousand RSA blobs approach.
				'unlimitedStorage',
				'alarms',
				'idle',
				'tabs',
				// Scan QR code on page takes a picture of the tab under the toolbar popup; `tabs` alone doesn't allow that.
				'activeTab',
				// Clearing the clipboard writes it without a user gesture, from an offscreen
				// document on Chrome and from the background page on Firefox.
				'clipboardWrite',
				...(browser === 'firefox' ? [] : ['offscreen']),
				// Firefox reads it as an alias of `menus`.
				'contextMenus',
			],
			commands: {
				autofill_login: {
					suggested_key: { default: 'Ctrl+Shift+L', mac: 'Command+Shift+L' },
					description: '__MSG_commandAutofillLogin__',
				},
				// Why this key: design.md § Notification bar.
				focus_notification_bar: {
					suggested_key: { default: 'Alt+Shift+K', mac: 'Command+Shift+K' },
					description: '__MSG_commandFocusNotificationBar__',
				},
			},
			// The save bar and the inline list, framed into pages. WXT writes MV2's string form for Firefox.
			web_accessible_resources: [{ resources: ['notification.html', 'inline-list.html'], matches: ['*://*/*'] }],
			...(manifestVersion === 3
				? { optional_host_permissions: serverOrigins, ...(optionalApis.length > 0 && { optional_permissions: optionalApis }) }
				: { optional_permissions: [...serverOrigins, ...optionalApis] }),
			// MV2 gets `browser_action` by name: WXT drops `action` there once the popup has made one.
			[manifestVersion === 3 ? 'action' : 'browser_action']: {
				default_title: `Keepiq${nameSuffix}`,
				// Painted before the background runs, so it must not claim an unlocked vault (src/toolbar-icon.ts).
				default_icon: { 16: 'icon/toolbar-logged-out/16.png', 32: 'icon/toolbar-logged-out/32.png' },
			},
			// Argon2id for send passwords is WebAssembly. Dev builds get this from WXT, production
			// only from here; WXT turns the object into MV2's string.
			content_security_policy: {
				extension_pages: "script-src 'self' 'wasm-unsafe-eval'; object-src 'self';",
			},
			browser_specific_settings: {
				gecko: {
					id: 'keepiq@conduction.nl',
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
