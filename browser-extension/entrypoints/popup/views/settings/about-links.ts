/** Every address the About panel links to; the Keepiq web app is the account's own. */
export const ABOUT_LINKS = {
	help: 'https://keepiq.conduction.nl/',
	reportBug: 'https://github.com/ConductionNL/keepiq/issues/new?template=bug-report.yml',
	contactSupport: 'mailto:support@conduction.nl',
	// Published from ../docs/browser-extension/privacy.md.
	privacyPolicy: 'https://keepiq.conduction.nl/docs/browser-extension/privacy/',
	/** Per store; `null` hides Rate the extension until the listing is published. */
	rate: { chrome: null, firefox: null } as Record<'chrome' | 'firefox', string | null>,
}
