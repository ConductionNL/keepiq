/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow: the browser extension fills the one-time code on the step
 * after the login (clients-extension-store-release task 5.4, keepiq#783).
 *
 * A throwaway vault owner holds a login and a TOTP secret for one site. The
 * test loads the UNPACKED Chromium build of the extension, pairs it with the
 * instance and unlocks it through the real action popup, then fills the
 * login on step one of a two-step test page. Step two shows only a code
 * field; the extension fills it with the code of the vault's TOTP secret,
 * which the test computes independently with the web app's own TOTP module.
 *
 * The two-step site is served by `context.route()` on a name that resolves
 * nowhere (`login.twostep.test`), so no outside network is touched. The popup
 * is the real one, opened with `chrome.action.openPopup()` over the page and
 * reached over the DevTools protocol, as browser-extension/load-check does:
 * the worker answers its own pages only, so a popup opened as a tab would be
 * refused.
 *
 * @spec openspec/changes/clients-extension-store-release/specs/extension-totp-autofill/spec.md#requirement-one-time-code-fill-on-the-step-after-the-login
 */
import type { BrowserContext, Page, Worker } from '@playwright/test'

import {
	chromium,
	expect,
	request as playwrightRequest,
	test,
} from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import * as path from 'path'
import { generateTotp, parseOtpauth } from '../../../src/totp/totp.js'
import { withStore } from './_vault-actions.ts'
import {
	adminApi,
	api,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
} from './_vault-users.ts'
import { gotoVaultRoute } from './_workflow-helpers.ts'

const SITE = 'https://login.twostep.test'
const SEED = 'otpauth://totp/TwoStep:erin?secret=JBSWY3DPEHPK3PXP&issuer=TwoStep'
const APP_ROOT = path.resolve(__dirname, '..', '..', '..')

const STEP_ONE = `<!doctype html><html><head><title>Sign in</title></head><body>
<form id="login" action="/code" method="get">
<label>User <input id="username" name="username" type="text" autocomplete="username"></label>
<label>Password <input id="password" name="password" type="password" autocomplete="current-password"></label>
<button id="next" type="submit">Next</button>
</form></body></html>`

const STEP_TWO = `<!doctype html><html><head><title>Code</title></head><body>
<form><label>Code <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"></label>
<button type="submit">Verify</button></form></body></html>`

/**
 * Open the real action popup over the active page and reach it over CDP.
 *
 * @param context The persistent context that loaded the extension.
 * @param worker The extension's service worker.
 * @param profile The profile directory (holds DevToolsActivePort).
 * @return The popup page and the CDP browser to close afterwards.
 */
async function openPopup(
	context: BrowserContext,
	worker: Worker,
	profile: string,
): Promise<{ popup: Page; close: () => Promise<void> }> {
	await worker.evaluate(() =>
		(
			globalThis as unknown as {
				chrome: { action: { openPopup: () => Promise<void> } }
			}
		).chrome.action.openPopup(),
	)
	const port = readFileSync(
		path.join(profile, 'DevToolsActivePort'),
		'utf8',
	).split('\n')[0]
	for (let i = 0; i < 40; i++) {
		const cdp = await chromium.connectOverCDP(`http://127.0.0.1:${port}`)
		const popup = cdp
			.contexts()
			.flatMap((c) => c.pages())
			.find((p) => p.url().endsWith('/popup.html'))
		if (popup) {
			return { popup, close: () => cdp.close() }
		}
		await cdp.close()
		await new Promise((resolve) => setTimeout(resolve, 250))
	}
	throw new Error('the action popup did not open')
}

/**
 * The code the TOTP seed gives now, and the one before it (a fill made just
 * before a period boundary still shows the earlier code).
 *
 * @return Both codes.
 */
async function expectedCodes(): Promise<string[]> {
	const params = parseOtpauth(SEED)
	const now = Date.now()
	return [
		await generateTotp(params, now),
		await generateTotp(params, now - 30_000),
	]
}

test.describe('extension: one-time code on the next step', () => {
	test('the code field on step two is filled after a login fill', async ({
		browser,
		baseURL,
	}) => {
		test.setTimeout(300_000)
		const admin = await adminApi(baseURL)
		const user = newVaultUser('erin')
		await createUsers(admin, [user])
		const profile = mkdtempSync(path.join(tmpdir(), 'keepiq-ext-e2e-'))
		const outdir = mkdtempSync(path.join(tmpdir(), 'keepiq-ext-build-'))
		let extension: BrowserContext | null = null

		try {
			// The vault: a login and a TOTP secret for the site, encrypted by the web app.
			const web = await signedInContext(browser, user)
			await setUpVault(web.page, user)
			await gotoVaultRoute(web.page, 'secrets')
			await expect(web.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			const types = (await api(web.page, 'GET', '/secret-types'))
				.body as Array<{ id: string; name: string }>
			const typeId = (name: string) => types.find((t) => t.name === name)?.id
			for (const secret of [
				{
					name: 'Two-step portal',
					url: SITE,
					login: 'erin',
					key: 'Two-step-password-1',
					typeId: typeId('login'),
				},
				{
					name: 'Two-step portal code',
					url: SITE,
					key: SEED,
					typeId: typeId('totp'),
				},
			]) {
				await withStore(
					web.page,
					'secret',
					'async (store, data) => (await store.createSecret(data)).id',
					secret,
				)
			}
			await web.context.close()

			// An app password for pairing, as a person would create one.
			const userApi = await playwrightRequest.newContext({
				baseURL,
				storageState: { cookies: [], origins: [] },
				httpCredentials: {
					username: user.uid,
					password: user.password,
					send: 'always',
				},
				extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
			})
			const appPassword = await userApi.get(
				'/ocs/v2.php/core/getapppassword?format=json',
			)
			expect(appPassword.status()).toBe(200)
			const pairingSecret = (await appPassword.json()).ocs.data
				.apppassword as string
			await userApi.dispose()

			// The unpacked Chromium build, loaded into its own profile.
			execFileSync(
				process.execPath,
				[
					path.join(APP_ROOT, 'browser-extension', 'build.mjs'),
					'--target',
					'chrome',
					'--outdir',
					outdir,
				],
				{ cwd: APP_ROOT },
			)
			const unpacked = path.join(outdir, 'chromium')
			extension = await chromium.launchPersistentContext(profile, {
				channel: 'chromium',
				headless: true,
				args: [
					`--disable-extensions-except=${unpacked}`,
					`--load-extension=${unpacked}`,
					'--remote-debugging-port=0',
				],
			})
			await extension.route(`${SITE}/**`, async (route) => {
				const url = new URL(route.request().url())
				await route.fulfill({
					contentType: 'text/html',
					body: url.pathname.startsWith('/code') ? STEP_TWO : STEP_ONE,
				})
			})
			const worker =
				extension.serviceWorkers()[0]
				?? (await extension.waitForEvent('serviceworker', {
					timeout: 15_000,
				}))

			const page = extension.pages()[0] ?? (await extension.newPage())
			await page.goto(`${SITE}/`)
			await expect(page.locator('#username')).toBeVisible()

			// Pair and unlock through the popup.
			const { popup, close } = await openPopup(extension, worker, profile)
			await popup.fill('#pair-url', baseURL ?? '')
			await popup.fill('#pair-user', user.uid)
			await popup.fill('#pair-app-password', pairingSecret)
			await popup.click('#pair-submit')
			await expect(
				popup.locator('#view-locked, #pair-error:not([hidden])').first(),
			).toBeVisible({ timeout: 30_000 })
			expect(
				(await popup.locator('#pair-error').isVisible())
					? await popup.locator('#pair-error').textContent()
					: '',
				'pairing error',
			).toBe('')
			await expect(popup.locator('#view-locked')).toBeVisible()
			await popup.fill('#unlock-master', user.masterPassword)
			await popup.click('#unlock-submit')
			await expect(popup.locator('#view-unlocked')).toBeVisible({
				timeout: 60_000,
			})

			// Fill the login. Step one has no code field, so the extension
			// remembers, for this tab and site, that a code comes next.
			const fill = popup
				.locator('.candidate-fill', { hasText: 'Two-step portal — ' })
				.filter({ hasNotText: 'code' })
			await expect(fill.first()).toBeVisible({ timeout: 30_000 })
			await fill.first().click()
			await close()
			await expect(page.locator('#username')).toHaveValue('erin', {
				timeout: 15_000,
			})
			await expect(page.locator('#password')).toHaveValue(
				'Two-step-password-1',
			)

			// Wait for the worker to finish the fill and store the intent for
			// step two. Without this wait the worker's own "fill the code on this
			// page" message can land on step two after the click, which would
			// fill the field without the next-step path ever running.
			const intentsKey = 'keepiq.otpIntents'
			await expect
				.poll(
					async () =>
						worker.evaluate(async (key) => {
							const session = (
								globalThis as unknown as {
									chrome: {
										storage: {
											session: {
												get: (
													k: string,
												) => Promise<Record<string, unknown>>
											}
										}
									}
								}
							).chrome.storage.session
							return Object.values(
								((await session.get(key))[key] ?? {}) as Record<
									string,
									Record<string, unknown>
								>,
							)
						}, intentsKey),
					{ timeout: 30_000 },
				)
				.toHaveLength(1)
			const [intent] = await worker.evaluate(async (key) => {
				const session = (
					globalThis as unknown as {
						chrome: {
							storage: {
								session: {
									get: (
										k: string,
									) => Promise<Record<string, unknown>>
								}
							}
						}
					}
				).chrome.storage.session
				return Object.values(
					((await session.get(key))[key] ?? {}) as Record<
						string,
						Record<string, unknown>
					>,
				)
			}, intentsKey)
			// The intent names the site and the secret, never a seed or a code.
			expect(intent.site).toBe('twostep.test')
			expect(JSON.stringify(intent)).not.toContain('JBSWY3DPEHPK3PXP')
			expect(Object.keys(intent).sort()).toEqual([
				'expiresAt',
				'site',
				'tabId',
				'totpSecretId',
			])

			// Step two: only the code field. The extension fills it.
			await page.click('#next')
			await expect(page).toHaveURL(/\/code/)
			const code = page.locator('#code')
			await expect(code).toHaveValue(/^\d{6}$/, { timeout: 30_000 })
			expect(await expectedCodes()).toContain(await code.inputValue())
		} finally {
			await extension?.close()
			rmSync(profile, { recursive: true, force: true })
			rmSync(outdir, { recursive: true, force: true })
			await deleteUsers(admin, [user])
			await admin.dispose()
		}
	})
})
