/**
 * The popup copies under the user's click. The background, which copies for the
 * context menu and clears later, needs a document: an offscreen one on Chrome MV3,
 * the persistent background page itself on Firefox MV2. Safari has neither, so both
 * go through the containing app there. `import.meta.env.BROWSER === 'safari'` stays inline,
 * not `isSafari()`, so other builds drop Safari's code.
 */
import type { BackgroundToOffscreen } from '@/src/messages'
import { createQueue } from '@/src/queue'
import { nativeClear, NativeOutcomeUnknown, nativeWrite } from '@/src/safari-native'

export const CLIPBOARD_ALARM = 'clipboard-clear'
/** The account whose one-time code the background put on the clipboard, never the code (spec `totp-autofill`). */
export const CLIPBOARD_CODE_KEY = 'clipboardCode'
/** Safari's pasteboard `changeCount` after Keepiq's last write, never the value (spec `safari-clipboard`). */
export const CLIPBOARD_COUNT_KEY = 'clipboardChangeCount'
const OFFSCREEN_PATH = '/offscreen.html'
/** Chrome allows one offscreen document, so a clear firing during a write waits instead of creating a second. */
const offscreenQueue = createQueue()
/** Safari: a clear must not run between reading and removing the count, nor during a native write. */
const nativeQueue = createQueue()

/**
 * Popup only. Rejects when the browser refuses the write, with `NativeOutcomeUnknown` when Safari's app may still write. Resolves to Safari's pasteboard count when the
 * containing app wrote; a dev build without the app (as under WebDriver) writes itself.
 */
export async function copyText(text: string): Promise<number | undefined> {
	if (import.meta.env.BROWSER === 'safari') {
		try {
			return await nativeWrite(text)
		} catch (error) {
			console.warn('[keepiq] the Keepiq app could not write the clipboard', error)
			// That write would skip `.currentHostOnly` and the concealed type, so a release reports the failure instead.
			if (import.meta.env.MODE === 'production') throw error
		}
	}
	await navigator.clipboard.writeText(text)
	return undefined
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

/** Safari only, which always has `storage.session`. */
async function setCount(changeCount: number | undefined): Promise<void> {
	if (changeCount === undefined) await browser.storage.session.remove(CLIPBOARD_COUNT_KEY)
	else await browser.storage.session.set({ [CLIPBOARD_COUNT_KEY]: changeCount })
}

/**
 * Every copy goes through here, so another copy forgets a copied code.
 * `ms` is the account's `clearClipboardMs`, read by the caller so the popup and offscreen bundles stay free of the settings code.
 * `changeCount`: Safari's count after the write; a copy without one is cleared unconditionally.
 */
export async function scheduleClipboardClear(ms: number, now = Date.now(), changeCount?: number): Promise<void> {
	// A clear left by an earlier copy would wipe this one, also when this one is never cleared.
	await browser.alarms.clear(CLIPBOARD_ALARM)
	await setCodeOf(undefined)
	// Kept under Never too: a lock still clears a copied code.
	if (import.meta.env.BROWSER === 'safari') await nativeQueue(() => setCount(changeCount))
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

/**
 * Resolves to whether the document wrote; rejects when the offscreen document could not be used.
 * The document stays open: Chrome commits its write after it answered, and closing it then can drop the write.
 */
async function inDocument(message: BackgroundToOffscreen): Promise<boolean> {
	const text = message.kind === 'offscreen.writeClipboard' ? message.text : ''
	const offscreen = browser.offscreen
	if (!offscreen) return writeClipboard(text)
	return offscreenQueue(async () => {
		if (!(await offscreen.hasDocument())) {
			await offscreen.createDocument({ url: browser.runtime.getURL(OFFSCREEN_PATH), reasons: ['CLIPBOARD'], justification: 'Copy secrets to the clipboard and clear them again' })
		}
		return await browser.runtime.sendMessage(message) === true
	})
}

/**
 * Background only: copies `text`, then schedules the clear after `clearMs`, the account's `clearClipboardMs`.
 * `codeOf`: `text` is a one-time code of that account, which its lock clears at once.
 * Resolves to whether the copy worked; a failed one leaves any earlier clear in place, unless Safari's app may have written late.
 */
export async function writeFromBackground(text: string, clearMs: number, { codeOf, now = Date.now() }: { codeOf?: string; now?: number } = {}): Promise<boolean> {
	let written = false
	let changeCount: number | undefined
	let unknown = false
	try {
		if (import.meta.env.BROWSER === 'safari') {
			changeCount = await nativeQueue(() => nativeWrite(text))
			written = true
		} else {
			written = await inDocument({ kind: 'offscreen.writeClipboard', text })
		}
	} catch (error) {
		unknown = error instanceof NativeOutcomeUnknown
		console.warn('[keepiq] could not write the clipboard', error)
	}
	if (written || unknown) {
		await scheduleClipboardClear(clearMs, now, changeCount)
		if (codeOf !== undefined) await setCodeOf(codeOf)
	}
	return written
}

/** Safari: clears only while the pasteboard still holds Keepiq's last write, when its count is known. */
function clearNative(): Promise<boolean> {
	return nativeQueue(async () => {
		const stored = (await browser.storage.session.get(CLIPBOARD_COUNT_KEY))[CLIPBOARD_COUNT_KEY]
		await setCount(undefined)
		// `false` means someone copied since, which is not a failure.
		await nativeClear(typeof stored === 'number' ? stored : undefined)
		return true
	})
}

/** Failing to clear is logged, never surfaced: the copy itself already succeeded. */
export async function clearClipboard(): Promise<void> {
	await setCodeOf(undefined)
	try {
		const cleared = import.meta.env.BROWSER === 'safari' ? await clearNative() : await inDocument({ kind: 'offscreen.clearClipboard' })
		if (!cleared) console.warn('[keepiq] could not clear the clipboard')
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
