/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow — a hidden custom field on the detail sidebar stays masked until
 * Show, copies without being shown, and masks again on Hide.
 *
 * The unit tests prove SchemaValueView keeps a hidden value out of the DOM;
 * this flow proves the same in a real browser, on a secret the app itself
 * encrypted, with the real clipboard.
 *
 * @e2e openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
 */
import { expect, test } from '@playwright/test'
import { withStore } from './_vault-actions.ts'
import { api } from './_vault-users.ts'
import { gotoVaultRoute, openVault, unlockVault } from './_workflow-helpers.ts'

// The view this flow drives, named after the file it covers (gate-26 matches
// a page against its component stem).
const SchemaValueView = '.schema-value-view'

const FIELD = 'Service account'
const EMAIL = 'e2e-hidden-field@example.test'
const VALUE = JSON.stringify({ type: 'service_account', client_email: EMAIL })

test.describe('Workflow: a hidden custom field — vault-custom-fields/spec.md', () => {
	test('a hidden object field shows as dots, copies without showing, and shows in the JSON viewer after Show', async ({
		page,
		context,
	}) => {
		// Scenario "A hidden service account", plus Hide.
		await context.grantPermissions(['clipboard-read', 'clipboard-write'])
		await unlockVault(page)
		await openVault(page)

		const types = await api(page, 'GET', '/secret-types')
		const login = (types.body as Array<{ id: string; name: string }>).find(
			(t) => t.name === 'login',
		)
		expect(login, 'the login secret type').toBeTruthy()

		// Stored through the app's own store, so the browser encrypts the blob
		// and its @schema exactly as the create dialog does.
		const id = await withStore<string>(
			page,
			'secret',
			`async (store, data) => {
				const created = await store.createSecret({
					name: data.name,
					key: 'e2e-password',
					typeId: data.typeId,
					additionalFields: {
						[data.field]: data.value,
						'@schema': { [data.field]: { type: 'object', hidden: true } },
					},
				})
				return created.id
			}`,
			{
				name: `__e2e_hidden_${Date.now()}`,
				typeId: login?.id,
				field: FIELD,
				value: VALUE,
			},
		)
		expect(id, 'the secret with a hidden field').toBeTruthy()

		try {
			await gotoVaultRoute(page, `secrets/${id}`)
			await expect(page.locator('.secret-detail__card')).toBeVisible({
				timeout: 30_000,
			})

			const item = page.locator('.secret-detail__extra-item', {
				hasText: FIELD,
			})
			const view = item.locator(SchemaValueView)
			await expect(view).toBeVisible({ timeout: 15_000 })

			// Masked: dots, and the value is nowhere in the page.
			await expect(view.locator('.schema-value-view__masked')).toBeVisible()
			await expect(page.locator('body')).not.toContainText(EMAIL)

			// Copy does not show it, and puts the whole value on the clipboard.
			await view
				.getByRole('button', { name: 'Copy to clipboard' })
				.evaluate((el: HTMLElement) => el.click())
			await expect
				.poll(
					async () =>
						await page.evaluate(() =>
							navigator.clipboard.readText().catch(() => ''),
						),
					{ message: 'the copied value', timeout: 15_000 },
				)
				.toBe(VALUE)
			await expect(view.locator('.schema-value-view__masked')).toBeVisible()
			await expect(page.locator('body')).not.toContainText(EMAIL)

			// Show: the JSON viewer holds the value.
			await view
				.getByRole('button', { name: 'Show' })
				.evaluate((el: HTMLElement) => el.click())
			await expect(view.locator('.schema-value-view__masked')).toHaveCount(0)
			await expect(view).toContainText(EMAIL, { timeout: 15_000 })

			// Hide: dots again, and the value leaves the page.
			await view
				.getByRole('button', { name: 'Hide' })
				.evaluate((el: HTMLElement) => el.click())
			await expect(view.locator('.schema-value-view__masked')).toBeVisible()
			await expect(page.locator('body')).not.toContainText(EMAIL)
		} finally {
			await api(page, 'DELETE', `/secrets/${id}`)
		}
	})
})
