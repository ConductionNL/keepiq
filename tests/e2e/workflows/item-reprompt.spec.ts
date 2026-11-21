/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The master password re-prompt on a flagged item (crypto-item-reprompt).
 *
 * A fresh account stores a login with the re-prompt flag. The list marks it;
 * revealing it with a wrong master password shows nothing and sends nothing
 * carrying the entered password; copying it with the right one copies the
 * value, and the next copy asks again.
 */
import type { APIRequestContext, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
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

const owner = newVaultUser('reprompt')
const VALUE = 'Domain-admin-pass-2026!'
const WRONG = 'not-my-master-password'

let admin: APIRequestContext
let context: BrowserContext
let page: Page
let id = ''

/**
 * Answer the open re-prompt dialog with a master password.
 *
 * @param target The page showing the dialog.
 * @param password The password to enter.
 */
async function answerPrompt(target: Page, password: string): Promise<void> {
	const dialog = target.getByTestId('reprompt-dialog')
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	await target
		.getByTestId('reprompt-dialog-password')
		.locator('input')
		.or(target.locator('input[data-testid="reprompt-dialog-password"]'))
		.first()
		.fill(password)
	await target
		.getByTestId('reprompt-dialog-confirm')
		.evaluate((el: HTMLElement) => el.click())
}

test.describe('item re-prompt', () => {
	test.beforeAll(async ({ baseURL, browser }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [owner])
		;({ context, page } = await signedInContext(browser, owner))
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await setUpVault(page, owner)
		await gotoVaultRoute(page, 'secrets')
		const types = await api(page, 'GET', '/secret-types')
		const login = (types.body as Array<{ id: string; name: string }>).find(
			(t) => t.name === 'login',
		)
		expect(login, 'the login secret type').toBeTruthy()
		id = await withStore<string>(
			page,
			'secret',
			`async (store, data) => (await store.createSecret(data)).id`,
			{
				name: 'Domain admin',
				typeId: login!.id,
				key: VALUE,
				login: 'administrator',
				reprompt: true,
			},
		)
		expect(id).toBeTruthy()
	})

	test.afterAll(async () => {
		await context?.close()
		await deleteUsers(admin, [owner])
		await admin?.dispose()
	})

	test('the list marks the flagged login', async () => {
		// @e2e item-master-password-reprompt::a-vault-user-flags-a-sensitive-login
		// The list loads on entering the route, so leave it and come back.
		await gotoVaultRoute(page, 'password-health')
		await gotoVaultRoute(page, 'secrets')
		await expect(page.getByTestId('secret-reprompt-marker').first()).toBeVisible(
			{
				timeout: 30_000,
			},
		)
	})

	test('a wrong master password reveals nothing', async () => {
		// @e2e item-master-password-reprompt::a-colleague-at-an-unlocked-screen
		const sent: string[] = []
		page.on('request', (request) => {
			const body = request.postData() ?? ''
			if (request.url().includes(WRONG) || body.includes(WRONG)) {
				sent.push(request.url())
			}
		})

		await gotoVaultRoute(page, `secrets/${id}`)
		const sidebar = page.getByTestId('secret-detail-sidebar')
		await expect(sidebar).toBeVisible({ timeout: 30_000 })
		await sidebar
			.locator('.keepiq-password-field button[aria-label="Show"]')
			.evaluate((el: HTMLElement) => el.click())

		await answerPrompt(page, WRONG)
		await expect(page.getByTestId('reprompt-dialog-error')).toBeVisible({
			timeout: 15_000,
		})
		await page
			.getByTestId('reprompt-dialog-cancel')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId('reprompt-dialog')).toHaveCount(0)

		await expect(
			sidebar.locator('.keepiq-password-field input'),
		).toHaveAttribute('type', 'password')
		await expect(sidebar).not.toContainText(VALUE)
		const shown = await sidebar
			.locator('.keepiq-password-field input')
			.inputValue()
		expect(shown).not.toBe(VALUE)
		expect(sent, 'requests carrying the entered password').toEqual([])
	})

	test('the right master password copies, and the next copy asks again', async () => {
		// @e2e item-master-password-reprompt::the-owner-reveals-the-value
		const sidebar = page.getByTestId('secret-detail-sidebar')
		const copy = sidebar.locator(
			'.keepiq-password-field button[aria-label="Copy password"]',
		)
		await page.evaluate(() => navigator.clipboard.writeText(''))
		await copy.evaluate((el: HTMLElement) => el.click())
		await answerPrompt(page, owner.masterPassword)
		await expect(page.getByTestId('reprompt-dialog')).toHaveCount(0, {
			timeout: 20_000,
		})
		await expect
			.poll(() => page.evaluate(() => navigator.clipboard.readText()), {
				timeout: 15_000,
			})
			.toBe(VALUE)

		await copy.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId('reprompt-dialog')).toBeVisible({
			timeout: 15_000,
		})
		await page
			.getByTestId('reprompt-dialog-cancel')
			.evaluate((el: HTMLElement) => el.click())
	})
})
