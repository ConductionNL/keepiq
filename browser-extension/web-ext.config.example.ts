import { defineWebExtConfig } from 'wxt';

// Per-machine browser binary overrides for `wxt dev`. Copy this file to
// `web-ext.config.ts` (gitignored) — WXT only searches a hard-coded list of
// standard install locations, so non-standard installs (Thorium, Brave Beta,
// Chromium snap, Firefox flatpak, …) have to be wired in by hand.
export default defineWebExtConfig({
	binaries: {
		// chrome: '/usr/bin/thorium-browser-avx2',
		// firefox: '/usr/bin/firefox-developer-edition',
	},
});
