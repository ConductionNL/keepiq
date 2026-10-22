import { vi } from 'vitest'
import { defaultOptions, HISTORY_LIMIT, type GeneratorHistoryEntry, type GeneratorOptions, type GeneratorPolicy } from '@/src/generator/options'
import type {
	AccountSummary, DecryptField, DecryptedItem, EditableItem, GlobalSettings, ItemMeta, PopupState, PopupTab, Result, SendCreateInput, SendRow, TypeMeta, VaultSnapshotReply,
} from '@/src/messages'

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
		createdAt: '2026-01-05T10:00:00Z', updatedAt: '2026-03-02T10:00:00Z', expiresAt: null,
		useOnly: false, readOnly: false, accessExpiresAt: null, ...overrides,
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
		defaultSecretType: null,
		folderNoticeDismissed: false,
		...overrides,
	}
}

interface FakeBackground {
	state?: PopupState
	/** A function is read per request, so a test can change the vault after a write. */
	snapshot?: VaultSnapshotReply | (() => VaultSnapshotReply)
	/** Replies by kind, for the write requests; a function gets the message. */
	replies?: Record<string, unknown>
	/** `item.fetch` replies by id. */
	items?: Record<string, EditableItem>
	/** Decrypted fields per item id; an id missing here decrypts to nothing. */
	decrypted?: Record<string, DecryptedItem>
	decryptError?: 'locked' | 'blocked' | 'use_only' | 'decrypt_failed'
	lastTab?: PopupTab
	tabUrl?: string
	/** Kinds the background fails on, which reaches the popup as no reply. */
	unanswered?: string[]
	settings?: GlobalSettings
	/** The generator's stored state; options and history change with the requests. */
	generator?: { options?: GeneratorOptions; policy?: GeneratorPolicy | null; accountEmail?: string | null; history?: GeneratorHistoryEntry[] }
	/** The server's sends; create and delete change them. */
	send?: { sends?: SendRow[]; passwordProtection?: boolean }
}

export function sendRow(overrides: Partial<SendRow> = {}): SendRow {
	return {
		id: 's1', token: 'a'.repeat(64), payloadType: 'text', hasPassword: false, maxViews: 5, viewCount: 3, remainingViews: 2,
		expiresAt: '2099-01-01T12:00:00Z', createdAt: '2026-10-22T09:30:00Z', ...overrides,
	}
}

const sendBase = 'https://cloud.example.org/index.php/apps/keepiq/public/send/'

/** Answers each message kind the way the background would; returns the spy to inspect what was sent. */
export function fakeBackground(options: FakeBackground = {}) {
	const state = options.state ?? popupState({ screen: 'unlocked', active: account({ status: 'unlocked' }), lastTab: options.lastTab ?? 'vault' })
	let settings: GlobalSettings = options.settings ?? { theme: 'system' }
	let generatorOptions = options.generator?.options ?? defaultOptions()
	let history = options.generator?.history ?? []
	let sends = options.send?.sends ?? []
	let created = 0
	const snapshot = () => (typeof options.snapshot === 'function' ? options.snapshot() : options.snapshot) ?? snapshotReply()
	const reply = vi.fn(async (message: {
		kind: string; id?: string; ids?: string[]; fields?: DecryptField[]; patch?: Partial<GlobalSettings>
		options?: GeneratorOptions; entry?: Omit<GeneratorHistoryEntry, 'createdAt'>; input?: SendCreateInput
	}): Promise<unknown> => {
		if (options.unanswered?.includes(message.kind)) return undefined
		const canned = options.replies?.[message.kind]
		if (canned !== undefined) return typeof canned === 'function' ? canned(message) : canned
		switch (message.kind) {
			case 'settings.get': return settings
			case 'settings.set': return (settings = { ...settings, ...message.patch })
			case 'vault.snapshot': return snapshot()
			case 'vault.sync': return (snapshot() as UnlockedSnapshot).sync ?? snapshotReply().sync
			case 'item.fetch': {
				const item = options.items?.[message.id!]
				return item ? { ok: true, value: item } : { ok: false, error: { code: 'not_found' } }
			}
			case 'item.create': return { ok: true, value: { id: 'new' } }
			// A save that reached everyone carries no `shareSave`.
			case 'item.update': return { ok: true, value: { id: message.id } }
			case 'item.editable': return { ok: true, value: { editable: true } }
			case 'item.delete':
			case 'folder.rename':
			case 'folder.delete': return { ok: true, value: null }
			case 'folder.create': return { ok: true, value: { id: 'f-new' } }
			case 'folder.children': return { ok: true, value: { directSecretCount: 0, subfolders: [] } }
			case 'folder.dismissNotice': return null
			case 'item.decrypt': {
				if (options.decryptError) return { ok: false, error: options.decryptError }
				const items = Object.fromEntries(message.ids!.map((id) => [id, Object.fromEntries(
					message.fields!.flatMap((field) => options.decrypted?.[id]?.[field] === undefined ? [] : [[field, options.decrypted[id][field]]]),
				)]))
				return { ok: true, items }
			}
			case 'generator.context': return { options: generatorOptions, policy: options.generator?.policy ?? null, accountEmail: options.generator?.accountEmail === undefined ? 'alice@example.org' : options.generator.accountEmail }
			case 'generator.saveOptions': return (generatorOptions = message.options!)
			case 'generator.history.list': return history
			case 'generator.history.add': return (history = [{ ...message.entry!, createdAt: new Date().toISOString() }, ...history].slice(0, HISTORY_LIMIT))
			case 'generator.history.clear': return (history = [], null)
			case 'send.list': return { ok: true, value: { sends, passwordProtection: options.send?.passwordProtection ?? true } }
			case 'send.create': {
				const input = message.input!
				const id = `s-new-${++created}`
				const token = String(created).padStart(64, '0')
				const row = sendRow({
					id, token, payloadType: input.payloadType, hasPassword: input.password !== undefined, maxViews: input.maxViews, viewCount: 0, remainingViews: input.maxViews,
					expiresAt: new Date(Date.now() + input.ttlSeconds * 1000).toISOString(), createdAt: new Date().toISOString(),
					...(input.password !== undefined && { link: `${sendBase}${token}` }),
				})
				sends = [row, ...sends]
				return { ok: true, value: { row, link: input.password === undefined ? `${sendBase}${token}#k=key${created}` : `${sendBase}${token}` } }
			}
			case 'send.delete': return (sends = sends.filter((row) => row.id !== message.id), { ok: true, value: null })
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

/** Its `typeName` is what `types` calls its type, unless given. */
export function editableItem(overrides: Partial<EditableItem> = {}): EditableItem {
	const typeId = overrides.typeId ?? 't-login'
	return {
		id: 'i1', typeId, typeName: types.find((type) => type.id === typeId)?.name, name: 'GitHub', url: 'https://github.com', folderId: null, key: 'hunter2', login: 'alice',
		additionalFields: {}, updatedAt: '2026-03-02T10:00:00Z', ...overrides,
	}
}

/** The messages of one kind sent so far. */
export const sentOf = (spy: ReturnType<typeof fakeBackground>, kind: string) => spy.mock.calls.map(([message]) => message as Record<string, unknown>).filter((message) => message.kind === kind)

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
