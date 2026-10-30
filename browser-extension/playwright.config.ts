import { defineConfig } from '@playwright/test'

// Never 8100: `npm run dev` serves the test site there. The site also takes the next port.
const port = Number(process.env.E2E_SITE_PORT ?? 8200)

// Chromium only: Playwright cannot load a WebExtension into Firefox.
export default defineConfig({
	testDir: './e2e',
	globalSetup: './e2e/global-setup.ts',
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	reporter: process.env.CI ? [['list'], ['github']] : 'list',
	expect: { timeout: 10_000 },
	use: { baseURL: `http://localhost:${port}` },
})
