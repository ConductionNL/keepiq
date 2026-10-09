import { defineWebExtConfig } from 'wxt';

// Per-machine browser binary overrides for `wxt dev`. Copy this file to
// `web-ext.config.ts` (gitignored) when the system default browser
// (scripts/default-browser.ts) isn't the one you want, or can't be detected.
export default defineWebExtConfig({
	binaries: {
		// chrome: '/usr/bin/thorium-browser-avx2',
		// firefox: '/usr/bin/firefox-developer-edition',
	},
});
