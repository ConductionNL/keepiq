/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Compromise recovery (key rotation + secret migration).
 *
 * Covers the four compromise-recovery scenarios that carry no `@e2e exclude`
 * in the spec: the rotate-every-value warning before the user confirms, live
 * progress inside the dialog, a terminal message that reports counts without
 * claiming the vault is secure, and the possibly-compromised warning on a
 * migrated secret afterwards.
 *
 * TWO CONSTRAINTS SHAPE THIS FILE. Both are recorded here rather than worked
 * around, because working around either would make the suite lie.
 *
 *   1. The seeded vault cannot be unlocked, at all. `SeedDevelopmentData`
 *      writes the AES-GCM private-key envelope with the PHP EncryptService,
 *      and the browser's decryptPrivateKey() (PBKDF2-SHA256 600k → AES-256-GCM,
 *      envelope v1) rejects it for every password including the dev one. Same
 *      root cause as bug #5 in workflows/vault-unlock.spec.ts, which fixmes its
 *      own unlock assertions for exactly this reason. Compromise recovery lives
 *      behind the unlock gate and needs an in-memory CryptoKey to generate a
 *      key pair, so every assertion that requires an unlocked vault is
 *      test.fixme here too — not skipped silently.
 *
 *   2. Driving the real flow ROTATES THE ACCOUNT'S KEY. That creates a second
 *      active suite, takes the write lock, and — before the fixes in this
 *      change — was exactly how a vault got bricked. A test that performs it
 *      as a side effect must run against a disposable instance, which is why
 *      tests/e2e/base-url.ts refuses to default to the shared dev container.
 *      The fixme'd tests below are written to be correct when pointed at a
 *      throwaway instance with a JS-written envelope; they are not written to
 *      be safe against a vault anyone cares about.
 *
 * WHAT IS GENUINELY GREEN HERE is the data-independent half: the recovery
 * surface is reachable, and the resume banner correctly renders NOTHING when no
 * migration is in progress (the negative case is the one that regresses
 * quietly, since a banner that shows up for everyone would be noticed on day
 * one). The copy assertions the spec demands are additionally covered at the
 * component level, where they can be asserted without a decryptable vault:
 *   - tests/components/CompromiseRecoveryForm.spec.js  (all three surfaces)
 *   - tests/components/SecretListItem.spec.js          (row warning)
 *   - tests/components/MigrationResumeBanner.spec.js   (resume banner)
 *
 * @e2e openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 * @e2e openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 */
import { test, expect } from '@playwright/test'
import {
	APP_BASE,
	DEV_MASTER_PASSWORD,
	gotoLockSettled,
	openVault,
	unlockVault,
} from './_workflow-helpers'

/**
 * Open the user-settings dialog and reveal the compromise-recovery form.
 *
 * The form is behind the "My master password was compromised" toggle in the
 * Security section of the user-settings dialog (App.vue), which CnAppRoot opens
 * from the manifest's `action: "user-settings"` entry. Dispatched natively
 * because the themed menu entry swallows Playwright's synthetic click.
 *
 * @param page The Playwright page (must already be unlocked).
 */
async function openRecoveryForm(page: import('@playwright/test').Page): Promise<void> {
	await page.evaluate(() => {
		const entry = Array.from(document.querySelectorAll('a, button')).find(
			(el) => /settings/i.test(el.textContent || ''),
		)
		if (entry) {
			(entry as HTMLElement).click()
		}
	})
	await page.waitForTimeout(500)

	await page.evaluate(() => {
		const toggle = Array.from(document.querySelectorAll('button')).find(
			(b) => /master password was compromised/i.test(b.textContent || ''),
		)
		if (toggle) {
			toggle.click()
		}
	})
	await expect(page.locator('.compromise-recovery-form')).toBeVisible({ timeout: 10_000 })
}

test.describe('Workflow: compromise recovery — encryption-suites/spec.md', () => {
	test('the app boots to the lock gate, so the recovery surface is gated', async ({ page }) => {
		const heading = await gotoLockSettled(page)
		expect(heading).toMatch(/Unlock Doriath/i)
		// Recovery must never be reachable without unlocking: it hands the old
		// master password to the browser and generates a new key pair.
		await expect(page.locator('.compromise-recovery-form')).toHaveCount(0)
	})

	test('the resume banner does NOT render when no migration is in progress', async ({ page }) => {
		// The negative case is the one that regresses quietly. A banner that
		// renders for every user would be caught immediately; one that renders
		// for nobody — including a user with a genuinely stalled migration — is
		// the failure this pins down from the other side.
		await gotoLockSettled(page)
		await expect(page.locator('[data-testid="migration-resume-banner"]')).toHaveCount(0)
	})

	test('the migration status endpoint reports no migration for a clean account', async ({ page }) => {
		await gotoLockSettled(page)

		const status = await page.evaluate(async (base) => {
			const token = (document.querySelector('head') as HTMLElement)
				?.getAttribute('data-requesttoken')
				|| (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| ''
			const res = await fetch(`${base}/api/v1/migrations/status`, {
				headers: { requesttoken: token, 'OCS-APIREQUEST': 'true' },
			})
			return { ok: res.ok, body: await res.text() }
		}, APP_BASE)

		expect(status.ok).toBe(true)
		// A clean account reports `none`; anything else means a migration is
		// parked and the vault is write-locked.
		expect(status.body).toContain('none')
	})

	// ---------------------------------------------------------------------
	// Everything below needs a vault that can actually be unlocked. See
	// constraint 1 at the top of this file.
	// ---------------------------------------------------------------------

	test.fixme('the warning to rotate every value is shown BEFORE the user confirms', async ({ page }) => {
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await openRecoveryForm(page)

		const text = await page.locator('.compromise-recovery-form').innerText()
		expect(text).toMatch(/must be assumed to have been exposed/i)
		expect(text).toMatch(/changed at its source/i)
		// The distinction the spec insists on: access restored, not safety.
		expect(text).toMatch(/restores access/i)
		expect(text).toMatch(/does not make the old values safe/i)
	})

	test.fixme('progress renders inside the recovery dialog while migrating', async ({ page }) => {
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')
		await form.locator('input[type="password"]').nth(0).fill(DEV_MASTER_PASSWORD)
		await form.locator('input[type="password"]').nth(1).fill('N3w-master-password!')
		await form.locator('input[type="password"]').nth(2).fill('N3w-master-password!')

		await page.evaluate(() => {
			const start = Array.from(document.querySelectorAll('.compromise-recovery-form button')).find(
				(b) => /start key rotation/i.test(b.textContent || ''),
			)
			if (start) {
				(start as HTMLElement).click()
			}
		})

		// Progress lives inside the dialog, per the maintainer decision recorded
		// in design.md — not in a toast or a separate page.
		await expect(form.locator('.compromise-recovery-form__progress')).toBeVisible({ timeout: 30_000 })
		await expect(form).toContainText(/records re-encrypted|Preparing/i)
	})

	test.fixme('the terminal message reports counts and never claims the vault is secure', async ({ page }) => {
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')
		await form.locator('input[type="password"]').nth(0).fill(DEV_MASTER_PASSWORD)
		await form.locator('input[type="password"]').nth(1).fill('N3w-master-password!')
		await form.locator('input[type="password"]').nth(2).fill('N3w-master-password!')
		await page.evaluate(() => {
			const start = Array.from(document.querySelectorAll('.compromise-recovery-form button')).find(
				(b) => /start key rotation/i.test(b.textContent || ''),
			)
			if (start) {
				(start as HTMLElement).click()
			}
		})

		await expect(form).toContainText(/Key rotation finished/i, { timeout: 120_000 })
		await expect(form).toContainText(/re-encrypted under your new key/i)
		await expect(form).toContainText(/still to be considered exposed/i)
		// The message this change exists to delete.
		await expect(form).not.toContainText(/now secured with a new encryption key/i)
		await expect(form).not.toContainText(/vault is now secure/i)
	})

	test.fixme('a migrated secret carries the possibly-compromised warning afterwards', async ({ page }) => {
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await openRecoveryForm(page)

		const form = page.locator('.compromise-recovery-form')
		await form.locator('input[type="password"]').nth(0).fill(DEV_MASTER_PASSWORD)
		await form.locator('input[type="password"]').nth(1).fill('N3w-master-password!')
		await form.locator('input[type="password"]').nth(2).fill('N3w-master-password!')
		await page.evaluate(() => {
			const start = Array.from(document.querySelectorAll('.compromise-recovery-form button')).find(
				(b) => /start key rotation/i.test(b.textContent || ''),
			)
			if (start) {
				(start as HTMLElement).click()
			}
		})
		await expect(form).toContainText(/Key rotation finished/i, { timeout: 120_000 })

		await openVault(page)

		// Every migrated secret must say so on its own row — this is the surface
		// that was entirely missing before, with six consumers of the flag and no
		// producer.
		const warned = page.locator('[data-testid="secret-possibly-compromised"]')
		expect(await warned.count()).toBeGreaterThan(0)
		await expect(warned.first()).toContainText(/change it at its source/i)
	})

	test.fixme('the write lock refuses a secret write while a migration is parked', async ({ page }) => {
		// Complements the PHPUnit coverage by proving the lock reaches the real
		// HTTP surface, not just the service. Needs a migration in progress,
		// which needs a completed rotation, which needs constraint 1 resolved.
		await unlockVault(page, DEV_MASTER_PASSWORD)

		const result = await page.evaluate(async (base) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken || ''
			const res = await fetch(`${base}/api/v1/secrets`, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: token,
					'OCS-APIREQUEST': 'true',
				},
				body: JSON.stringify({ name: 'blocked-during-migration', key: 'CIPHERTEXT' }),
			})
			return { status: res.status, body: await res.text() }
		}, APP_BASE)

		expect(result.status).toBeGreaterThanOrEqual(400)
		expect(result.body).toMatch(/migration is in progress/i)
	})
})
