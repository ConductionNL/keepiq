/**
 * The popup copies under the user's click. The background, which copies for the
 * context menu and clears later, needs a document: an offscreen one on Chrome MV3,
 * the persistent background page itself on Firefox MV2.
 */
import type { BackgroundToOffscreen } from '@/src/messages'
import { createQueue } from '@/src/queue'

export const CLIPBOARD_ALARM = 'clipboard-clear'
const OFFSCREEN_PATH = '/offscreen.html'
/** Chrome allows one offscreen document, so a clear firing during a write waits for it. */
const offscreenQueue = createQueue()

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

/** Replaces the clipboard with `text`, without a user gesture; needs a document allowed to write it. */
export function writeClipboard(text: string): boolean {
	const onCopy = (event: ClipboardEvent) => {
		event.clipboardData?.setData('text/plain', text)
		event.preventDefault()
	}
	document.addEventListener('copy', onCopy)
	try {
		return document.execCommand('copy')
	} finally {
		document.removeEventListener('copy', onCopy)
	}
}

/** Resolves to whether the document wrote; rejects when the offscreen document could not be used. */
async function inDocument(message: BackgroundToOffscreen): Promise<boolean> {
	const text = message.kind === 'offscreen.writeClipboard' ? message.text : ''
	const offscreen = browser.offscreen
	if (!offscreen) return writeClipboard(text)
	return offscreenQueue(async () => {
		if (!(await offscreen.hasDocument())) {
			await offscreen.createDocument({ url: browser.runtime.getURL(OFFSCREEN_PATH), reasons: ['CLIPBOARD'], justification: 'Copy secrets to the clipboard and clear them again' })
		}
		try {
			return await browser.runtime.sendMessage(message) === true
		} finally {
			await offscreen.closeDocument()
		}
	})
}

/**
 * Background only: copies `text`, then schedules the clear after `clearMs`, the account's `clearClipboardMs`.
 * Resolves to whether the copy worked; a failed one leaves any earlier clear in place.
 */
export async function writeFromBackground(text: string, clearMs: number, now = Date.now()): Promise<boolean> {
	let written = false
	try {
		written = await inDocument({ kind: 'offscreen.writeClipboard', text })
	} catch (error) {
		console.warn('[keepiq] could not write the clipboard', error)
	}
	if (written) await scheduleClipboardClear(clearMs, now)
	return written
}

/** Failing to clear is logged, never surfaced: the copy itself already succeeded. */
export async function clearClipboard(): Promise<void> {
	try {
		if (!(await inDocument({ kind: 'offscreen.clearClipboard' }))) console.warn('[keepiq] could not clear the clipboard')
	} catch (error) {
		console.warn('[keepiq] could not clear the clipboard', error)
	}
}
