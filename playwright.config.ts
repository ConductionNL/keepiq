/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright config for Doriath.
 *
 * One project:
 *
 *   - `chromium` — spec-coverage UI tests (Gate-19). Logs in as admin once
 *     via globalSetup and reuses the session via storageState.
 *
 * Point at a running Nextcloud with NEXTCLOUD_URL (default
 * http://localhost:8080). Auth: NC_ADMIN_USER / NC_ADMIN_PASS (default
 * admin/admin).
 */
import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

export default defineConfig({
	testDir: './tests/e2e',
	globalSetup: path.resolve(__dirname, 'tests/e2e/global-setup.ts'),
	timeout: 45_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
		},
	],

	testIgnore: [
		'**/node_modules/**',
		'**/custom_apps/**',
		'**/.claude/**',
	],
})
