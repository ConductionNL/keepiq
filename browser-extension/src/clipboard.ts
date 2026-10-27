/**
 * Copy runs in the popup under the user's click. Clearing runs later from the
 * background, which needs a document: an offscreen one on Chrome MV3, the
 * persistent background page itself on Firefox MV2.
 */
import type { BackgroundToOffscreen } from '@/src/messages'

export const CLIPBOARD_ALARM = 'clipboard-clear'
const OFFSCREEN_PATH = '/offscreen.html'

/** Popup only. Rejects when the browser refuses the write. */
export function copyText(text: string): Promise<void> {
	return navigator.clipboard.writeText(text)
}

/** `ms` is the account's `clearClipboardMs`, read by the caller so the popup and offscreen bundles stay free of the settings code. */
export async function scheduleClipboardClear(ms: number, now = Date.now()): Promise<void> {
	// A clear left by an earlier copy would wipe this one, also when this one is never cleared.
	await browser.alarms.clear(CLIPBOARD_ALARM)
	if (ms !== 0) await browser.alarms.create(CLIPBOARD_ALARM, { when: now + ms })
}

/** Replaces the clipboard with an empty string; needs a document allowed to write it. */
export function overwriteClipboard(): boolean {
	const onCopy = (event: ClipboardEvent) => {
		event.clipboardData?.setData('text/plain', '')
		event.preventDefault()
	}
	document.addEventListener('copy', onCopy)
	try {
		return document.execCommand('copy')
	} finally {
		document.removeEventListener('copy', onCopy)
	}
}

/** Failing to clear is logged, never surfaced: the copy itself already succeeded. */
export async function clearClipboard(): Promise<void> {
	try {
		if (!browser.offscreen) {
			if (!overwriteClipboard()) console.warn('[keepiq] could not clear the clipboard')
			return
		}
		const url = browser.runtime.getURL(OFFSCREEN_PATH)
		if (!(await browser.offscreen.hasDocument())) {
			await browser.offscreen.createDocument({ url, reasons: ['CLIPBOARD'], justification: 'Clear copied secrets from the clipboard' })
		}
		const message: BackgroundToOffscreen = { kind: 'offscreen.clearClipboard' }
		let cleared: unknown
		try {
			cleared = await browser.runtime.sendMessage(message)
		} finally {
			await browser.offscreen.closeDocument()
		}
		if (cleared !== true) console.warn('[keepiq] could not clear the clipboard')
	} catch (error) {
		console.warn('[keepiq] could not clear the clipboard', error)
	}
}
