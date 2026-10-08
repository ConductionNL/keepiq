import { defineConfig } from 'vitest/config'
import { WxtVitest } from 'wxt/testing/vitest-plugin'

// WxtVitest provides the auto-imports, the `@/` alias and an in-memory `browser`.
export default defineConfig({
	plugins: [WxtVitest()],
	test: {
		include: ['src/**/*.test.ts', 'entrypoints/**/*.test.{ts,tsx}', 'scripts/**/*.test.ts'],
		restoreMocks: true,
	},
})
