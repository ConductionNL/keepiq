/** The page script on Chrome, before the page's own scripts; Firefox gets `passkey-page.ts` from the relay instead. */

import { installPasskeyPage } from '@/src/passkey/page'

export default defineContentScript({
	matches: ['*://*/*'],
	allFrames: true,
	runAt: 'document_start',
	world: 'MAIN',
	include: ['chrome'],
	main() {
		installPasskeyPage()
	},
})
