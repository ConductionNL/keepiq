/**
 * Capture the screenshots and videos of the Chromium package for the user
 * documentation (docs/browser-extension/using.md).
 *
 * Needs the capture Nextcloud from compose.yaml + setup.sh, and the built
 * package (npm run build:extension). Writes PNG screenshots and webm videos
 * to browser-extension/capture/out/media/.
 *
 *   node browser-extension/capture/chromium.mjs
 *
 * KEEPIQ_CAPTURE_SERVER is the Nextcloud (http://localhost:8188 by default).
 * The extension pairs with it as https://cloud.example.com through the demo
 * server in fixtures.mjs, and every website it visits is a local demo page.
 *
 * The popup runs as an extension page in a tab, pinned to the site tab with
 * ?tabId=, as a popped-out popup is. That is the same page the toolbar opens,
 * and Playwright can record it. The vault is filled with demo items through
 * the extension itself, so every value is encrypted as a user's would be.
 */
import { chromium } from '@playwright/test'
import { mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CLOUD_HOST, DEMO_DOMAINS, PASSKEY_HOST, startFixtures } from './fixtures.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const pkg = resolve(process.argv[2] || join(here, '..', 'dist', 'chromium'))
const OUT = join(here, 'out', 'media')
const SERVER = process.env.KEEPIQ_CAPTURE_SERVER || 'http://localhost:8188'
const HTTPS_PORT = Number(process.env.KEEPIQ_CAPTURE_HTTPS_PORT || 8443)
const PROXY_PORT = Number(process.env.KEEPIQ_CAPTURE_PROXY_PORT || 8444)
const APP_PASSWORD = readFileSync(join(here, 'out', 'app-password'), 'utf8').trim()
const MASTER = process.env.KEEPIQ_CAPTURE_MASTER || 'Oj'
const POPUP = { width: 380, height: 600 }
const SITE = { width: 1024, height: 640 }

/** The demo items, created through the extension after the first unlock. */
const DEMO_ITEMS = [
	{ name: 'Webmail (demo)', url: 'https://webmail.example.com', login: 'anna.demo@example.com', folder: 'Personal' },
	{ name: 'Bank (demo)', url: 'https://bank.example.net', login: '40817265', folder: 'Personal' },
	{ name: 'Intranet (demo)', url: 'https://intranet.example.org', login: 'anna.demo', folder: 'Work' },
	{ name: 'Project board (demo)', url: 'https://board.example.org', login: 'anna.demo@example.com', folder: 'Work' },
	{ name: 'Router admin (demo)', url: 'https://router.example', login: 'admin', folder: null },
]

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const log = (...args) => console.log('[capture]', ...args)

mkdirSync(OUT, { recursive: true })
const profile = mkdtempSync(join(tmpdir(), 'keepiq-capture-'))
const videoTmp = mkdtempSync(join(tmpdir(), 'keepiq-capture-video-'))
const fixtures = await startFixtures({ httpsPort: HTTPS_PORT, proxyPort: PROXY_PORT, nextcloud: SERVER })
// The browser that is running, closed on failure too.
let open = null

/**
 * Start Chromium with the package, recording video at `size`. The profile is
 * kept between sessions, so the account stays paired; the vault locks when
 * the browser closes, as it does for a user.
 *
 * @param {{width: number, height: number}} size The video and viewport size.
 */
async function launch(size) {
	const context = await chromium.launchPersistentContext(profile, {
		channel: 'chromium',
		headless: true,
		viewport: size,
		ignoreHTTPSErrors: true,
		recordVideo: { dir: videoTmp, size },
		args: [
			`--disable-extensions-except=${pkg}`,
			`--load-extension=${pkg}`,
			`--host-resolver-rules=${DEMO_DOMAINS.map((d) => `MAP *.${d} 127.0.0.1:${HTTPS_PORT}`).join(',')}`,
			'--ignore-certificate-errors',
		],
	})
	open = context
	const worker =
		context.serviceWorkers()[0]
		|| (await context.waitForEvent('serviceworker', { timeout: 15000 }))
	return { context, worker, extId: new URL(worker.url()).host }
}

/** The browser's id of the tab showing `url`. */
async function tabIdOf(worker, url) {
	const id = await worker.evaluate(async (u) => {
		const tabs = await chrome.tabs.query({})
		return tabs.find((t) => t.url && t.url.startsWith(u))?.id
	}, url)
	if (id === undefined) throw new Error('no tab shows ' + url)
	return id
}

/** Open the popup page, pinned to the site tab `tabId`. */
async function openPopup(session, tabId) {
	const popup = await session.context.newPage()
	await popup.setViewportSize(POPUP)
	await popup.goto(`chrome-extension://${session.extId}/popup.html?tabId=${tabId}`)
	return popup
}

/** Close a page and keep its video as `name`, or drop it without a name. */
async function closePage(page, name) {
	const video = page.video()
	await page.close()
	if (video && name) {
		await video.saveAs(join(OUT, name))
		log('video', name)
	}
	await video?.delete().catch(() => {})
}

async function shot(page, name, options = {}) {
	await page.screenshot({ path: join(OUT, name), ...options })
	log('screenshot', name)
}

/** Ask the worker from an extension page. */
function ask(page, type, payload) {
	return page.evaluate(
		([t, p]) => chrome.runtime.sendMessage({ type: t, payload: p }),
		[type, payload],
	)
}

async function unlock(popup, { slow = false } = {}) {
	await popup.waitForSelector('#view-locked:not([hidden])', { timeout: 30000 })
	if (slow) await popup.type('#unlock-master', MASTER, { delay: 120 })
	else await popup.fill('#unlock-master', MASTER)
	return async () => {
		await popup.click('#unlock-submit')
		await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 60000 })
	}
}

/** A random demo password. */
function demoPassword() {
	const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!#%+=?'
	const bytes = new Uint8Array(20)
	globalThis.crypto.getRandomValues(bytes)
	return [...bytes].map((b) => chars[b % chars.length]).join('')
}

let failed = false
try {
	// ── Session 1: the popup, recorded at popup size ─────────────────────
	let session = await launch(POPUP)
	const webmail = await session.context.newPage()
	await webmail.goto('https://webmail.example.com/')
	const webmailTab = await tabIdOf(session.worker, 'https://webmail.example.com/')

	// Pair and unlock.
	let popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#view-pair:not([hidden])')
	await sleep(600)
	await popup.type('#pair-url', `https://${CLOUD_HOST}`, { delay: 40 })
	await popup.type('#pair-user', 'admin', { delay: 60 })
	await popup.fill('#pair-app-password', APP_PASSWORD)
	await shot(popup, 'pair.png')
	await popup.click('#pair-submit')
	const submitUnlock = await unlock(popup, { slow: true })
	await shot(popup, 'unlock.png')
	await submitUnlock()
	await sleep(800)
	await closePage(popup, 'pair-and-unlock.webm')

	// The demo items, through the extension's own save.
	popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 30000 })
	const index = await ask(popup, 'vault-list')
	if (index?.error) throw new Error('vault-list: ' + index.error)
	// A second run starts from an empty vault again.
	for (const item of index.items || []) {
		const trashed = await ask(popup, 'vault-trash', { id: item.id })
		if (!trashed?.ok) throw new Error('trashing ' + item.id + ': ' + JSON.stringify(trashed))
	}
	const sends = await ask(popup, 'send-list')
	for (const send of sends?.sends || []) {
		const ended = await ask(popup, 'send-revoke', { id: send.id })
		if (!ended?.ok) throw new Error('ending send ' + send.id + ': ' + JSON.stringify(ended))
	}
	const loginType = index.types.find((t) => t.name === 'login')
	if (!loginType) throw new Error('the server has no login type')
	for (const item of DEMO_ITEMS) {
		const folder = item.folder ? index.folders.find((f) => f.name === item.folder) : null
		if (item.folder && !folder) throw new Error('no folder ' + item.folder)
		const saved = await ask(popup, 'vault-save', {
			typeId: loginType.id,
			typeName: 'login',
			changes: {
				name: item.name,
				url: item.url,
				login: item.login,
				key: demoPassword(),
				folderId: folder ? folder.id : null,
			},
		})
		if (!saved?.ok) throw new Error('saving ' + item.name + ': ' + JSON.stringify(saved))
	}
	log('demo items', DEMO_ITEMS.length)
	await closePage(popup)

	// This site: the logins for the open page.
	popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#candidates .candidate-fill', { timeout: 30000 })
	await sleep(400)
	await shot(popup, 'this-site.png')
	await closePage(popup)

	// Vault: browse, search, open an item.
	popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 30000 })
	await sleep(500)
	await popup.click('#tab-vault')
	await popup.waitForFunction(
		() => document.querySelectorAll('#vault-list button:not([disabled])').length >= 5,
		null,
		{ timeout: 30000 },
	)
	await sleep(800)
	await shot(popup, 'vault.png')
	await popup.type('#vault-search', 'bank', { delay: 120 })
	await sleep(800)
	await popup.click('#vault-list button:not([disabled])')
	await popup.waitForSelector('#vault-detail:not([hidden])', { timeout: 30000 })
	await sleep(600)
	await popup.click('#detail-reveal').catch(() => {})
	await sleep(1200)
	await shot(popup, 'vault-item.png')
	await closePage(popup, 'vault.webm')

	// Generator: a password, then a passphrase.
	popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 30000 })
	await sleep(500)
	await popup.click('#tab-generator')
	await popup.waitForFunction(() => document.getElementById('gen-output').textContent.length > 0)
	await sleep(800)
	await shot(popup, 'generator.png')
	await popup.click('#gen-regenerate')
	await sleep(900)
	await popup.click('#gen-tab-passphrase')
	await popup.waitForFunction(() => document.getElementById('gen-output').textContent.includes('-'))
	await sleep(1200)
	await shot(popup, 'generator-passphrase.png')
	await closePage(popup, 'generator.webm')

	// Send: share a short text through a link that expires.
	popup = await openPopup(session, webmailTab)
	await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 30000 })
	await sleep(500)
	await popup.click('#tab-send')
	await popup.waitForSelector('#send-text', { state: 'visible' })
	await popup.type('#send-text', 'The door code for the demo office is 4711.', { delay: 35 })
	await popup.selectOption('#send-expiry', '1h').catch(() => {})
	await sleep(400)
	await shot(popup, 'send-form.png')
	await popup.click('#send-create')
	await popup.waitForSelector('#send-result:not([hidden])', { timeout: 30000 })
	await popup.$eval('#send-result', (el) => el.scrollIntoView({ block: 'start' }))
	await sleep(1500)
	await shot(popup, 'send-link.png')
	await closePage(popup, 'send.webm')

	await closePage(webmail)
	await session.context.close()

	// ── Session 2: the websites, recorded at page size ───────────────────
	session = await launch(SITE)
	const unlockTab = await session.context.newPage()
	await unlockTab.goto('https://webmail.example.com/')
	popup = await openPopup(session, await tabIdOf(session.worker, 'https://webmail.example.com/'))
	await (await unlock(popup))()
	await closePage(popup)
	await closePage(unlockTab)

	// Fill a login from the popup.
	const login = await session.context.newPage()
	await login.goto('https://webmail.example.com/')
	await sleep(1200)
	popup = await openPopup(session, await tabIdOf(session.worker, 'https://webmail.example.com/'))
	await popup.waitForSelector('#candidates .candidate-fill', { timeout: 30000 })
	await shot(popup, 'fill-popup.png')
	await popup.click('#candidates .candidate-fill')
	await login.bringToFront()
	await login.waitForFunction(() => document.getElementById('password').value.length > 0, null, { timeout: 15000 })
	await sleep(1500)
	await shot(login, 'fill-filled.png')
	if (!popup.isClosed()) await closePage(popup)
	await closePage(login, 'fill.webm')

	// Sign in with a login Keepiq does not know: the save bar.
	const forum = await session.context.newPage()
	await forum.goto('https://forum.example/')
	await sleep(1000)
	await forum.click('#user')
	await forum.keyboard.type('anna_demo', { delay: 90 })
	await forum.click('#password')
	await forum.keyboard.type('Demo-forum-2026!', { delay: 90 })
	await sleep(500)
	await forum.click('button[type="submit"]')
	await forum.waitForURL('**/signed-in', { timeout: 15000 })
	await forum.waitForSelector('#keepiq-save-prompt', { state: 'attached', timeout: 15000 })
	await sleep(1200)
	await shot(forum, 'save-prompt.png')
	// The main button has the focus; Enter is a user's key press.
	await forum.keyboard.press('Enter')
	await sleep(2500)
	await shot(forum, 'save-done.png')
	await sleep(1000)
	popup = await openPopup(session, await tabIdOf(session.worker, 'https://forum.example/'))
	await popup.waitForSelector('#view-unlocked:not([hidden])', { timeout: 30000 })
	const saved = await ask(popup, 'vault-list')
	await closePage(popup)
	await closePage(forum, 'save-prompt.webm')
	if (!saved.items?.some((i) => (i.url || '').includes('forum.example'))) {
		throw new Error('the save bar did not save the forum login')
	}

	// Create a passkey, then sign in with it.
	const pk = await session.context.newPage()
	await pk.goto(`https://${PASSKEY_HOST}/`)
	await sleep(1000)
	for (const [button, consentShot, resultShot, expect] of [
		['#create', 'passkey-consent.png', 'passkey-created.png', 'Passkey created'],
		['#signin', 'passkey-signin-consent.png', 'passkey-signed-in.png', 'Signed in'],
	]) {
		const consentOpens = session.context.waitForEvent('page', {
			predicate: (p) => p.url().includes('consent.html'),
			timeout: 20000,
		})
		await pk.click(button)
		const consent = await consentOpens
		await consent.setViewportSize({ width: 380, height: 260 })
		await consent.waitForSelector('#allow')
		await sleep(800)
		await shot(consent, consentShot)
		await consent.click('#allow')
		await pk.bringToFront()
		await pk.waitForFunction((t) => document.getElementById('result').textContent.includes(t), expect, {
			timeout: 30000,
		})
		await sleep(1500)
		await shot(pk, resultShot)
		if (!consent.isClosed()) await closePage(consent)
		else await consent.video()?.delete().catch(() => {})
	}
	await closePage(pk, 'passkey.webm')
	await session.context.close()
} catch (e) {
	failed = true
	console.error('capture failed:', e.stack || e)
} finally {
	await open?.close().catch(() => {})
	await fixtures.close()
	rmSync(profile, { recursive: true, force: true })
	rmSync(videoTmp, { recursive: true, force: true })
}
process.exitCode = failed ? 1 : 0
