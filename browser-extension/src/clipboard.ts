/**
 * The popup copies under the user's click. The background, which copies for the
 * context menu and clears later, needs a document: an offscreen one on Chrome MV3,
 * the persistent background page itself on Firefox MV2.
 */
import type { BackgroundToOffscreen } from '@/src/messages'
import { createQueue } from '@/src/queue'

export const CLIPBOARD_ALARM = 'clipboard-clear'
/** The account whose one-time code the background put on the clipboard, never the code (spec `totp-autofill`). */
export const CLIPBOARD_CODE_KEY = 'clipboardCode'
const OFFSCREEN_PATH = '/offscreen.html'
/** Chrome allows one offscreen document, so a clear firing during a write waits for it. */
const offscreenQueue = createQueue()

/** Popup only. Rejects when the browser refuses the write. */
export function copyText(text: string): Promise<void> {
	return navigator.clipboard.writeText(text)
}

/** Background memory stands in for `storage.session` below Firefox 115, whose background page persists. */
let codeInMemory: string | undefined

async function setCodeOf(accountId: string | undefined): Promise<void> {
	const session = browser.storage.session
	if (!session) codeInMemory = accountId
	else if (accountId === undefined) await session.remove(CLIPBOARD_CODE_KEY)
	else await session.set({ [CLIPBOARD_CODE_KEY]: accountId })
}

async function codeOf(): Promise<string | undefined> {
	const session = browser.storage.session
	if (!session) return codeInMemory
	const stored = (await session.get(CLIPBOARD_CODE_KEY))[CLIPBOARD_CODE_KEY]
	return typeof stored === 'string' ? stored : undefined
}

/**
 * Every copy goes through here, so another copy forgets a copied code.
 * `ms` is the account's `clearClipboardMs`, read by the caller so the popup and offscreen bundles stay free of the settings code.
 */
export async function scheduleClipboardClear(ms: number, now = Date.now()): Promise<void> {
	// A clear left by an earlier copy would wipe this one, also when this one is never cleared.
	await browser.alarms.clear(CLIPBOARD_ALARM)
	await setCodeOf(undefined)
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
 * `codeOf`: `text` is a one-time code of that account, which its lock clears at once.
 * Resolves to whether the copy worked; a failed one leaves any earlier clear in place.
 */
export async function writeFromBackground(text: string, clearMs: number, { codeOf, now = Date.now() }: { codeOf?: string; now?: number } = {}): Promise<boolean> {
	let written = false
	try {
		written = await inDocument({ kind: 'offscreen.writeClipboard', text })
	} catch (error) {
		console.warn('[keepiq] could not write the clipboard', error)
	}
	if (written) {
		await scheduleClipboardClear(clearMs, now)
		if (codeOf !== undefined) await setCodeOf(codeOf)
	}
	return written
}

/** Failing to clear is logged, never surfaced: the copy itself already succeeded. */
export async function clearClipboard(): Promise<void> {
	await setCodeOf(undefined)
	try {
		if (!(await inDocument({ kind: 'offscreen.clearClipboard' }))) console.warn('[keepiq] could not clear the clipboard')
	} catch (error) {
		console.warn('[keepiq] could not clear the clipboard', error)
	}
}

/** After a lock or logout: a code the locked account copied is cleared now, whatever the delay. */
export async function clearCodeAfterLock(isUnlocked: (accountId: string) => Promise<boolean>): Promise<void> {
	const accountId = await codeOf()
	if (accountId === undefined || await isUnlocked(accountId)) return
	await browser.alarms.clear(CLIPBOARD_ALARM)
	await clearClipboard()
}
