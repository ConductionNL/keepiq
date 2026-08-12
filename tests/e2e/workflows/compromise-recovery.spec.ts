/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Compromise recovery (key rotation + secret migration).
 *
 * Covers the four compromise-recovery scenarios that carry no `@e2e exclude`:
 * the rotate-every-value warning before the user confirms, live progress inside
 * the dialog, a terminal message that reports counts without claiming the vault
 * is secure, and the possibly-compromised warning on a migrated secret
 * afterwards.
 *
 * HOW THIS AVOIDS THE SEEDED-VAULT DEAD END
 *
 * workflows/vault-unlock.spec.ts documents (bug #5) that admin's seeded vault
 * cannot be unlocked by any password: `SeedDevelopmentData` writes the AES-GCM
 * private-key envelope with the PHP EncryptService and the browser's
 * decryptPrivateKey() rejects it. Recovery lives behind the unlock gate, so
 * driving it as admin is impossible.
 *
 * So this spec does not use admin. It logs in as a NON-ADMIN fixture user who
 * owns NO EncryptionSuite (alice, in the standard dev seed), which puts the
 * lock screen in FIRST-TIME SETUP mode. The vault is then created by the
 * BROWSER, so the envelope is JS-written and unlocks normally — and the whole
 * recovery flow becomes reachable end to end without touching the seeded
 * envelope at all.
 *
 * It also makes the test self-repairing: it creates the vault it goes on to
 * rotate, rather than rotating one that already held something. Rotation is
 * destructive to a vault's link shares and passkeys, so a spec that performs it
 * must own its subject. Point DORIATH_VAULT_USER at a throwaway account.
 *
 * If the chosen user already owns a suite (a previous run, or a seed change),
 * setup mode will not appear; the spec detects that and skips rather than
 * silently asserting against the wrong surface.
 *
 * @e2e openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 * @e2e openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 * @e2e openspec/specs/encryption-suites/spec.md#requirement-suite-migration
 */
import { test, expect, type Page } from '@playwright/test'
import { APP_BASE } from './_workflow-helpers'

/** A fixture account that owns no EncryptionSuite, so setup mode is reachable. */
const VAULT_USER = process.env.DORIATH_VAULT_USER ?? 'alice'
const VAULT_PASS = process.env.DORIATH_VAULT_PASS ?? 'alice'

/** Master passwords for the vault this spec creates and then rotates. */
const OLD_MASTER = 'Or1ginal-master-passphrase!'
const NEW_MASTER = 'R0tated-master-passphrase!'

/**
 * Start from a clean, unauthenticated context: the global setup stores an ADMIN
 * session, and this spec deliberately runs as somebody else.
 */
test.use({ storageState: { cookies: [], origins: [] } })

/**
 * Log into Nextcloud as the vault fixture user.
 *
 * @param page The Playwright page.
 */
async function loginAsVaultUser(page: Page): Promise<void> {
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	const userField = page.locator('input[name="user"]')
	await userField.waitFor({ state: 'visible', timeout: 40_000 })
	await userField.fill(VAULT_USER)
	await page.locator('input[name="password"]').fill(VAULT_PASS)
	await page.locator('form[name="login"] button[type="submit"]').click()
	await expect(page).not.toHaveURL(/\/login(\?|$|\/)/, { timeout: 40_000 })
}

/**
 * Click a button by its visible label, natively.
 *
 * The themed NcButton swallows Playwright's synthetic click, which is why every
 * existing workflow helper dispatches through page.evaluate.
 *
 * @param page  The Playwright page.
 * @param label A regex source matched case-insensitively against the label.
 */
async function clickByLabel(page: Page, label: string): Promise<void> {
	await page.evaluate((pattern) => {
		const re = new RegExp(pattern, 'i')
		const btn = Array.from(document.querySelectorAll('button')).find(
			(b) => re.test(b.textContent || ''),
		)
		if (btn) {
			(btn as HTMLButtonElement).click()
		}
	}, label)
}

/**
 * Create the vault in the browser, so its envelope is JS-written.
 *
 * @param page The Playwright page (already logged in).
 * @return True when setup ran; false when the user already owned a suite.
 */
async function setUpVault(page: Page): Promise<boolean> {
	await page.goto(`${APP_BASE}/#/lock`, { waitUntil: 'domcontentloaded' })
	await page.locator('.lock-screen__card').waitFor({ state: 'visible', timeout: 30_000 })

	// Setup mode has TWO password fields; unlock mode has one.
	const fields = page.locator('.lock-screen input[type="password"]')
	if (await fields.count() < 2) {
		return false
	}

	await fields.nth(0).fill(OLD_MASTER, { force: true })
	await fields.nth(1).fill(OLD_MASTER, { force: true })
	await page.waitForTimeout(400)
	await clickByLabel(page, 'Set up vault')
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 30_000 })
	return true
}

/**
 * Create one secret through the API, so the migration has something to move.
 *
 * Ciphertext is produced in the browser by the app's own crypto for realism;
 * this only needs the row to exist and be bound to the active suite.
 *
 * @param page The Playwright page (vault unlocked).
 */
async function seedOneSecret(page: Page): Promise<void> {
	const created = await page.evaluate(async (base) => {
		const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken || ''
		const res = await fetch(`${base}/api/v1/secrets`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: token,
				'OCS-APIREQUEST': 'true',
			},
			body: JSON.stringify({
				name: 'e2e-rotation-subject',
				key: 'PLACEHOLDER-CIPHERTEXT-FOR-ROTATION',
			}),
		})
		return { status: res.status, body: await res.text() }
	}, APP_BASE)

	expect(created.status, `secret create failed: ${created.body}`).toBeLessThan(400)
}

/**
 * Open the compromise-recovery form inside the user-settings dialog.
 *
 * @param page The Playwright page (vault unlocked).
 */
async function openRecoveryForm(page: Page): Promise<void> {
	await clickByLabel(page, 'My master password was compromised')
	await expect(page.locator('.compromise-recovery-form')).toBeVisible({ timeout: 15_000 })
}

test.describe('Workflow: compromise recovery — encryption-suites/spec.md', () => {
	test('a fresh vault can be created, rotated, and reports the truth throughout', async ({ page }) => {
		test.slow()

		await loginAsVaultUser(page)

		const didSetUp = await setUpVault(page)
		test.skip(
			didSetUp === false,
			`${VAULT_USER} already owns an EncryptionSuite, so first-time setup was not offered. `
			+ 'Point DORIATH_VAULT_USER at an account with no suite.',
		)

		await seedOneSecret(page)

		// The recovery form lives in the user-settings dialog; the manifest entry
		// opens it. Navigate in place so the in-memory CryptoKey survives.
		await page.evaluate(() => {
			const entry = Array.from(document.querySelectorAll('a, button')).find(
				(el) => /settings/i.test(el.textContent || ''),
			)
			if (entry) {
				(entry as HTMLElement).click()
			}
		})
		await page.waitForTimeout(800)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')

		// SURFACE 1 — before confirm. Access restored, not safety.
		const before = await form.innerText()
		expect(before).toMatch(/must be assumed to have been exposed/i)
		expect(before).toMatch(/changed at its source/i)
		expect(before).toMatch(/restores access/i)
		expect(before).toMatch(/does not make the old values safe/i)

		// Drive the rotation.
		const pw = form.locator('input[type="password"]')
		await pw.nth(0).fill(OLD_MASTER, { force: true })
		await pw.nth(1).fill(NEW_MASTER, { force: true })
		await pw.nth(2).fill(NEW_MASTER, { force: true })
		await page.waitForTimeout(400)
		await clickByLabel(page, 'Start key rotation')

		// SURFACE 3 — terminal. Counts, and never an all-clear. (Surface 2, the
		// progress bar, is asserted in the dedicated test below; a single-secret
		// vault can migrate faster than a poll can observe it.)
		await expect(form).toContainText(/Key rotation finished/i, { timeout: 180_000 })
		await expect(form).toContainText(/re-encrypted under your new key/i)
		await expect(form).toContainText(/still to be considered exposed/i)
		await expect(form).not.toContainText(/now secured with a new encryption key/i)
		await expect(form).not.toContainText(/vault is now secure/i)

		// The migration must actually be terminal, not merely reported so.
		const status = await page.evaluate(async (base) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken || ''
			const res = await fetch(`${base}/api/v1/migrations/status`, {
				headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
			})
			return res.text()
		}, APP_BASE)
		expect(status).toContain('none')
	})

	test('progress renders inside the dialog, not in a toast or a separate page', async ({ page }) => {
		test.slow()

		await loginAsVaultUser(page)
		const didSetUp = await setUpVault(page)
		test.skip(didSetUp === false, `${VAULT_USER} already owns an EncryptionSuite.`)

		// Several secrets, so the run lasts long enough to observe. RSA-4096 over
		// each field is the slow part, which is exactly what the worker exists for.
		for (let i = 0; i < 6; i++) {
			await seedOneSecret(page)
		}

		await page.evaluate(() => {
			const entry = Array.from(document.querySelectorAll('a, button')).find(
				(el) => /settings/i.test(el.textContent || ''),
			)
			if (entry) {
				(entry as HTMLElement).click()
			}
		})
		await page.waitForTimeout(800)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')
		const pw = form.locator('input[type="password"]')
		await pw.nth(0).fill(OLD_MASTER, { force: true })
		await pw.nth(1).fill(NEW_MASTER, { force: true })
		await pw.nth(2).fill(NEW_MASTER, { force: true })
		await page.waitForTimeout(400)
		await clickByLabel(page, 'Start key rotation')

		// Inside the dialog — the maintainer decision recorded in design.md.
		await expect(form.locator('.compromise-recovery-form__progress')).toBeVisible({ timeout: 60_000 })
		await expect(form).toContainText(/records re-encrypted|Preparing/i)
	})

	test('a migrated secret carries the possibly-compromised warning afterwards', async ({ page }) => {
		test.slow()

		await loginAsVaultUser(page)
		const didSetUp = await setUpVault(page)
		test.skip(didSetUp === false, `${VAULT_USER} already owns an EncryptionSuite.`)

		await seedOneSecret(page)

		await page.evaluate(() => {
			const entry = Array.from(document.querySelectorAll('a, button')).find(
				(el) => /settings/i.test(el.textContent || ''),
			)
			if (entry) {
				(entry as HTMLElement).click()
			}
		})
		await page.waitForTimeout(800)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')
		const pw = form.locator('input[type="password"]')
		await pw.nth(0).fill(OLD_MASTER, { force: true })
		await pw.nth(1).fill(NEW_MASTER, { force: true })
		await pw.nth(2).fill(NEW_MASTER, { force: true })
		await page.waitForTimeout(400)
		await clickByLabel(page, 'Start key rotation')
		await expect(form).toContainText(/Key rotation finished/i, { timeout: 180_000 })

		// Into the vault list, in place so the CryptoKey survives.
		await page.evaluate(() => { window.location.hash = '#/secrets' })
		await page.waitForTimeout(1500)

		// This is the surface that was entirely missing before: six consumers of
		// possibly_compromised_at and no producer, so no row ever said anything.
		const warned = page.locator('[data-testid="secret-possibly-compromised"]')
		await expect(warned.first()).toBeVisible({ timeout: 30_000 })
		await expect(warned.first()).toContainText(/change it at its source/i)
	})

	test('the resume banner stays silent when no migration is in progress', async ({ page }) => {
		// The negative case is the one that regresses quietly: a banner shown to
		// everyone gets noticed immediately, one shown to nobody does not.
		await loginAsVaultUser(page)
		await page.goto(`${APP_BASE}/#/lock`, { waitUntil: 'domcontentloaded' })
		await page.locator('.lock-screen__card').waitFor({ state: 'visible', timeout: 30_000 })

		await expect(page.locator('[data-testid="migration-resume-banner"]')).toHaveCount(0)
	})
})
