/**
 * Clearing the clipboard after a copy (clients-extension-gaps). The popup
 * copies and tells the worker; the worker clears the clipboard after the
 * delay the user chose, even when the popup has closed. A service worker
 * has no clipboard, so Chromium clears through an offscreen document and
 * Firefox through its background page, which has a document.
 *
 * The clipboard is cleared whatever it holds by then: reading it would
 * need a permission that lets the extension read every copy.
 *
 * @spec openspec/changes/clients-extension-gaps/specs/extension-clipboard/spec.md#requirement-every-copy-is-cleared-after-a-delay-the-user-sets
 */

/** The delays a user can pick, in seconds; 0 means never. */
export const CLEAR_CHOICES = Object.freeze([0, 10, 20, 30, 60, 120, 300])

/** The delay before the user picks one. */
export const DEFAULT_CLEAR_SECONDS = 30

/** The alarm that clears the clipboard after a longer delay. */
export const CLEAR_ALARM = 'keepiq-clipboard'

const SETTING_KEY = 'clipboard-clear-seconds'

// Alarms fire no sooner than 30 seconds; shorter delays use a timer.
const ALARM_FLOOR_SECONDS = 30

/**
 * Build the clipboard clearer.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.local The persistent storage area.
 * @param {() => Promise<void>} deps.clear Clear the clipboard now.
 * @param {object} [deps.alarms] The alarms API.
 * @param {() => number} [deps.now] The clock, in ms.
 * @return {object}
 */
export function buildClipboardClear({
	local,
	clear,
	alarms = globalThis.chrome?.alarms,
	now = () => Date.now(),
}) {
	let timer = null

	/**
	 * The chosen delay in seconds.
	 *
	 * @return {Promise<number>}
	 */
	async function seconds() {
		const value = (await local.get(SETTING_KEY))[SETTING_KEY]
		return CLEAR_CHOICES.includes(value) ? value : DEFAULT_CLEAR_SECONDS
	}

	return {
		seconds,

		/**
		 * Store a new delay.
		 *
		 * @param {number} value Seconds, one of CLEAR_CHOICES.
		 * @return {Promise<number>} The stored delay.
		 */
		async setSeconds(value) {
			const n = Number(value)
			if (!CLEAR_CHOICES.includes(n)) throw new Error('unsupported delay')
			await local.set({ [SETTING_KEY]: n })
			return n
		},

		/**
		 * A copy just happened: clear the clipboard after the delay. A newer
		 * copy restarts the wait.
		 *
		 * @return {Promise<{clearsInSeconds: number}>}
		 */
		async copied() {
			const delay = await seconds()
			clearTimeout(timer)
			timer = null
			await alarms?.clear?.(CLEAR_ALARM)
			if (delay === 0) return { clearsInSeconds: 0 }
			if (delay < ALARM_FLOOR_SECONDS || !alarms) {
				timer = setTimeout(() => {
					timer = null
					clear().catch(() => {})
				}, delay * 1000)
			} else {
				alarms.create(CLEAR_ALARM, { when: now() + delay * 1000 })
			}
			return { clearsInSeconds: delay }
		},

		/**
		 * An alarm fired.
		 *
		 * @param {{name: string}} alarm The alarm.
		 * @return {Promise<boolean>} Whether it was this one.
		 */
		async onAlarm(alarm) {
			if (alarm?.name !== CLEAR_ALARM) return false
			await clear().catch(() => {})
			return true
		},
	}
}

/**
 * Write an empty text to the clipboard from a page with a document.
 *
 * @param {Document} doc The document.
 * @return {boolean} Whether the browser ran the copy.
 */
export function clearWithDocument(doc) {
	const onCopy = (event) => {
		event.clipboardData?.setData('text/plain', '')
		event.preventDefault()
	}
	doc.addEventListener('copy', onCopy)
	try {
		return doc.execCommand('copy') === true
	} finally {
		doc.removeEventListener('copy', onCopy)
	}
}

/**
 * Clear the clipboard now: through an offscreen document where the browser
 * has them (Chromium), else from this page's own document (Firefox).
 *
 * @return {Promise<void>}
 */
export async function clearClipboardNow() {
	const offscreen = globalThis.chrome?.offscreen
	if (offscreen) {
		const url = 'offscreen.html'
		const exists = (await offscreen.hasDocument?.()) === true
		if (!exists) {
			await offscreen.createDocument({
				url,
				reasons: ['CLIPBOARD'],
				justification: 'Clear a copied password from the clipboard',
			})
		}
		await chrome.runtime.sendMessage({ type: 'offscreen-clear-clipboard' })
		return
	}
	if (typeof document !== 'undefined') clearWithDocument(document)
}
