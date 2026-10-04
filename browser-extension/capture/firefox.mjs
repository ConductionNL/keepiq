/**
 * Capture screenshots of the Firefox package: the popup and a fill.
 *
 * Run after chromium.mjs against the same instance: that script fills the
 * vault with the demo items this one shows. Needs Firefox, geckodriver and
 * `selenium-webdriver`, like load-check/firefox.mjs. The add-on UUID is
 * pinned so the popup URL is known. Writes to browser-extension/capture/out/media/.
 *
 *   node browser-extension/capture/firefox.mjs
 */
import { Builder, By, until } from 'selenium-webdriver'
import firefox from 'selenium-webdriver/firefox.js'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { GECKO_ID } from '../manifests/browsers.mjs'
import { CLOUD_HOST, DEMO_DOMAINS, startFixtures } from './fixtures.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const pkg = resolve(process.argv[2] || join(here, '..', 'dist', 'firefox'))
const OUT = join(here, 'out', 'media')
const SERVER = process.env.KEEPIQ_CAPTURE_SERVER || 'http://localhost:8188'
const HTTPS_PORT = Number(process.env.KEEPIQ_CAPTURE_HTTPS_PORT || 8443)
const PROXY_PORT = Number(process.env.KEEPIQ_CAPTURE_PROXY_PORT || 8444)
const APP_PASSWORD = readFileSync(join(here, 'out', 'app-password'), 'utf8').trim()
const MASTER = process.env.KEEPIQ_CAPTURE_MASTER || 'Oj'
const UUID = '7a1c7c52-5b0f-4a8e-9d53-000000000002'
const POPUP_URL = `moz-extension://${UUID}/popup.html`

// Only the demo names go through the proxy; everything else stays direct.
const PAC = `function FindProxyForURL(url, host) {
	var demo = ${JSON.stringify(DEMO_DOMAINS)};
	for (var i = 0; i < demo.length; i++) {
		if (host === demo[i] || dnsDomainIs(host, '.' + demo[i])) return 'PROXY 127.0.0.1:${PROXY_PORT}';
	}
	return 'DIRECT';
}`

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

mkdirSync(OUT, { recursive: true })
const fixtures = await startFixtures({ httpsPort: HTTPS_PORT, proxyPort: PROXY_PORT, nextcloud: SERVER })

const options = new firefox.Options()
	.addArguments('-headless')
	.setAcceptInsecureCerts(true)
	.setPreference('extensions.webextensions.uuids', JSON.stringify({ [GECKO_ID]: UUID }))
	.setPreference('network.proxy.type', 2)
	.setPreference('network.proxy.autoconfig_url', 'data:text/javascript,' + encodeURIComponent(PAC))

async function shot(driver, name) {
	writeFileSync(join(OUT, name), await driver.takeScreenshot(), 'base64')
	console.log('[capture] screenshot', name)
}

async function visible(driver, css) {
	const el = await driver.wait(until.elementLocated(By.css(css)), 30000)
	await driver.wait(until.elementIsVisible(el), 60000)
	return el
}

let driver
let failed = false
try {
	driver = await new Builder().forBrowser('firefox').setFirefoxOptions(options).build()
	await driver.installAddon(pkg, true)
	await driver.manage().window().setRect({ width: 1024, height: 640 })
	await driver.get('https://webmail.example.com/')
	const siteWindow = await driver.getWindowHandle()

	await driver.switchTo().newWindow('tab')
	await driver.get(POPUP_URL)
	const tabId = await driver.executeAsyncScript(function (done) {
		browser.tabs
			.query({})
			.then((tabs) => done((tabs.find((t) => (t.url || '').startsWith('https://webmail.example.com/')) || {}).id))
	})
	if (tabId === undefined || tabId === null) throw new Error('no tab shows the webmail page')
	await driver.manage().window().setRect({ width: 380, height: 600 })
	await driver.get(`${POPUP_URL}?tabId=${tabId}`)

	await (await visible(driver, '#pair-url')).sendKeys(`https://${CLOUD_HOST}`)
	await driver.findElement(By.css('#pair-user')).sendKeys('admin')
	await driver.findElement(By.css('#pair-app-password')).sendKeys(APP_PASSWORD)
	await driver.findElement(By.css('#pair-submit')).click()
	await (await visible(driver, '#unlock-master')).sendKeys(MASTER)
	await driver.findElement(By.css('#unlock-submit')).click()
	await visible(driver, '#candidates .candidate-fill')
	await sleep(800)
	await shot(driver, 'firefox-this-site.png')

	await driver.findElement(By.css('#candidates .candidate-fill')).click()
	await sleep(1500)
	await driver.switchTo().window(siteWindow)
	await driver.manage().window().setRect({ width: 1024, height: 640 })
	await driver.wait(
		async () => (await driver.findElement(By.css('#password')).getAttribute('value')).length > 0,
		15000,
	)
	await sleep(500)
	await shot(driver, 'firefox-fill.png')
} catch (e) {
	failed = true
	console.error('firefox capture failed:', e.stack || e)
} finally {
	await driver?.quit()
	await fixtures.close()
}
process.exitCode = failed ? 1 : 0
