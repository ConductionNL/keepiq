import { defineConfig, defineProject } from 'vitest/config'
import { WxtVitest } from 'wxt/testing/vitest-plugin'

// WxtVitest provides the auto-imports, the `@/` alias and an in-memory `browser`. It sets these up from its own
// config hook, which an inherited (`extends`) project does not run, so each project takes the plugin itself.
const project = (name: string, include: string[], setupFiles: string[] = []) =>
	defineProject({
		plugins: [WxtVitest()],
		test: { name, include, setupFiles: ['./vitest.setup.ts', ...setupFiles], restoreMocks: true },
	})

const popupTests = ['entrypoints/**/*.test.tsx']

export default defineConfig({
	test: {
		// React tests run as in production and again under StrictMode, as the dev build renders the popup.
		projects: [
			project('unit', ['src/**/*.test.ts', 'entrypoints/**/*.test.ts', 'scripts/**/*.test.ts', 'locales/*.test.ts']),
			project('popup-prod', popupTests),
			project('popup-dev', popupTests, ['./vitest.strict.setup.ts']),
		],
	},
})
