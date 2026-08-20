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
	// The shared quality.yml Playwright job is `timeout-minutes: 45`, and a job
	// cancelled by that cap produces NO verdict: Playwright never prints its
	// tally, the `if: failure()` trace upload never fires, and the
	// `if: always()` report upload does not run on a cancelled job either — the
	// run you most need to read is the one that leaves nothing behind, and it
	// still renders as "fail" in `gh pr checks` while carrying no information.
	// Runs cancelled at ~45m16s have been observed in this fleet. Measured
	// overhead before `Run Playwright tests` starts is 2.0-2.4 min and the
	// uploads after it take seconds, so 38m keeps ~7 min of margin while
	// guaranteeing both a tally and the artifacts that explain it.
	globalTimeout: 38 * 60_000,
	reporter: [
		['list'],
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
	],
	outputDir: 'tests/e2e/test-results',

	use: {
		baseURL: BASE_URL,
		// `on-first-retry` writes a trace only when a retry actually happens, so
		// the trace artifact is a function of `retries`. Off CI `retries` is 0
		// above, so a local failure has never produced a trace at all; on CI it
		// traces the SECOND attempt only, which means the failure that does not
		// reproduce — the one actually worth a trace — leaves no record of the
		// attempt that failed. `retain-on-failure` traces every attempt and
		// keeps the ones that failed: strictly more informative, and
		// independent of the retry count.
		trace: 'retain-on-failure',
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

	testIgnore: ['**/node_modules/**', '**/custom_apps/**', '**/.claude/**'],
})
