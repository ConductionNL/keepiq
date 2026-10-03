/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow: organisation account recovery
 * (crypto-organisation-account-recovery task 6.1, keepiq#788).
 *
 * A user enrols, forgets their master password, files a request from the lock
 * screen, two recovery officers approve after comparing the phrase, one hands
 * the key over, and the user sets a new master password in the browser that
 * asked and reads the secret they stored before.
 *
 * Every person is a throwaway account whose vault the browser creates. The
 * administrator settings (policy, officers, threshold) are instance-wide, so
 * the test sets them for its own officers and switches the policy back off at
 * the end.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
 */
import type { APIRequestContext, Page } from '@playwright/test'
import type { VaultUser } from './_vault-users.ts'

import { expect, test } from '@playwright/test'
import { openUserSettings, readSecretValue, storeSecret } from './_vault-actions.ts'
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

const RECOVERY_ADMIN = '/index.php/apps/keepiq/api/v1/recovery/admin'

/**
 * Set the instance's recovery settings. The route asks for a password
 * confirmation, which basic auth gives.
 *
 * @param admin An API context from adminApi().
 * @param settings Policy, officers and threshold.
 */
async function setRecoverySettings(
	admin: APIRequestContext,
	settings: { policy: string, officers: string[], threshold: number },
): Promise<void> {
	const response = await admin.put(RECOVERY_ADMIN, { data: settings })
	expect(response.status(), await response.text()).toBe(200)
}

/**
 * Put the instance back to no recovery: retire the active recovery key (a
 * key left by an earlier run would hide "Create the recovery key" from new
 * officers) and switch the policy off.
 *
 * @param admin An API context from adminApi().
 */
async function resetRecovery(admin: APIRequestContext): Promise<void> {
	const current = await (await admin.get(RECOVERY_ADMIN)).json()
	if (current?.activeKey?.id) {
		const retired = await admin.post(`${RECOVERY_ADMIN}/keys/${current.activeKey.id}/retire`)
		expect(retired.status(), await retired.text()).toBe(200)
	}
	await setRecoverySettings(admin, { policy: 'off', officers: [], threshold: 1 })
}

/**
 * Open the officer panel fresh (it loads the requests when it is created).
 *
 * A reload locks the vault (the key lives in memory only), so this unlocks
 * again first, then opens the settings dialog.
 *
 * @param page An officer page.
 * @param officer The officer.
 */
async function openOfficerPanel(page: Page, officer: VaultUser): Promise<void> {
	await unlockAs(page, officer)
	await openUserSettings(page)
	await expect(page.getByTestId('recovery-officer')).toBeVisible({ timeout: 20_000 })
}

test.describe('organisation account recovery', () => {
	test('two officers approve after comparing the phrase and the user reads their old secrets', async ({ browser, baseURL }) => {
		test.setTimeout(420_000)
		const admin = await adminApi(baseURL)

		const user = newVaultUser('ruth')
		const officerOne = newVaultUser('otto')
		const officerTwo = newVaultUser('olivia')
		const people = [user, officerOne, officerTwo]
		await createUsers(admin, people)
		await resetRecovery(admin)

		try {
			// Officers need a vault before they can be named.
			const one = await signedInContext(browser, officerOne)
			await setUpVault(one.page, officerOne)
			const two = await signedInContext(browser, officerTwo)
			await setUpVault(two.page, officerTwo)

			await setRecoverySettings(admin, {
				policy: 'optional',
				officers: [officerOne.uid, officerTwo.uid],
				threshold: 2,
			})

			// An officer's browser makes the recovery key.
			await openOfficerPanel(one.page, officerOne)
			await one.page.getByTestId('recovery-create-key').evaluate((el: HTMLElement) => el.click())
			await expect(one.page.getByTestId('recovery-create-key')).toHaveCount(0, { timeout: 30_000 })

			// The user sets up a vault, stores a secret and enrols.
			const first = await signedInContext(browser, user)
			await setUpVault(first.page, user)
			await gotoVaultRoute(first.page, 'secrets')
			await expect(first.page.locator('.secret-list-view')).toBeVisible({ timeout: 30_000 })
			const secretValue = `before-recovery-${user.uid}`
			const secretId = await storeSecret(first.page, `Kept through recovery ${user.uid}`, secretValue)
			await openUserSettings(first.page)
			const enrolment = first.page.getByTestId('recovery-enrolment')
			await expect(enrolment.getByTestId('recovery-enrolment-fingerprint')).toBeVisible({ timeout: 20_000 })
			await enrolment.locator('input[type="password"]').first().fill(user.masterPassword, { force: true })
			await first.page.waitForTimeout(300)
			await enrolment.getByTestId('recovery-enrol').evaluate((el: HTMLElement) => el.click())
			await expect(enrolment.getByTestId('recovery-enrolment-enrolled')).toBeVisible({ timeout: 30_000 })
			await first.context.close()

			// The user forgot the master password: a new browser files a request.
			const asking = await signedInContext(browser, user)
			await asking.page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
			const forgot = asking.page.getByTestId('forgot-password-start')
			await expect(forgot).toBeVisible({ timeout: 30_000 })
			await forgot.evaluate((el: HTMLElement) => el.click())
			const userPhrase = asking.page.getByTestId('forgot-password-phrase')
			await expect(userPhrase).toBeVisible({ timeout: 30_000 })
			const phrase = (await userPhrase.textContent())?.trim() ?? ''
			expect(phrase.split(/\s+/).length).toBeGreaterThanOrEqual(5)

			// Both officers compare the words and approve with their own key.
			for (const [officer, session] of [[officerOne, one], [officerTwo, two]] as const) {
				await openOfficerPanel(session.page, officer)
				const row = session.page.locator('[data-testid^="recovery-request-"]', { hasText: user.uid })
				await expect(row).toBeVisible({ timeout: 20_000 })
				await expect(row.locator('.recovery-officer__phrase')).toHaveText(phrase)
				await row.locator('input[type="password"]').first().fill(officer.masterPassword, { force: true })
				await session.page.waitForTimeout(300)
				await row.locator('[data-testid^="recovery-approve-"]').evaluate((el: HTMLElement) => {
					const button = el.tagName === 'BUTTON' ? el : el.querySelector('button')
					;(button as HTMLElement).click()
				})
				await expect(row.locator('[data-testid^="recovery-approve-"]')).toHaveCount(0, { timeout: 30_000 })
			}

			// Threshold met: the last approver hands the key over.
			await openOfficerPanel(two.page, officerTwo)
			const handoff = two.page.locator('[data-testid^="recovery-handoff-"]').first()
			await expect(handoff).toBeVisible({ timeout: 20_000 })
			await handoff.evaluate((el: HTMLElement) => {
				const button = el.tagName === 'BUTTON' ? el : el.querySelector('button')
				;(button as HTMLElement).click()
			})
			await expect(handoff).toHaveCount(0, { timeout: 30_000 })

			// The asking browser picks the key up and sets a new master password.
			const newMaster = `ruth-new-${user.uid}-2026!`
			await asking.page.getByTestId('forgot-password-check').evaluate((el: HTMLElement) => el.click())
			const passwords = asking.page.locator('[data-testid="forgot-password"] input[type="password"]')
			await expect(passwords).toHaveCount(2, { timeout: 30_000 })
			await passwords.nth(0).fill(newMaster, { force: true })
			await passwords.nth(1).fill(newMaster, { force: true })
			await asking.page.waitForTimeout(300)
			await asking.page.getByTestId('forgot-password-complete').evaluate((el: HTMLElement) => el.click())
			await expect(asking.page.locator('.lock-screen__card, .lock-screen').first()).toBeVisible()

			// The new master password unlocks the same key, and the old secret reads.
			const later = await signedInContext(browser, user)
			await unlockAs(later.page, { ...user, masterPassword: newMaster })
			await gotoVaultRoute(later.page, 'secrets')
			await expect(later.page.locator('.secret-list-view')).toBeVisible({ timeout: 30_000 })
			expect(await readSecretValue(later.page, secretId)).toBe(secretValue)

			// And the forgotten one no longer does.
			const stale = await signedInContext(browser, user)
			await stale.page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
			const field = stale.page.locator('.lock-screen input[type="password"]').first()
			await field.waitFor({ state: 'visible', timeout: 30_000 })
			await field.fill(user.masterPassword, { force: true })
			await stale.page.getByTestId('unlock-with-password').evaluate((el: HTMLElement) => el.click())
			await stale.page.waitForTimeout(5_000)
			await expect(stale.page.locator('.lock-screen')).toHaveCount(1)

			for (const context of [one.context, two.context, asking.context, later.context, stale.context]) {
				await context.close()
			}
		} finally {
			await resetRecovery(admin).catch(() => undefined)
			await deleteUsers(admin, people)
			await admin.dispose()
		}
	})
})
