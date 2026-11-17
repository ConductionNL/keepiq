/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recovery kit (crypto-recovery-kit).
 *
 * A fresh account makes a kit in its settings and downloads it, without the
 * code going to the server. After a reload locks the vault, "I lost my master
 * password" takes the code and a new master password and opens the vault.
 * The spent code is then refused, and the new master password unlocks.
 */
import type { APIRequestContext, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import {
	adminApi,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
	unlockAs,
} from './_vault-users.ts'
import { APP_BASE, gotoVaultRoute } from './_workflow-helpers.ts'

const owner = newVaultUser('kit')
const NEW_MASTER = 'kit-new-master-pass-2026!'

let admin: APIRequestContext
let context: BrowserContext
let page: Page
let code = ''

/**
 * Fill a field rendered by a Nextcloud input component, by its data-testid.
 *
 * @param target The page.
 * @param testid The data-testid on the component or on its input.
 * @param value The text.
 */
async function fill(target: Page, testid: string, value: string): Promise<void> {
	await target
		.locator(`[data-testid="${testid}"] input, input[data-testid="${testid}"]`)
		.first()
		.fill(value, { force: true })
}

/**
 * Click a control natively by its data-testid.
 *
 * @param target The page.
 * @param testid The data-testid.
 */
async function press(target: Page, testid: string): Promise<void> {
	await target.getByTestId(testid).first().evaluate((el: HTMLElement) => el.click())
}

/**
 * Reload the lock screen and open the recovery path with a code.
 *
 * @param target The page.
 * @param kitCode The recovery code to enter.
 */
async function recoverWith(target: Page, kitCode: string): Promise<void> {
	await target.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	await expect(target.getByTestId('recovery-kit-unlock')).toBeVisible({ timeout: 30_000 })
	await press(target, 'recovery-kit-unlock-start')
	await fill(target, 'recovery-kit-unlock-code', kitCode)
	await fill(target, 'recovery-kit-unlock-password', NEW_MASTER)
	await fill(target, 'recovery-kit-unlock-repeat', NEW_MASTER)
	await target.waitForTimeout(300)
	await press(target, 'recovery-kit-unlock-submit')
}

test.describe('recovery kit', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async ({ baseURL, browser }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [owner])
		;({ context, page } = await signedInContext(browser, owner))
		await setUpVault(page, owner)
		await gotoVaultRoute(page, 'secrets')
	})

	test.afterAll(async () => {
		await context?.close()
		await deleteUsers(admin, [owner])
		await admin?.dispose()
	})

	test('a kit is made and downloaded without the code leaving the browser', async () => {
		// @e2e vault-recovery-kit::a-kit-is-created-without-the-code-leaving-the-browser
		const sent: string[] = []
		page.on('request', (request) => {
			sent.push(`${request.url()} ${request.postData() ?? ''}`)
		})
		await page
			.getByTestId('cn-nav-entry-UserSettings')
			.locator('a, button')
			.first()
			.evaluate((el: HTMLElement) => el.click())
		const panel = page.getByTestId('recovery-kit').first()
		await expect(panel).toBeVisible({ timeout: 20_000 })
		await press(page, 'recovery-kit-create')
		await fill(page, 'recovery-kit-password', owner.masterPassword)
		await page.waitForTimeout(300)
		await press(page, 'recovery-kit-create')
		const shown = page.getByTestId('recovery-kit-code').first()
		await expect(shown).toBeVisible({ timeout: 60_000 })
		code = (await shown.innerText()).trim()
		expect(code.length).toBeGreaterThan(20)

		const pending = page.waitForEvent('download', { timeout: 30_000 })
		await press(page, 'recovery-kit-download')
		const file = await readFile((await (await pending).path()) as string, 'utf8')
		expect(file).toContain(code)

		const bare = code.replace(/[\s-]/g, '')
		for (const line of sent) {
			expect(line, 'a request carrying the recovery code').not.toContain(code)
			expect(line.replace(/[\s-]/g, ''), 'a request carrying the recovery code').not.toContain(bare)
		}
	})

	test('the code opens the vault with a new master password, once', async () => {
		// @e2e vault-recovery-kit::a-user-who-lost-the-master-password-gets-back-in
		await recoverWith(page, code)
		await expect(page.getByTestId('recovery-kit-unlock-continue')).toBeVisible({ timeout: 60_000 })
		await press(page, 'recovery-kit-unlock-continue')
		await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 30_000 })

		// The spent kit is gone: the lock screen no longer offers it, and the
		// new master password unlocks.
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.lock-screen input[type="password"]').first()).toBeVisible({
			timeout: 30_000,
		})
		await expect(page.getByTestId('recovery-kit-unlock')).toHaveCount(0)
		await unlockAs(page, { ...owner, masterPassword: NEW_MASTER })
	})
})
