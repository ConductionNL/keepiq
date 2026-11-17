/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Duplicates section of the password health report (vault-duplicate-finder).
 *
 * A fresh account stores three logins for one site and username: two with the
 * same password and one with another. The report then shows one exact group
 * (the two identical logins) and one likely group (all three), and no request
 * to the server carries a group.
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

const owner = newVaultUser('dupes')

let admin: APIRequestContext
let context: BrowserContext
let page: Page

/**
 * Store a login through the app's own store, so the browser encrypts it.
 *
 * @param target An unlocked page of the app.
 * @param typeId The login type's id.
 * @param name The item name.
 * @param password The login's password.
 * @return The new secret's id.
 */
async function storeLogin(
	target: Page,
	typeId: string,
	name: string,
	password: string,
): Promise<string> {
	return withStore<string>(
		target,
		'secret',
		`async (store, data) => (await store.createSecret(data)).id`,
		{
			name,
			typeId,
			key: password,
			login: 'octo@example.org',
			url: 'https://github.com/login',
		},
	)
}

test.describe('duplicate finder', () => {
	test.beforeAll(async ({ baseURL, browser }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [owner])
		;({ context, page } = await signedInContext(browser, owner))
		await setUpVault(page, owner)
	})

	test.afterAll(async () => {
		await context?.close()
		await deleteUsers(admin, [owner])
		await admin?.dispose()
	})

	test('the report shows one exact and one likely group', async () => {
		// @e2e vault-duplicates::a-vault-user-sees-duplicate-logins
		await gotoVaultRoute(page, 'secrets')
		const types = await api(page, 'GET', '/secret-types')
		const login = (types.body as Array<{ id: string; name: string }>).find(
			(t) => t.name === 'login',
		)
		expect(login, 'the login secret type').toBeTruthy()

		const first = await storeLogin(page, login!.id, 'GitHub', 'Same-pass-2026!')
		const second = await storeLogin(page, login!.id, 'GitHub (import 2)', 'Same-pass-2026!')
		const third = await storeLogin(page, login!.id, 'GitHub (old)', 'Other-pass-2026!')

		const bodies: string[] = []
		page.on('request', (request) => {
			if (request.url().includes('/apps/keepiq/')) {
				bodies.push(request.postData() ?? '')
			}
		})

		await gotoVaultRoute(page, 'password-health')
		await expect(page.getByTestId('category-duplicates')).toBeVisible({
			timeout: 30_000,
		})

		const exact = page.getByTestId('duplicate-group-exact')
		await expect(exact).toHaveCount(1)
		await expect(exact.getByTestId(`duplicate-item-${first}`)).toBeVisible()
		await expect(exact.getByTestId(`duplicate-item-${second}`)).toBeVisible()
		await expect(exact.getByTestId(`duplicate-item-${third}`)).toHaveCount(0)
		await expect(exact).toContainText('github.com')
		await expect(exact).toContainText('octo@example.org')

		const likely = page.getByTestId('duplicate-group-likely')
		await expect(likely).toHaveCount(1)
		for (const id of [first, second, third]) {
			await expect(likely.getByTestId(`duplicate-item-${id}`)).toBeVisible()
		}

		for (const body of bodies) {
			expect(body, 'a request body naming a duplicate group').not.toMatch(
				/duplicate|"exact"|"likely"/i,
			)
		}
	})
})
