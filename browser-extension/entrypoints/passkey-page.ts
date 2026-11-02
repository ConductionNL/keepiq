/** The page script on Firefox, whose MV2 has no `MAIN` world content script: the relay injects it (design.md § Injecting the page script). */

import { installPasskeyPage } from '@/src/passkey/page'

export default defineUnlistedScript({
	include: ['firefox'],
	main() {
		installPasskeyPage()
	},
})
