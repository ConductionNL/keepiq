/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares two:
 *
 *   chromium — the spec-coverage + workflow regression suite. CI wants this.
 *   visual   — pixel-diff baselines. Its own header states the PNGs are
 *              host-font/GPU specific and "a CI Linux runner will not
 *              byte-match a dev-container baseline", so it cannot pass here.
 *
 * Letting the root config be picked therefore runs a project that is
 * documented as unable to pass on a CI runner. Rather than delete or weaken
 * it, `playwright-test-path: tests/e2e` in the caller makes the workflow's
 * FIRST lookup hit this file, which declares only the regression project. The
 * root config is untouched and stays the entry point for local runs and
 * `--project visual`.
 *
 * The report/output paths also differ deliberately: the workflow uploads
 * `server/apps/<app>/playwright-report/` and `server/apps/<app>/test-results/`,
 * so on CI the artifacts must land at the APP ROOT, not under `tests/e2e/`.
 * With the root config's paths the "Upload Playwright report" step matches
 * nothing and silently uploads an empty artifact (`if-no-files-found: ignore`)
 * — a failing run with no report to read.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { BASE_URL } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// Mirrors the root config's chromium project ignore list. A
			// project-level `testIgnore` REPLACES the top-level one, so every
			// entry the root carries has to be repeated here rather than
			// inherited — `**/visual/**` in particular, because the visual
			// project's committed PNG baselines cannot byte-match a CI runner.
			testIgnore: [
				'**/node_modules/**',
				'**/custom_apps/**',
				'**/.claude/**',
				'**/visual/**',
			],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
