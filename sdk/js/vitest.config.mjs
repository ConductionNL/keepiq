// The library's own vitest run, separate from the app's vitest.config.js.
export default {
	test: {
		environment: 'node',
		include: ['test/**/*.test.ts'],
		testTimeout: 60000,
	},
}
