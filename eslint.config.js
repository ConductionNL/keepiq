const {
	defineConfig,
} = require('@eslint/config-helpers')

const js = require('@eslint/js')

const {
	FlatCompat,
} = require('@eslint/eslintrc')

// Shared Vue 3 rule layer, published inside @conduction/nextcloud-vue.
//
// It is an ARRAY OF THREE configs, not one object, and it registers no
// plugins — which is why it layers cleanly on top of the @nextcloud v8 base
// and must be spread LAST. Do NOT adopt `@nextcloud/eslint-config/vue3`
// directly: it sets `parserOptions.parser` to a bare string, which routes
// template expressions through @typescript-eslint/parser, drops `v-for`
// scope, and manufactures hundreds of bogus `vue/valid-v-for` errors.
const {
	conductionVue3Fixes,
} = require('@conduction/nextcloud-vue/eslint')

const compat = new FlatCompat({
	baseDirectory: __dirname,
	recommendedConfig: js.configs.recommended,
	allConfig: js.configs.all,
})

module.exports = defineConfig([{
	extends: compat.extends('@nextcloud'),

	settings: {
		'import/resolver': {
			alias: {
				map: [
					['@', './src'],
					['@floating-ui/dom-actual', './node_modules/@floating-ui/dom'],
					['@conduction/nextcloud-vue', '../nextcloud-vue/src'],
				],
				extensions: ['.js', '.ts', '.vue', '.json', '.css'],
			},
		},
	},

	rules: {
		// Allow unused i18n functions (t, n) — imported for future translation wiring
		'no-unused-vars': ['error', { varsIgnorePattern: '^(t|n)$', argsIgnorePattern: '^_' }],
		'jsdoc/require-jsdoc': 'off',
		// `@spec` / `@e2e` are the fleet's traceability tags (ADR-020, hydra
		// gate-16 / gate-19), used deliberately throughout this codebase.
		// jsdoc/check-tag-names does not know them and reported all 272 of
		// them as "Invalid JSDoc tag name", which buried every other warning.
		// Declaring them is the correct fix — not a suppression.
		'jsdoc/check-tag-names': ['warn', { definedTags: ['spec', 'e2e'] }],
		'vue/first-attribute-linebreak': 'off',
		'@typescript-eslint/no-explicit-any': 'off',
		'n/no-missing-import': 'off',
		'import/namespace': 'off', // disable namespace checking to avoid parser requirement
		'import/default': 'off', // disable default import checking to avoid parser requirement
		'import/no-named-as-default': 'off', // disable named-as-default checking to avoid parser requirement
		'import/no-named-as-default-member': 'off', // disable named-as-default-member checking to avoid parser requirement
	},
}, {
	// The MV3 browser extension runs in a WebExtension runtime (chrome.* / browser.*)
	// and in page/service-worker contexts, not the Nextcloud web app.
	files: ['browser-extension/**/*.js'],
	languageOptions: {
		globals: {
			chrome: 'readonly',
			browser: 'readonly',
		},
	},
},
// Spread LAST so the Vue 3 rules win over the Vue-2-era @nextcloud base.
// Without this layer ZERO `vue/no-deprecated-*` rules are active, so Vue-2
// survivals (beforeDestroy, .sync, filters) lint clean while being silently
// ignored at runtime.
...conductionVue3Fixes,
])
