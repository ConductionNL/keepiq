/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Config for the two-instance federation test (sharing-federated-recipients
 * task 5.1). Its own config and directory, outside tests/e2e/, so the single
 * instance suite never lists this spec as skipped: it runs only in the job
 * that stands the pair up (compose.yaml, setup.sh).
 *
 *   FED_A_URL=http://localhost:8101 FED_B_URL=http://localhost:8102 \
 *     npx playwright test --config tests/integration/federation/playwright.config.ts
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

export default defineConfig({
	testDir: __dirname,
	testMatch: '*.spec.ts',
	timeout: 240_000,
	expect: { timeout: 20_000 },
	fullyParallel: false,
	workers: 1,
	retries: 0,
	reporter: [
		['list'],
		[
			'html',
			{
				open: 'never',
				outputFolder: path.resolve(
					__dirname,
					'../../../playwright-report-federation',
				),
			},
		],
	],
	outputDir: path.resolve(__dirname, '../../../test-results-federation'),
	use: {
		...devices['Desktop Chrome'],
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
})
