/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow e2e for the client-side key generator (client-side-key-generator).
 *
 * From the new-secret dialog: open the generator, generate a password and a
 * passphrase, and put the passphrase into the value field. Throughout, the
 * browser must never ask the server to generate: a request to
 * `/api/v1/generate-key` fails the test, because the server would then see
 * the plaintext value.
 *
 * The policy scenarios (a 30-character floor with a digit, an administrator
 * switching passphrases off) carry an `@e2e exclude` on their spec scenarios:
 * they need an org-policy write that would leak into every other spec on the
 * shared instance, and are covered by tests/vitest/generator.spec.js,
 * tests/dialogs/KeyGeneratorModal.spec.js and
 * tests/components/OrgPasswordPolicySection.spec.js.
 */
import { expect, test } from '@playwright/test'
import { gotoVaultRoute, unlockVault } from './_workflow-helpers.ts'

test.describe('key generator', () => {
	test('generates a password and a passphrase in the browser, and uses the passphrase', async ({
		page,
	}) => {
		// @e2e key-generator::generate-in-the-browser
		// @e2e passphrase-generator::a-vault-user-generates-a-passphrase-for-a-new-secret
		const serverGeneration: string[] = []
		page.on('request', (request) => {
			if (request.url().includes('/api/v1/generate-key')) {
				serverGeneration.push(request.url())
			}
		})

		await unlockVault(page)
		await gotoVaultRoute(page, 'secrets?action=create')
		const form = page.locator('.secret-form')
		await expect(form).toBeVisible({ timeout: 20_000 })

		await form.getByRole('button', { name: 'Generate a strong key' }).click()
		const generator = page.getByRole('dialog', { name: 'Generate key' })
		await expect(generator).toBeVisible({ timeout: 10_000 })

		// Password (the default mode).
		await generator
			.getByRole('button', { name: 'Generate', exact: true })
			.click()
		const preview = generator.getByLabel('Generated key')
		await expect(preview).toHaveValue(/^.{16}$/)

		// Passphrase: six words separated by spaces.
		await generator.getByText('Passphrase', { exact: true }).click()
		await generator.getByLabel('Number of words').fill('6')
		await generator.getByLabel('Separator').fill(' ')
		await generator
			.getByRole('button', { name: 'Generate', exact: true })
			.click()
		await expect(preview).toHaveValue(/^[a-z-]+( [a-z-]+){5}$/)
		const passphrase = await preview.inputValue()

		await generator.getByRole('button', { name: 'Use', exact: true }).click()
		await expect(generator).toHaveCount(0, { timeout: 10_000 })
		await expect(form.locator('input[type="password"]').first()).toHaveValue(
			passphrase,
		)

		expect(serverGeneration, 'the browser asked the server to generate').toEqual(
			[],
		)
	})
})
