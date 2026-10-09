import { vi } from 'vitest'
import type { AccountSummary, DecryptField, DecryptedItem, ItemMeta, PopupState, PopupTab, Result, TypeMeta, VaultSnapshotReply } from '@/src/messages'

export function account(overrides: Partial<AccountSummary> = {}): AccountSummary {
	return {
		id: 'a1',
		serverUrl: 'https://cloud.example.org',
		host: 'cloud.example.org',
		uid: 'alice',
		displayName: 'Alice Doe',
		avatarDataUrl: null,
		status: 'locked',
		active: true,
		...overrides,
	}
}

export function popupState(overrides: Partial<PopupState> = {}): PopupState {
	const active = overrides.active === undefined ? account() : overrides.active
	return {
		screen: 'unlock',
		accounts: active ? [active] : [],
		active,
		notice: null,
		canAddAccount: true,
		lastTab: 'vault',
		...overrides,
	}
}

export const ok = (state: PopupState = popupState()): Result => ({ ok: true, state })

/** A dispatch spy that answers every message with `result`. */
export function dispatchReturning(result: Result = ok()) {
	return vi.fn(async () => result)
}

export function itemMeta(overrides: Partial<ItemMeta> = {}): ItemMeta {
	return {
		id: 'i1', name: 'GitHub', url: 'https://github.com', typeId: 't-login', folderId: null, hasLogin: true, blocked: false,
		createdAt: '2026-01-05T10:00:00Z', updatedAt: '2026-03-02T10:00:00Z', expiresAt: null, ...overrides,
	}
}

export const types: TypeMeta[] = [
	['login', 'Login'], ['api_key', 'API key'], ['note', 'Note'], ['totp', 'TOTP'], ['card', 'Card'], ['identity', 'Identity'], ['passkey', 'Passkey'],
].map(([name, label]) => ({ id: `t-${name}`, name: name!, label: label! }))

export type UnlockedSnapshot = Extract<VaultSnapshotReply, { state: 'unlocked' }>

export function snapshotReply(overrides: Partial<UnlockedSnapshot> = {}): UnlockedSnapshot {
	return {
		state: 'unlocked',
		items: [itemMeta()],
		folders: [],
		types,
		suggestionIds: [],
		sync: { syncing: false, syncedAt: new Date().toISOString(), offline: false, lastError: null },
		webAppUrl: 'https://cloud.example.org/index.php/apps/keepiq/',
		...overrides,
	}
}

interface FakeBackground {
	state?: PopupState
	snapshot?: VaultSnapshotReply
	/** Decrypted fields per item id; an id missing here decrypts to nothing. */
	decrypted?: Record<string, DecryptedItem>
	decryptError?: 'locked' | 'blocked' | 'decrypt_failed'
	lastTab?: PopupTab
	tabUrl?: string
	/** Kinds the background fails on, which reaches the popup as no reply. */
	unanswered?: string[]
}

/** Answers each message kind the way the background would; returns the spy to inspect what was sent. */
export function fakeBackground(options: FakeBackground = {}) {
	const state = options.state ?? popupState({ screen: 'unlocked', active: account({ status: 'unlocked' }), lastTab: options.lastTab ?? 'vault' })
	const reply = vi.fn(async (message: { kind: string; ids?: string[]; fields?: DecryptField[] }): Promise<unknown> => {
		if (options.unanswered?.includes(message.kind)) return undefined
		switch (message.kind) {
			case 'vault.snapshot': return options.snapshot ?? snapshotReply()
			case 'vault.sync': return (options.snapshot as UnlockedSnapshot | undefined)?.sync ?? snapshotReply().sync
			case 'item.decrypt': {
				if (options.decryptError) return { ok: false, error: options.decryptError }
				const items = Object.fromEntries(message.ids!.map((id) => [id, Object.fromEntries(
					message.fields!.flatMap((field) => options.decrypted?.[id]?.[field] === undefined ? [] : [[field, options.decrypted[id][field]]]),
				)]))
				return { ok: true, items }
			}
			case 'popup.lastTab.set':
			case 'popup.popout':
			case 'clipboard.copied': return null
			default: return { ok: true, state }
		}
	})
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(reply as never)
	vi.spyOn(browser.tabs, 'query').mockResolvedValue([{ id: 7, windowId: 3, url: options.tabUrl ?? 'https://github.com/login' }] as never)
	return reply
}

/** The kinds sent so far, in order. */
export const sentKinds = (spy: ReturnType<typeof fakeBackground>) => spy.mock.calls.map(([message]) => message.kind)

/** Reports every observed row as visible at once, so lazy subtitles load in tests. */
export function everythingVisible() {
	vi.stubGlobal('IntersectionObserver', class {
		constructor(private callback: IntersectionObserverCallback) {}
		observe(target: Element) {
			this.callback([{ target, isIntersecting: true } as IntersectionObserverEntry], this as never)
		}
		unobserve() {}
		disconnect() {}
	})
}

/** A clipboard that records writes, or refuses them. */
export function fakeClipboard(refuse = false) {
	const writeText = vi.fn<(text: string) => Promise<void>>(async () => {
		if (refuse) throw new DOMException('Denied', 'NotAllowedError')
	})
	Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
	return writeText
}
