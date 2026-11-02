/**
 * The passkey provider in real Chromium (spec `passkey-provider`): the test site's relying party registers and signs in
 * through Keepiq's window, the inline list and the browser's own authenticator, a CDP virtual one, and checks every
 * answer with WebCrypto. Saves go to the fake Keepiq of `keepiq-server.ts`.
 */
import { createHash, generateKeyPairSync, randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import type { BrowserContext, Locator, Page } from '@playwright/test'
import type { PasskeyCredential } from '@/src/passkey/credential'
import { envelope } from '@/src/testing/vectors'
import { clickField, expect, extensionWorker, holds, listFrame, listOpen, listText, test } from './fixtures'
import { cachedVault, decrypt, fakeKeepiq } from './keepiq-server'
import { LOGIN } from './seed'

const catalog = readFileSync(new URL('../locales/en.yml', import.meta.url), 'utf8')
const text = (name: string) => new RegExp(`^passkey:\\n(?: .*\\n)*? {2}${name}: "?(.+?)"?$`, 'm').exec(catalog)![1]!
const FALLBACK = text('fallback')
const ZERO_AAGUID = '0'.repeat(32)

/** A passkey for the test site's `alice` and the record its relying party keeps of it, as if registered before. */
function passkeyFor(counter: number) {
	const { privateKey, publicKey } = generateKeyPairSync('ec', { namedCurve: 'prime256v1' })
	const credentialId = randomBytes(16).toString('base64url')
	// As `test-site/passkey.js` derives it.
	const userHandle = createHash('sha256').update('keepiq-test-site:alice').digest().subarray(0, 16).toString('base64url')
	const credential: PasskeyCredential = {
		credentialId, rpId: 'localhost', rpName: 'Keepiq test site', userName: 'alice', userDisplayName: 'alice', userHandle,
		privateKey: privateKey.export({ format: 'der', type: 'pkcs8' }).toString('base64url'), algorithm: -7, counter, transports: ['internal'],
		createdAt: '2026-10-01T10:00:00.000Z',
	}
	const registered = { id: credentialId, jwk: publicKey.export({ format: 'jwk' }), counter, userName: 'alice', userHandle, aaguid: ZERO_AAGUID }
	return { credential, registered }
}

const result = (frame: Page | ReturnType<Page['frameLocator']>) => frame.locator('#result')

const passkeyWindows = (context: BrowserContext) => context.pages().filter((page) => page.url().includes('/popup.html?passkey='))

/** The window `act` makes the page open. */
async function windowFor(context: BrowserContext, act: () => Promise<void>): Promise<Page> {
	await act()
	await expect.poll(() => passkeyWindows(context).length).toBe(1)
	const window = passkeyWindows(context)[0]!
	await window.waitForLoadState()
	return window
}

/** A press within 500 ms of the window or its step showing is ignored, so this presses until `done`. */
async function press(button: Locator, done: () => Promise<boolean> | boolean): Promise<void> {
	await expect(async () => {
		if (!(await done())) await button.click({ timeout: 1000 })
		await expect.poll(done, { timeout: 1000 }).toBe(true)
	}).toPass()
}

const settled = (page: Page) => async () => await result(page).getAttribute('data-result') !== null

/** The relying party knows the passkeys of `registered`, as after a registration on an earlier visit. */
async function siteKnows(page: Page, registered: object[]): Promise<void> {
	await page.goto('/passkey.html')
	await page.evaluate((list) => localStorage.setItem('keepiq-test-site.passkeys', JSON.stringify(list)), registered)
	await page.reload()
}

const decryptedPasskeys = async (ciphertext: unknown) => JSON.parse(await decrypt(ciphertext)) as PasskeyCredential[]

async function virtualAuthenticator(page: Page) {
	const cdp = await page.context().newCDPSession(page)
	await cdp.send('WebAuthn.enable', { enableUI: false })
	const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
		options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
	})
	return { credentialIds: async () => (await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.map((credential) => Buffer.from(credential.credentialId, 'base64').toString('base64url')) }
}

/** Everything the extension keeps on disk and in the session, as one string. */
const storedText = async (context: BrowserContext) => (await extensionWorker(context)).evaluate(async () => {
	const { storage } = (globalThis as unknown as { chrome: { storage: Record<'local' | 'session', { get(): Promise<object> }> } }).chrome
	return JSON.stringify([await storage.local.get(), await storage.session.get()])
})

test('Save as new login: the site verifies the attestation, and a later sign-in with the saved passkey', async ({ context, page, baseURL }) => {
	const keepiq = await fakeKeepiq(context)
	await page.goto('/passkey.html')
	const window = await windowFor(context, () => page.click('#register'))
	await expect(window.getByRole('heading', { name: text('saveTitle') })).toBeVisible()
	await expect(window.getByRole('button', { name: `Test site, ${LOGIN.username}` })).toBeVisible()
	await expect(window.getByLabel(text('name'))).toHaveCount(0)
	await press(window.getByRole('button', { name: text('newLogin') }), () => window.getByLabel(text('name')).isVisible())
	await window.getByLabel(text('name')).fill('Example')
	await press(window.getByRole('button', { name: text('saveNew') }), settled(page))
	await expect(result(page)).toHaveAttribute('data-result', 'registered')
	await expect(result(page)).toHaveAttribute('data-aaguid', ZERO_AAGUID)
	await expect(result(page)).toHaveAttribute('data-flags', 'UP BE BS AT')
	await expect.poll(() => window.isClosed()).toBe(true)

	const created = keepiq.requests.filter((request) => request.method === 'POST' && request.path === '/api/v1/secrets')
	expect(created).toHaveLength(1)
	const body = created[0]!.body!
	expect(body).toMatchObject({ name: 'Example', url: new URL(baseURL!).origin, typeId: 't-login', folderId: null })
	expect(body).not.toHaveProperty('login')
	expect(await decrypt(body.key)).toBe('')
	const [saved, ...more] = await decryptedPasskeys(body.passkeys)
	expect(more).toEqual([])
	const registered = await page.evaluate(() => JSON.parse(localStorage.getItem('keepiq-test-site.passkeys')!)[0])
	expect(saved).toMatchObject({ credentialId: registered.id, rpId: 'localhost', rpName: 'Keepiq test site', userName: 'alice', userHandle: registered.userHandle, algorithm: -7, counter: 0 })

	// The sync after the save brings the new login into the vault.
	await expect.poll(async () => (await cachedVault(context))?.secrets.map((row) => row.name)).toContain('Example')
	const signIn = await windowFor(context, () => page.click('#sign-in'))
	await press(signIn.getByRole('button', { name: 'Example, alice' }), async () => await result(page).getAttribute('data-result') === 'signed-in')
	await expect(result(page)).toHaveAttribute('data-counter', '0')
	expect(keepiq.requests.filter((request) => request.method === 'PUT')).toEqual([])
})

test('Save to an existing login: only its passkeys change', async ({ context, page }) => {
	const keepiq = await fakeKeepiq(context)
	const before = structuredClone(keepiq.rows[0]!)
	await page.goto('/passkey.html')
	const window = await windowFor(context, () => page.click('#register'))
	await press(window.getByRole('button', { name: `Test site, ${LOGIN.username}` }), settled(page))
	await expect(result(page)).toHaveAttribute('data-result', 'registered')
	await expect.poll(() => window.isClosed()).toBe(true)

	const saves = keepiq.requests.filter((request) => request.method === 'PUT')
	expect(saves.map((request) => request.path)).toEqual(['/api/v1/secrets/s1/share-save'])
	expect(Object.keys(saves[0]!.body!.patch as object)).toEqual(['passkeys'])
	const after = keepiq.rows[0]!
	expect(before.passkeys).toBeUndefined()
	expect({ ...after, passkeys: undefined, updatedAt: before.updatedAt }).toEqual(before)
	const registered = await page.evaluate(() => JSON.parse(localStorage.getItem('keepiq-test-site.passkeys')!)[0])
	expect((await decryptedPasskeys(after.passkeys)).map((credential) => credential.credentialId)).toEqual([registered.id])
	expect(after.updatedAt).not.toBe(before.updatedAt)
})

test.describe('with a passkey on the login', () => {
	const { credential, registered } = passkeyFor(0)
	test.use({ passkeys: [credential] })

	test('Sign in: the site verifies the assertion, nothing is written, and no key or challenge stays in storage', async ({ context, page }) => {
		const keepiq = await fakeKeepiq(context)
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await expect(window.getByRole('heading', { name: text('signInTitle') })).toBeVisible()
		expect(await window.content()).not.toContain(credential.privateKey)
		await press(window.getByRole('button', { name: 'Test site, alice' }), settled(page))
		await expect(result(page)).toHaveAttribute('data-result', 'signed-in')
		await expect(result(page)).toHaveAttribute('data-counter', '0')
		await expect(result(page)).toHaveAttribute('data-flags', 'UP BE BS')
		await expect.poll(() => window.isClosed()).toBe(true)
		expect(keepiq.requests.filter((request) => request.method !== 'GET')).toEqual([])
		const stored = await storedText(context)
		expect(stored).not.toContain(credential.privateKey)
		expect(stored).not.toContain('passkeyCeremony.')
	})

	test('Cancel rejects the page\'s call with NotAllowedError and closes the window', async ({ context, page }) => {
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await window.getByRole('button', { name: 'Cancel' }).click()
		await expect(result(page)).toHaveAttribute('data-error', 'NotAllowedError')
		await expect.poll(() => window.isClosed()).toBe(true)
	})

	test('closing the window rejects the page\'s call with NotAllowedError', async ({ context, page }) => {
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await window.close()
		await expect(result(page)).toHaveAttribute('data-error', 'NotAllowedError')
	})

	test('the page aborting rejects with its reason and closes the window', async ({ context, page }) => {
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await page.click('#abort')
		await expect(result(page)).toHaveAttribute('data-error', 'AbortError')
		await expect.poll(() => window.isClosed()).toBe(true)
	})

	test('the inline list offers the passkey during a conditional request and signs with it', async ({ page }) => {
		await siteKnows(page, [registered])
		await page.goto('/passkey-conditional.html')
		await expect(result(page)).toHaveAttribute('data-result', 'waiting')
		await clickField(page, page.locator('input[name=username]'))
		await expect.poll(() => listText(page)).toContain('Test site alice')
		const row = () => listFrame(page)!.getByRole('option', { name: 'Test site, alice, passkey' })
		// The page only leaves once its relying party verified the assertion; a failure stays on it.
		await expect(async () => {
			if (page.url().includes('/passkey-conditional.html')) {
				expect(await result(page).getAttribute('data-result', { timeout: 1000 })).toBe('waiting')
				const box = (await row().boundingBox({ timeout: 1000 }))!
				await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
			}
			await expect(page).toHaveURL(/\/signed-in\.html\?from=%2Fpasskey-conditional&passkey=alice$/, { timeout: 1000 })
		}).toPass()
		await expect(page.locator('#from')).toHaveText('You signed in with a passkey as alice. Came from /passkey-conditional.')
		expect(await page.evaluate(() => JSON.parse(localStorage.getItem('keepiq-test-site.passkeys')!))).toEqual([registered])
		await expect.poll(() => listOpen(page)).toBe(false)
	})

	test('without a conditional request the inline list shows the login only', async ({ page }) => {
		await page.goto('/login.html')
		await clickField(page, page.locator('input[name=username]'))
		await expect.poll(async () => await listFrame(page)?.getByRole('option').count() ?? 0).toBe(1)
		await expect(listFrame(page)!.getByRole('option')).toHaveAccessibleName(`Test site, ${LOGIN.username}`)
	})
})

test.describe('with an imported passkey whose counter is 41', () => {
	const { credential, registered } = passkeyFor(41)
	test.use({ passkeys: [credential] })

	test('the login is saved with counter 42 before the page gets an assertion with 42', async ({ context, page }) => {
		const keepiq = await fakeKeepiq(context)
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await press(window.getByRole('button', { name: 'Test site, alice' }), settled(page))
		await expect(result(page)).toHaveAttribute('data-result', 'signed-in')
		await expect(result(page)).toHaveAttribute('data-counter', '42')
		const saves = keepiq.requests.filter((request) => request.method === 'PUT')
		expect(saves).toHaveLength(1)
		const [saved] = await decryptedPasskeys((saves[0]!.body!.patch as { passkeys: string }).passkeys)
		expect(saved).toEqual({ ...credential, counter: 42 })
	})
})

test.describe('locked, with a passkey on the login', () => {
	const { credential, registered } = passkeyFor(0)
	test.use({ vault: 'locked', passkeys: [credential] })

	test('the window asks to unlock and shows no passkey, then lists it after the unlock', async ({ context, page }) => {
		await fakeKeepiq(context)
		await siteKnows(page, [registered])
		const window = await windowFor(context, () => page.click('#sign-in'))
		await expect(window.getByText(text('unlockGet').replace('{rpId}', 'localhost'))).toBeVisible()
		const shown = await window.locator('body').innerText()
		expect(shown).not.toContain('alice')
		expect(shown).not.toContain('Test site')
		await window.getByRole('textbox', { name: 'Master password' }).fill(envelope.password)
		await press(window.getByRole('button', { name: 'Unlock' }), () => window.getByRole('button', { name: 'Test site, alice' }).isVisible())
		await press(window.getByRole('button', { name: 'Test site, alice' }), settled(page))
		await expect(result(page)).toHaveAttribute('data-result', 'signed-in')
		// Unlocking in the ceremony is user verification.
		await expect(result(page)).toHaveAttribute('data-flags', 'UP UV BE BS')
	})

	test('the inline list during a conditional request reads locked and shows no passkey', async ({ page }) => {
		await siteKnows(page, [registered])
		await page.goto('/passkey-conditional.html')
		await expect(result(page)).toHaveAttribute('data-result', 'waiting')
		await clickField(page, page.locator('input[name=username]'))
		await expect.poll(() => listText(page)).toContain('Unlock')
		expect(await listText(page)).not.toContain('alice')
		await expect(listFrame(page)!.getByRole('option')).toHaveCount(0)
	})
})

test.describe('the browser\'s own authenticator', () => {
	test('"Use your device or hardware key" hands the registration and the sign-in to it', async ({ context, page }) => {
		await page.goto('/passkey.html')
		const authenticator = await virtualAuthenticator(page)
		const save = await windowFor(context, () => page.click('#register'))
		await press(save.getByRole('button', { name: FALLBACK }), settled(page))
		await expect(result(page)).toHaveAttribute('data-result', 'registered')
		await expect.poll(() => save.isClosed()).toBe(true)
		const registered = await page.evaluate(() => JSON.parse(localStorage.getItem('keepiq-test-site.passkeys')!)[0])
		expect(await authenticator.credentialIds()).toEqual([registered.id])

		const signIn = await windowFor(context, () => page.click('#sign-in'))
		await expect(signIn.getByText(text('noPasskey').replace('{rpId}', 'localhost'))).toBeVisible()
		await press(signIn.getByRole('button', { name: FALLBACK }), async () => await result(page).getAttribute('data-result') === 'signed-in')
		await expect.poll(() => signIn.isClosed()).toBe(true)
	})

	test('a frame with a cross-origin ancestor goes to it, and no Keepiq window opens', async ({ context, page }) => {
		await page.goto('/passkey-iframe.html')
		const authenticator = await virtualAuthenticator(page)
		const frame = page.frameLocator('#frame')
		await frame.locator('#register').click()
		await expect(result(frame)).toHaveAttribute('data-result', 'registered')
		await holds(() => passkeyWindows(context).length === 0, 1000)
		const registered = await frame.locator('body').evaluate(() => JSON.parse(localStorage.getItem('keepiq-test-site.passkeys')!)[0])
		expect(await authenticator.credentialIds()).toEqual([registered.id])
		await frame.locator('#sign-in').click()
		await expect(result(frame)).toHaveAttribute('data-result', 'signed-in')
		expect(passkeyWindows(context)).toEqual([])
	})

	test('with "Ask to save and use passkeys" off, a registration goes to it and no Keepiq window opens', async ({ context, page }) => {
		await (await extensionWorker(context)).evaluate(async () => {
			const { storage } = (globalThis as unknown as { chrome: { storage: { local: { set(items: object): Promise<void> } } } }).chrome
			await storage.local.set({ 'settings.e2e': { askPasskeys: false } })
		})
		await page.goto('/passkey.html')
		const authenticator = await virtualAuthenticator(page)
		await page.click('#register')
		await expect(result(page)).toHaveAttribute('data-result', 'registered')
		expect(passkeyWindows(context)).toEqual([])
		expect(await authenticator.credentialIds()).toHaveLength(1)
	})
})
