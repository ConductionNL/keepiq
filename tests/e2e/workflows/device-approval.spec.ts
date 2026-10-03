/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow: approve a new device from an unlocked one
 * (crypto-new-device-approval task 5.1, keepiq#787).
 *
 * Two browser contexts for ONE user. The second context stands on the lock
 * screen and asks for approval; the first, unlocked, shows the request,
 * the test checks both show the same five-word phrase, and approves with the
 * master password. The second context then unlocks without ever being given
 * the master password, and lists the vault.
 *
 * The user is a throwaway account whose vault the browser creates, so the
 * run never depends on admin's seeded suite (see _vault-users.ts).
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
 */
import { expect, test } from '@playwright/test'
import {
	adminApi,
	api,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
} from './_vault-users.ts'
import { APP_BASE } from './_workflow-helpers.ts'

test.describe('device approval', () => {
	test('a second device unlocks after the first approves matching phrases', async ({ browser, baseURL }) => {
		test.setTimeout(240_000)
		const admin = await adminApi(baseURL)
		const user = newVaultUser('dana')
		await createUsers(admin, [user])

		try {
			// First device: set up the vault and store one secret to find later.
			const first = await signedInContext(browser, user)
			await setUpVault(first.page, user)
			const secretName = `Approved device check ${user.uid}`
			const types = await api(first.page, 'GET', '/secret-types')
			const noteType = (types.body as Array<{ id: string, name: string }>).find((t) => t.name === 'note')
			const created = await api(first.page, 'POST', '/secrets', {
				name: secretName,
				key: 'ciphertext-not-read-by-this-test',
				typeId: noteType?.id,
			})
			expect(created.status).toBe(201)

			// Second device: the same user, locked, asks for approval.
			const second = await signedInContext(browser, user)
			await second.page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
			const start = second.page.getByTestId('device-approval-start')
			await expect(start).toBeVisible({ timeout: 30_000 })
			await start.evaluate((el: HTMLElement) => el.click())
			const askingPhrase = second.page.getByTestId('device-approval-phrase')
			await expect(askingPhrase).toBeVisible({ timeout: 30_000 })
			const phraseOnNewDevice = (await askingPhrase.textContent())?.trim() ?? ''
			expect(phraseOnNewDevice.split(/\s+/).length).toBeGreaterThanOrEqual(5)

			// First device: the dialog picks the request up (it refreshes on
			// open; a reload opens it straight away instead of after a poll).
			await first.page.reload({ waitUntil: 'domcontentloaded' })
			const fields = first.page.locator('.lock-screen input[type="password"]')
			if (await fields.first().isVisible().catch(() => false)) {
				await fields.first().fill(user.masterPassword, { force: true })
				await first.page.getByTestId('unlock-with-password').evaluate((el: HTMLElement) => el.click())
			}
			const dialog = first.page.getByTestId('device-approval-dialog')
			await expect(dialog).toBeVisible({ timeout: 60_000 })
			const phraseOnApprover = (await dialog.getByTestId('device-approval-phrase').textContent())?.trim() ?? ''

			// The person compares the words before approving.
			expect(phraseOnApprover).toBe(phraseOnNewDevice)

			await dialog.locator('input[type="password"]').first().fill(user.masterPassword, { force: true })
			await first.page.waitForTimeout(300)
			await dialog.getByTestId('device-approval-approve').evaluate((el: HTMLElement) => {
				const button = el.tagName === 'BUTTON' ? el : el.querySelector('button')
				;(button as HTMLElement).click()
			})
			await expect(dialog).toHaveCount(0, { timeout: 30_000 })

			// Second device: unlocked without the master password, and lists the vault.
			await expect(second.page.locator('.lock-screen')).toHaveCount(0, { timeout: 30_000 })
			await expect(second.page.getByText(secretName).first()).toBeVisible({ timeout: 30_000 })

			await first.context.close()
			await second.context.close()
		} finally {
			await deleteUsers(admin, [user])
			await admin.dispose()
		}
	})
})
