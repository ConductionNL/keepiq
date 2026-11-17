/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Hidden custom fields and the SSH key item (vault-custom-field-kinds-and-ssh-key).
 *
 * A fresh account stores a login with a hidden field: the detail sidebar
 * shows dots until Show, hides it again, and Copy puts the real value on the
 * clipboard. Then it creates an SSH Key item through the New secret dialog
 * with Generate key pair, and the detail sidebar shows the public key and its
 * fingerprint with a Copy action.
 */
import type { APIRequestContext, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { withStore } from './_vault-actions.ts'
import {
	adminApi,
	api,
	clickButton,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
} from './_vault-users.ts'
import { gotoVaultRoute } from './_workflow-helpers.ts'

const owner = newVaultUser('fields')
const HIDDEN = 'RC-4711-recovery-2026'

let admin: APIRequestContext
let context: BrowserContext
let page: Page
let types: Array<{ id: string; name: string }> = []

/**
 * The id of a system secret type.
 *
 * @param name The type name, such as `login`.
 * @return The type id.
 */
function typeId(name: string): string {
	const type = types.find((t) => t.name === name)
	expect(type, `the ${name} secret type`).toBeTruthy()
	return type!.id
}

/**
 * The clipboard's text, read in the page.
 *
 * @return The text.
 */
function clipboard(): Promise<string> {
	return page.evaluate(() => navigator.clipboard.readText())
}

test.describe('custom field kinds and the SSH key item', () => {
	test.beforeAll(async ({ baseURL, browser }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [owner])
		;({ context, page } = await signedInContext(browser, owner))
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await setUpVault(page, owner)
		await gotoVaultRoute(page, 'secrets')
		types = (await api(page, 'GET', '/secret-types')).body
	})

	test.afterAll(async () => {
		await context?.close()
		await deleteUsers(admin, [owner])
		await admin?.dispose()
	})

	test('a hidden field is masked until Show, and Copy copies the value', async () => {
		// @e2e vault-custom-fields::an-owner-hides-a-recovery-code
		const id = await withStore<string>(
			page,
			'secret',
			`async (store, data) => (await store.createSecret(data)).id`,
			{
				name: 'Mail account',
				typeId: typeId('login'),
				key: 'Mail-pass-2026!',
				login: 'me@example.org',
				additionalFields: {
					'Recovery code': { value: HIDDEN, kind: 'hidden' },
					Note: 'plain',
				},
			},
		)

		await gotoVaultRoute(page, `secrets/${id}`)
		const sidebar = page.getByTestId('secret-detail-sidebar')
		const field = sidebar.getByTestId('secret-detail-field-Recovery code')
		await expect(field).toHaveText('••••••••', { timeout: 30_000 })
		await expect(sidebar).not.toContainText(HIDDEN)
		await expect(sidebar.getByTestId('secret-detail-field-Note')).toHaveText('plain')

		const toggle = sidebar.getByTestId('secret-detail-field-reveal-Recovery code')
		await toggle.evaluate((el: HTMLElement) => el.click())
		await expect(field).toHaveText(HIDDEN)
		await toggle.evaluate((el: HTMLElement) => el.click())
		await expect(field).toHaveText('••••••••')

		await page.evaluate(() => navigator.clipboard.writeText(''))
		await sidebar
			.getByTestId('secret-detail-field-copy-Recovery code')
			.evaluate((el: HTMLElement) => el.click())
		await expect.poll(clipboard, { timeout: 15_000 }).toBe(HIDDEN)
		await expect(field).toHaveText('••••••••')
	})

	test('Generate key pair stores an SSH key and the sidebar copies its public key', async () => {
		// @e2e vault-ssh-key-item::a-user-generates-an-ssh-key-pair
		// A secret lives in a folder, so the dialog is opened from one.
		const folder = await api(page, 'POST', '/folders', { name: 'Servers' })
		expect(folder.status, JSON.stringify(folder.body)).toBeLessThan(300)
		await gotoVaultRoute(page, `folders/${folder.body.id}`)
		// A new account is greeted by the support dialog, which covers the page.
		const support = page.getByRole('dialog', { name: 'Support Keepiq' })
		if (await support.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await support.getByRole('button', { name: 'Close' }).first().click()
			await expect(support).toHaveCount(0)
		}
		await clickButton(page, '^New secret$')
		const form = page.locator('.secret-form')
		await expect(form).toBeVisible({ timeout: 15_000 })
		await form.locator('input[type="text"]').first().fill('Deploy key', { force: true })

		await form.getByRole('combobox', { name: 'Type' }).click()
		await page.getByRole('option', { name: /SSH/ }).first().click()
		const ssh = form.getByTestId('ssh-key-fields')
		await expect(ssh).toBeVisible({ timeout: 15_000 })

		await ssh.getByTestId('ssh-generate').evaluate((el: HTMLElement) => el.click())
		const publicKey = ssh.locator('[data-testid="ssh-public-key"] input, input[data-testid="ssh-public-key"]').first()
		await expect(publicKey).toHaveValue(/^ssh-[a-z0-9-]+ /, { timeout: 30_000 })
		const generated = await publicKey.inputValue()
		const fingerprint = await ssh
			.locator('[data-testid="ssh-fingerprint"] input, input[data-testid="ssh-fingerprint"]')
			.first()
			.inputValue()
		expect(fingerprint).toMatch(/^SHA256:/)

		await clickButton(page, '^Create secret$')
		await expect(form).toHaveCount(0, { timeout: 20_000 })

		const list = await api(page, 'GET', '/secrets?limit=100')
		const items = (list.body.items ?? list.body) as Array<{ id: string; name: string; key: string }>
		const stored = items.find((s) => s.name === 'Deploy key')
		expect(stored, 'the stored SSH key item').toBeTruthy()
		expect(stored!.key).not.toContain('PRIVATE KEY')
		expect(JSON.stringify(stored)).not.toContain(generated)

		await gotoVaultRoute(page, `secrets/${stored!.id}`)
		const sidebar = page.getByTestId('secret-detail-sidebar')
		await expect(sidebar.getByTestId('secret-detail-ssh-public-key')).toHaveText(generated, {
			timeout: 30_000,
		})
		await expect(sidebar.getByTestId('secret-detail-ssh-fingerprint')).toHaveText(fingerprint)

		await page.evaluate(() => navigator.clipboard.writeText(''))
		await sidebar
			.getByTestId('secret-detail-ssh-copy-public-key')
			.evaluate((el: HTMLElement) => el.click())
		await expect.poll(clipboard, { timeout: 15_000 }).toBe(generated)
	})
})
