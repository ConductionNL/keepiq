/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Clone, attachment preview, Print and Show as QR code on one login
 * (vault-item-clone-preview-and-print, tasks 1.2, 2.2, 3.1 and 3.2).
 *
 * A fresh, non-admin account stores the login "Guest Wi-Fi" with a one-time
 * code key and a small PNG attachment. Cloning it saves a second item with a
 * new id and no shares; previewing the PNG shows it without a download;
 * Print with a wrong master password renders no sheet, and with the right
 * one renders name, address, username and password and never the key; Show
 * as QR code shows a code only after the master password.
 *
 * @spec openspec/specs/secret-item-actions/spec.md
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

const owner = newVaultUser('itemactions')
const NAME = 'Guest Wi-Fi'
const URL = 'https://router.example'
const LOGIN = 'guest'
const VALUE = 'Gast-wifi-2026!'
const SEED = 'JBSWY3DPEHPK3PXP'
const WRONG = 'not-my-master-password'

/** A 1×1 PNG. */
const PNG_BASE64 =
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='

let admin: APIRequestContext
let context: BrowserContext
let page: Page
let id = ''

/**
 * Open the detail sidebar of a secret, inside the unlocked app.
 *
 * @param target The page.
 * @param secretId The secret.
 */
async function openDetail(target: Page, secretId: string): Promise<void> {
	await gotoVaultRoute(target, `secrets/${secretId}`)
	await expect(target.getByTestId('secret-detail-sidebar')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Click an entry of the sidebar's More menu. NcActionButton puts the testid on
 * its wrapper and the handler on the inner button, so the inner one is
 * clicked natively.
 *
 * @param target The page.
 * @param testid The entry's testid.
 */
async function moreMenu(target: Page, testid: string): Promise<void> {
	await target
		.getByTestId('secret-detail-more')
		.locator('button')
		.first()
		.evaluate((el: HTMLElement) => el.click())
	const inner = target.getByTestId(testid).locator('button').first()
	await inner.waitFor({ state: 'attached', timeout: 10_000 })
	await inner.evaluate((el: HTMLElement) => el.click())
}

/**
 * Type a master password into a dialog's field and press its primary button.
 *
 * @param target The page.
 * @param field The password field's testid.
 * @param submit The button's testid.
 * @param password The password.
 */
async function enterMasterPassword(
	target: Page,
	field: string,
	submit: string,
	password: string,
): Promise<void> {
	await target
		.getByTestId(field)
		.locator('input')
		.or(target.locator(`input[data-testid="${field}"]`))
		.first()
		.fill(password)
	await target.getByTestId(submit).evaluate((el: HTMLElement) => el.click())
}

test.describe('secret item actions', () => {
	test.beforeAll(async ({ baseURL, browser }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [owner])
		;({ context, page } = await signedInContext(browser, owner))
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
				name: NAME,
				typeId: login!.id,
				key: VALUE,
				login: LOGIN,
				url: URL,
				totp: SEED,
			},
		)
		expect(id).toBeTruthy()

		// The attachment store is in use once the detail renders its panel.
		await openDetail(page, id)
		await withStore<void>(
			page,
			'attachment',
			`async (store, data) => {
				const bytes = Uint8Array.from(atob(data.png), (c) => c.charCodeAt(0))
				await store.upload(data.id, new File([bytes], 'router.png', { type: 'image/png' }))
			}`,
			{ id, png: PNG_BASE64 },
		)
	})

	test.afterAll(async () => {
		await context?.close()
		await deleteUsers(admin, [owner])
		await admin?.dispose()
	})

	test('Clone saves a second item with a new id and no shares', async () => {
		// @e2e secret-item-actions::clone-a-secret
		await openDetail(page, id)
		await moreMenu(page, 'secret-detail-clone')
		const dialog = page.getByRole('dialog').first()
		await expect(
			dialog.getByRole('textbox', { name: /^name/i }).first(),
		).toHaveValue(`${NAME} (copy)`, {
			timeout: 15_000,
		})
		await dialog
			.getByRole('button', { name: /^(save|create)/i })
			.first()
			.evaluate((el: HTMLElement) => el.click())

		const listedIds = async (): Promise<string[]> => {
			const listed = await api(page, 'GET', '/secrets?limit=100')
			expect(listed.status).toBe(200)
			return (listed.body.items as Array<{ id: string }>).map((row) => row.id)
		}
		await expect
			.poll(async () => (await listedIds()).length, { timeout: 30_000 })
			.toBe(2)
		const cloneId = (await listedIds()).find((row) => row !== id)
		expect(cloneId, 'the clone has a new id').toBeTruthy()

		const shares = await api(page, 'GET', `/secrets/${cloneId}/shares`)
		expect(shares.status).toBe(200)
		const rows = Array.isArray(shares.body)
			? shares.body
			: (shares.body.items ?? shares.body.shares ?? [])
		expect(rows, 'the clone is not shared').toEqual([])
	})

	test('an image attachment previews without a download', async () => {
		// @e2e secret-item-actions::preview-an-attachment-in-the-browser
		const downloads: string[] = []
		page.on('download', (download) =>
			downloads.push(download.suggestedFilename()),
		)

		await openDetail(page, id)
		await page
			.getByRole('button', { name: 'Preview attachment' })
			.first()
			.evaluate((el: HTMLElement) => el.click())
		const image = page.getByTestId('attachment-preview-image')
		await expect(image).toBeVisible({ timeout: 20_000 })
		expect(await image.getAttribute('src')).toMatch(/^blob:/)

		await page
			.getByTestId('attachment-preview-close')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId('attachment-preview')).toHaveCount(0)
		expect(downloads, 'files saved to the device').toEqual([])
	})

	test('a wrong master password blocks printing', async () => {
		// @e2e secret-item-actions::a-wrong-master-password-blocks-printing
		await page.evaluate(() => {
			;(window as unknown as { __printed: number }).__printed = 0
			window.print = () => {
				;(window as unknown as { __printed: number }).__printed += 1
			}
		})
		await openDetail(page, id)
		await moreMenu(page, 'secret-detail-print')
		await expect(page.getByTestId('secret-print')).toBeVisible({
			timeout: 15_000,
		})
		await enterMasterPassword(
			page,
			'secret-print-password-field',
			'secret-print-submit',
			WRONG,
		)

		await expect(page.getByTestId('secret-print-error')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('secret-print-sheet')).toHaveCount(0)
		expect(
			await page.evaluate(
				() => (window as unknown as { __printed: number }).__printed,
			),
		).toBe(0)
		await page
			.getByTestId('secret-print-close')
			.evaluate((el: HTMLElement) => el.click())
	})

	test('the print sheet holds name, address, username and password and no key', async () => {
		// @e2e secret-item-actions::print-a-login-or-show-its-password-as-a-qr-code
		await page.evaluate(() => {
			;(window as unknown as { __printed: number }).__printed = 0
			window.print = () => {
				;(window as unknown as { __printed: number }).__printed += 1
			}
		})
		await openDetail(page, id)
		await moreMenu(page, 'secret-detail-print')
		await enterMasterPassword(
			page,
			'secret-print-password-field',
			'secret-print-submit',
			owner.masterPassword,
		)

		const sheet = page.getByTestId('secret-print-sheet')
		await expect(sheet).toHaveCount(1, { timeout: 20_000 })
		await expect(sheet.getByTestId('secret-print-name')).toHaveText(NAME)
		await expect(sheet.getByTestId('secret-print-url')).toHaveText(URL)
		await expect(sheet.getByTestId('secret-print-login')).toHaveText(LOGIN)
		await expect(sheet.getByTestId('secret-print-password')).toHaveText(VALUE)
		expect(await sheet.textContent()).not.toContain(SEED)
		expect(
			await page.evaluate(
				() => (window as unknown as { __printed: number }).__printed,
			),
		).toBe(1)

		// In print, the sheet is the only thing on the page.
		await page.emulateMedia({ media: 'print' })
		await expect(sheet).toBeVisible()
		await expect(page.locator('#keepiq-app')).toBeHidden()
		await page.emulateMedia({ media: 'screen' })
		await expect(sheet).toBeHidden()

		await page
			.getByTestId('secret-print-close')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId('secret-print-sheet')).toHaveCount(0)
	})

	test('Show as QR code shows the code after the master password', async () => {
		// @e2e secret-item-actions::a-vault-user-shows-a-wi-fi-password-as-a-qr-code
		await openDetail(page, id)
		await moreMenu(page, 'secret-detail-qr')
		await expect(page.getByTestId('secret-qr')).toBeVisible({ timeout: 15_000 })
		await expect(page.getByTestId('secret-qr-code')).toHaveCount(0)

		await enterMasterPassword(
			page,
			'secret-qr-password',
			'secret-qr-show',
			owner.masterPassword,
		)
		const code = page.getByTestId('secret-qr-code')
		await expect(code).toBeVisible({ timeout: 15_000 })
		expect(await code.locator('path').getAttribute('d')).toMatch(
			/^M\d+ \d+h1v1h-1z/,
		)
		await expect(page.getByTestId('secret-qr')).not.toContainText(VALUE)

		await page
			.getByTestId('secret-qr-close')
			.evaluate((el: HTMLElement) => el.click())
		await expect(page.getByTestId('secret-qr-code')).toHaveCount(0)
	})
})
