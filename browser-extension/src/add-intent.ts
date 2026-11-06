/** The context menu's Add, waiting in background memory for the popup it opened (ext-context-menu design.md). */
import { onBroadcast } from '@/src/background/broadcast'
import type { AddIntent } from '@/src/messages'

export const INTENT_MS = 10_000

let pending: (AddIntent & { at: number }) | null = null

/** Synchronous, so the click handler sets it before the popup it opens can ask. */
export function setIntent(add: AddIntent['add']): void {
	pending = { add, at: Date.now() }
}

/** On account switch; a lock clears it through `registerAddIntent`. */
export function clearIntent(): void {
	pending = null
}

/** Read once; `null` once expired. The caller answers `null` itself unless the active account is unlocked. */
export function takeIntent(): AddIntent | null {
	const intent = pending
	pending = null
	return intent && Date.now() - intent.at <= INTENT_MS ? { add: intent.add } : null
}

export function registerAddIntent(): void {
	onBroadcast((message) => {
		if (message.kind === 'vault.locked') clearIntent()
	})
}
