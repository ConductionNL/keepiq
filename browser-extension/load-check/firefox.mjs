/**
 * Install the Firefox package as a temporary add-on in headless Firefox and
 * check the background script starts and answers the popup
 * (clients-browser-builds, "Firefox starts the background").
 *
 * Needs Firefox, geckodriver and `selenium-webdriver` (the CI job installs
 * the last one without saving it). The add-on UUID is pinned through the
 * `extensions.webextensions.uuids` preference so the popup URL is known.
 *
 * Usage: node browser-extension/load-check/firefox.mjs [dist/firefox]
 * Exits 0 when the background answered get-state, 1 otherwise.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/specs/clients-browser-builds/spec.md
 */
import { Builder } from 'selenium-webdriver'
import firefox from 'selenium-webdriver/firefox.js'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { GECKO_ID } from '../manifests/browsers.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const pkg = resolve(process.argv[2] || join(here, '..', 'dist', 'firefox'))
const UUID = '7a1c7c52-5b0f-4a8e-9d53-000000000001'

const options = new firefox.Options()
	.addArguments('-headless')
	.setPreference(
		'extensions.webextensions.uuids',
		JSON.stringify({ [GECKO_ID]: UUID }),
	)

let driver
try {
	driver = await new Builder()
		.forBrowser('firefox')
		.setFirefoxOptions(options)
		.build()
	await driver.installAddon(pkg, true)
	await driver.get(`moz-extension://${UUID}/popup.html`)
	let state = null
	for (let i = 0; i < 10 && !state; i++) {
		state = await driver.executeAsyncScript(function (done) {
			browser.runtime
				.sendMessage({ type: 'get-state' })
				.then(done, () => done(null))
		})
		if (!state) await new Promise((r) => setTimeout(r, 500))
	}
	if (!state || state.paired !== false) {
		throw new Error('unexpected state ' + JSON.stringify(state))
	}
	console.log(
		'firefox: background started, get-state answered',
		JSON.stringify(state),
	)
} catch (e) {
	console.error('firefox load check failed:', e.message || e)
	process.exitCode = 1
} finally {
	await driver?.quit()
}
