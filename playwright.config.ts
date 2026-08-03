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
 * Point at a running Nextcloud with PLAYWRIGHT_BASE_URL (or CI's BASE_URL).
 * There is deliberately NO default — see tests/e2e/base-url.ts. Auth:
 * NC_ADMIN_USER / NC_ADMIN_PASS (default admin/admin).
 */
import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'
import { BASE_URL } from './tests/e2e/base-url'

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
		baseURL: BASE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// Project-level testIgnore REPLACES the top-level one, so repeat the
			// shared excludes and add the visual specs (visual project only).
			testIgnore: [
				'**/node_modules/**',
				'**/custom_apps/**',
				'**/.claude/**',
				'**/visual/**',
			],
			use: {
				...devices['Desktop Chrome'],
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
		},
		// Visual-regression project (GAP-5). Opt-in / non-gating:
		//   npx playwright test --project visual
		//   npx playwright test --project visual --update-snapshots  (rebaseline)
		// Fixed viewport + authenticated session => deterministic shots.
		// Baselines live in tests/e2e/visual/*-snapshots/ and ARE committed.
		// PLATFORM CAVEAT: PNG baselines are host-font/GPU specific, so a CI
		// Linux runner will not byte-match a dev-container baseline; the visual
		// project must regenerate its baselines in-CI before it can gate.
		{
			name: 'visual',
			testMatch: /visual\/.*\.visual\.spec\.ts$/,
			use: {
				...devices['Desktop Chrome'],
				viewport: { width: 1280, height: 800 },
				storageState: path.resolve(__dirname, 'tests/e2e/.auth/admin.json'),
			},
			timeout: 90_000,
		},
	],

	testIgnore: [
		'**/node_modules/**',
		'**/custom_apps/**',
		'**/.claude/**',
	],
})
