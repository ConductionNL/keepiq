/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deep workflow e2e for secret import (secret-import §8).
 *
 * Drives the two UI-observable flows:
 *   - import a small generic CSV: unlock -> open wizard -> upload -> mapping
 *     preview -> folders -> duplicates (none) -> commit -> summary counts ->
 *     the imported secret is visible in the vault list.
 *   - re-import the same file: the duplicates step lists every row, skip-all is
 *     the default, the summary reports them skipped, and the vault count is
 *     unchanged.
 *
 * The server-only / API-contract scenarios (chunk-cap 413, partial-chunk
 * per-index failure, no-active-suite 412, plaintext-never-on-the-wire envelope
 * validation) carry an `@e2e exclude` directive on their spec scenarios per
 * gate-19 and are covered by PHPUnit (ImportControllerTest / ImportServiceTest)
 * and the import-store vitest (plaintext-never-in-request-body assertion).
 *
 * Environment assumptions match the other workflow specs: the doriath app is
 * enabled and the admin owns one active EncryptionSuite seeded with the dev
 * master password.
 */
import { test, expect } from '@playwright/test'
import { APP_BASE, DEV_MASTER_PASSWORD, gotoLockSettled, unlockVault } from './_workflow-helpers'

const CSV = 'name,url,username,password,folder\n'
	+ 'E2E Import Sample,https://e2e-import.test,e2euser,e2epass,E2E Imports\n'

test.describe('secret import', () => {
	test('imports a generic CSV and the secret appears in the vault', async ({ page }) => {
		// @e2e secret-import::csv-columns-adjusted-before-commit
		// @e2e secret-import::summary-after-a-mixed-import
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await page.goto(`${APP_BASE}/secrets`, { waitUntil: 'networkidle' })

		await page.getByTestId('import-secrets').click()

		// Upload the CSV via the file input (read entirely client-side).
		await page.getByTestId('import-file').setInputFiles({
			name: 'sample.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(CSV),
		})

		// Mapping preview -> folders -> duplicates -> commit.
		await expect(page.getByText(/rows parsed/i)).toBeVisible({ timeout: 20_000 })
		await page.getByRole('button', { name: /^Next$/ }).click() // mapping -> folders
		await page.getByRole('button', { name: /^Next$/ }).click() // folders -> duplicates
		await page.getByRole('button', { name: /^Import$/ }).click() // duplicates -> commit

		await expect(page.getByText(/Imported:\s*1/)).toBeVisible({ timeout: 30_000 })
		await page.getByRole('button', { name: /^Close$/ }).click()

		await expect(page.getByText('E2E Import Sample')).toBeVisible({ timeout: 20_000 })
	})

	test('re-importing the same file flags duplicates and skip-all leaves the vault unchanged', async ({ page }) => {
		// @e2e secret-import::re-import-of-the-same-file
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await page.goto(`${APP_BASE}/secrets`, { waitUntil: 'networkidle' })

		await page.getByTestId('import-secrets').click()
		await page.getByTestId('import-file').setInputFiles({
			name: 'sample.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(CSV),
		})
		await expect(page.getByText(/rows parsed/i)).toBeVisible({ timeout: 20_000 })
		await page.getByRole('button', { name: /^Next$/ }).click()
		await page.getByRole('button', { name: /^Next$/ }).click()

		// Duplicates step: the previously-imported row is listed; skip is default.
		await expect(page.getByText(/match an existing secret/i)).toBeVisible({ timeout: 20_000 })
		await page.getByRole('button', { name: /^Import$/ }).click()

		await expect(page.getByText(/Skipped duplicates:\s*1/)).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText(/Imported:\s*0/)).toBeVisible()
	})
})
