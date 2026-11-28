/** Safari's clipboard, written by the containing app (spec `safari-clipboard`). Background and popup only. */
import type { ToNativeApp } from '@/src/messages'

/** Safari ignores the id and talks to the containing app's extension. */
const NATIVE_APP = 'nl.conduction.keepiq.safari'
export const NATIVE_TIMEOUT_MS = 2_000

/** No reply in time, or one of another shape: the app may have written anyway. */
export class NativeOutcomeUnknown extends Error {}

/** A rejected `sendNativeMessage` or `{ ok: false }` means the app did nothing; the reply itself is untrusted. */
async function send(request: ToNativeApp): Promise<Record<string, unknown>> {
	let timer: ReturnType<typeof setTimeout> | undefined
	const timeout = new Promise<never>((_, reject) => {
		timer = setTimeout(() => reject(new NativeOutcomeUnknown(`${request.kind}: no reply`)), NATIVE_TIMEOUT_MS)
	})
	try {
		const reply: unknown = await Promise.race([browser.runtime.sendNativeMessage(NATIVE_APP, request), timeout])
		const ok = typeof reply === 'object' && reply !== null ? (reply as { ok?: unknown }).ok : undefined
		if (ok === false) throw new Error(`${request.kind}: refused`)
		if (ok !== true) throw new NativeOutcomeUnknown(`${request.kind}: bad reply`)
		return reply as Record<string, unknown>
	} finally {
		clearTimeout(timer)
	}
}

/** Pasteboard counts are non-negative integers; anything else from an untrusted sender is ignored. */
export const isChangeCount = (value: unknown): value is number => Number.isSafeInteger(value) && (value as number) >= 0

/** Resolves to the pasteboard's `changeCount` after the write. */
export async function nativeWrite(text: string): Promise<number> {
	const { changeCount } = await send({ kind: 'clipboard.write', text })
	if (!isChangeCount(changeCount)) throw new NativeOutcomeUnknown('clipboard.write: bad reply')
	return changeCount
}

/** With `changeCount` the app clears only while nothing was copied since. Resolves to whether it cleared. */
export async function nativeClear(changeCount?: number): Promise<boolean> {
	const { cleared } = await send(changeCount === undefined ? { kind: 'clipboard.clear' } : { kind: 'clipboard.clear', changeCount })
	if (typeof cleared !== 'boolean') throw new NativeOutcomeUnknown('clipboard.clear: bad reply')
	return cleared
}
