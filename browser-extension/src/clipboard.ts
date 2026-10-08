/**
 * Copy runs in the popup under the user's click. Clearing runs later from the
 * background, which needs a document: an offscreen one on Chrome MV3, the
 * persistent background page itself on Firefox MV2.
 */
import { settingsKey } from '@/src/accounts/settings'
import type { BackgroundToOffscreen } from '@/src/messages'

export const CLIPBOARD_ALARM = 'clipboard-clear'
const OFFSCREEN_PATH = '/offscreen.html'

/** Popup only. Rejects when the browser refuses the write. */
export function copyText(text: string): Promise<void> {
	return navigator.clipboard.writeText(text)
}

/** The delay arrives with ext-settings; absent means never clear. */
async function clearDelayMs(accountId: string): Promise<number | null> {
	const key = settingsKey(accountId)
	const stored = (await browser.storage.local.get(key))[key] as { clearClipboardMs?: unknown } | undefined
	const ms = stored?.clearClipboardMs
	return typeof ms === 'number' && ms > 0 ? ms : null
}

export async function scheduleClipboardClear(accountId: string, now = Date.now()): Promise<void> {
	const ms = await clearDelayMs(accountId)
	if (ms !== null) await browser.alarms.create(CLIPBOARD_ALARM, { when: now + ms })
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
