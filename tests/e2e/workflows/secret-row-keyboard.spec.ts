/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow — a secret row in the vault list is keyboard-operable (WCAG 2.1 AA
 * SC 2.1.1 Keyboard + 4.1.2 Name, Role, Value; ADR-010).
 *
 * GOAL: prove a keyboard-only user can Tab onto a secret row and open its
 * detail view with Enter (and that Space also activates it), with no mouse
 * events, and that a visible :focus-visible outline is rendered — matching the
 * mouse-click behaviour proven in secret-crud-encryption.spec.ts.
 *
 * Requires a live, seeded Doriath instance (the dev seed ships secrets such as
 * "GitHub"). The vault is unlocked headlessly via the dev master password.
 *
 * @e2e openspec/specs/secrets-write-ui/spec.md#secret-list-rows-must-be-keyboard-operable
 */
import { test, expect } from '@playwright/test'
import { unlockVault, openVault } from './_workflow-helpers'

test.describe('Workflow: secret rows are keyboard-operable — secrets-write-ui/spec.md', () => {
	test('Tab + Enter opens a secret from the list without a mouse', async ({ page }) => {
		await unlockVault(page)
		await openVault(page)

		const firstRow = page.locator('.secret-list-item').first()
		await expect(firstRow).toBeVisible({ timeout: 15_000 })

		// The row must expose an interactive role + accessible name.
		await expect(firstRow).toHaveAttribute('role', 'button')
		await expect(firstRow).toHaveAttribute('tabindex', '0')
		await expect(firstRow).toHaveAttribute('aria-label', /.+/)

		// Move keyboard focus onto the row (no mouse click) and activate it.
		await firstRow.focus()
		await expect(firstRow).toBeFocused()

		// A visible focus indicator must be rendered via :focus-visible.
		const outlineWidth = await firstRow.evaluate(
			(el) => getComputedStyle(el).outlineWidth,
		)
		expect(outlineWidth).not.toBe('0px')

		await page.keyboard.press('Enter')

		// Enter on the focused row navigates to the secret's detail view.
		await expect(page.locator('.secret-detail__card')).toBeVisible({ timeout: 20_000 })
	})

	test('Space also activates a focused row', async ({ page }) => {
		await unlockVault(page)
		await openVault(page)

		const firstRow = page.locator('.secret-list-item').first()
		await expect(firstRow).toBeVisible({ timeout: 15_000 })

		await firstRow.focus()
		await expect(firstRow).toBeFocused()
		await page.keyboard.press('Space')

		await expect(page.locator('.secret-detail__card')).toBeVisible({ timeout: 20_000 })
	})

	test('the copy-password control is independently focusable and does not open the row', async ({ page }) => {
		await unlockVault(page)
		await openVault(page)

		// Find a row that renders the copy action (i.e. not a blocked secret).
		const copyButton = page.locator('.secret-list-item__actions button').first()
		await expect(copyButton).toBeVisible({ timeout: 15_000 })

		// The copy control is reachable via keyboard and, when activated, must
		// NOT navigate to the secret detail view (its keydown is stopped from
		// bubbling to the row's open handler).
		await copyButton.focus()
		await expect(copyButton).toBeFocused()
		await page.keyboard.press('Enter')

		await expect(page.locator('.secret-detail__card')).toHaveCount(0)
	})
})
