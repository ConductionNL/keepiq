/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Doriath admin settings page.
 *
 * The admin settings page is a Nextcloud settings section (not gated by the
 * vault lock), so it is fully reachable and renders the Doriath Vue admin UI:
 * a CnVersionInfoCard, a Password Policy section, and a Certificate Authority
 * health section. These are real, data-independent surfaces.
 *
 * @e2e openspec/specs/admin-settings/spec.md#admin-opens-settings
 * @e2e openspec/specs/admin-settings/spec.md#version-info-card
 * @e2e openspec/specs/admin-settings/spec.md#password-policy-section
 * @e2e openspec/specs/admin-settings/spec.md#ca-health-section
 */
import * as fs from 'fs'
import * as path from 'path'
import { test, expect } from '@playwright/test'
import { collectDoriathErrors, assertNoDoriathErrors } from './_helpers'

const ADMIN_SETTINGS = '/index.php/settings/admin/doriath'

// Read the app version from appinfo/info.xml so the version-card assertion
// tracks the real deployed version instead of a hard-coded literal that breaks
// on every cache-bust bump.
const APP_VERSION = (() => {
	const infoXml = fs.readFileSync(path.resolve(__dirname, '../../../appinfo/info.xml'), 'utf8')
	return infoXml.match(/<version>([^<]+)<\/version>/)?.[1] ?? ''
})()

test.describe('Admin settings — spec: admin-settings/spec.md', () => {
	test('settings section loads with the Doriath administration heading', async ({ page }) => {
		const errors = collectDoriathErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const heading = page.locator('#app-content h1, #content h1').first()
		await expect(heading).toBeVisible({ timeout: 15_000 })
		await expect(heading).toHaveText(/Doriath/i)

		assertNoDoriathErrors(errors)
	})

	test('Version Information card shows app name and the current app version', async ({ page }) => {
		const errors = collectDoriathErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const content = page.locator('#app-content, #content').first()
		await expect(content.getByText(/Version Information/i).first()).toBeVisible({ timeout: 15_000 })
		// CnVersionInfoCard renders the app name and the current version
		// (read from appinfo/info.xml so this tracks cache-bust bumps).
		await expect(content.getByText(/Application Name/i).first()).toBeVisible()
		await expect(content.getByText(APP_VERSION, { exact: false }).first()).toBeVisible()
		// Support footer slot is rendered by AdminRoot.vue.
		await expect(content.getByText(/support@conduction\.nl/i).first()).toBeVisible()

		assertNoDoriathErrors(errors)
	})

	test('Password Policy section renders', async ({ page }) => {
		const errors = collectDoriathErrors(page)
		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })

		const content = page.locator('#app-content, #content').first()
		await expect(content.getByText(/Password Policy/i).first()).toBeVisible({ timeout: 15_000 })

		assertNoDoriathErrors(errors)
	})

	test('Certificate Authority section renders and reports Healthy status', async ({ page }) => {
		const errors = collectDoriathErrors(page)

		// Confirm the CA is healthy via the API before asserting the UI label.
		const apiRes = await page.request.get('/index.php/apps/doriath/api/v1/ca/status', {
			headers: {
				Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
				'X-Requested-With': 'XMLHttpRequest',
			},
		})
		const caHealthy = apiRes.ok() && (await apiRes.json().catch(() => ({}))).status === 'healthy'

		await page.goto(ADMIN_SETTINGS, { waitUntil: 'domcontentloaded' })
		const content = page.locator('#app-content, #content').first()
		await expect(content.getByText(/Certificate Authority/i).first()).toBeVisible({ timeout: 15_000 })

		if (caHealthy) {
			await expect(content.getByText(/Healthy|Gezond/i).first()).toBeVisible({ timeout: 15_000 })
		}

		assertNoDoriathErrors(errors)
	})
})
