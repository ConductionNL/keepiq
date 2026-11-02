import { useCallback, useLayoutEffect, useMemo, useRef, useState, type RefObject } from 'react'
import { i18n } from '#i18n'
import type { ItemMeta, PopupTab, SendRow } from '@/src/messages'
import { request } from '../hooks/useMessage'
import type { ItemActions, ShellContextValue } from '../shell-context'
import type { FormMode } from './ItemForm'
import type { SendPrefill } from './send/NewSend'
import type { SettingsSection } from './settings/SettingsIndex'

export type FormPage = { mode: FormMode; id?: string }

/** `key` remounts the form when another item's Send opens it. */
export type SendPage = { kind: 'new'; key: number; prefill?: SendPrefill } | { kind: 'created'; row: SendRow; link: string }

export type ShellDialog =
	| { kind: 'delete'; item: ItemMeta }
	| { kind: 'move'; item: ItemMeta }
	| { kind: 'discard'; then: () => void }

/** The open pages that move the popup body's scroll position. */
export interface ScrollPages {
	pick: unknown
	detailId: string | null
	form: FormPage | null
	sendPage: SendPage | null
}

/** Keeps the popup body's scroll: Back restores the list's, pick mode's end the form's, and a send page starts at the top. */
export function useBodyScroll(bodyRef: RefObject<HTMLDivElement | null>, pages: ScrollPages) {
	const { pick, detailId, form, sendPage } = pages
	const listScroll = useRef(0)
	const onList = useRef(true)
	/** Where the form was when pick mode opened, restored when it ends. */
	const pickedFrom = useRef<{ button: HTMLElement | null; scroll: number } | null>(null)

	// Before the list's effect, which wins when the form closed too.
	useLayoutEffect(() => {
		if (!bodyRef.current) return
		if (pick) {
			bodyRef.current.scrollTop = 0
			return
		}
		const from = pickedFrom.current
		pickedFrom.current = null
		if (!from) return
		bodyRef.current.scrollTop = from.scroll
		from.button?.focus()
	}, [bodyRef, pick])

	useLayoutEffect(() => {
		onList.current = detailId === null && form === null
		if (bodyRef.current) bodyRef.current.scrollTop = onList.current ? listScroll.current : 0
	}, [bodyRef, detailId, form])

	useLayoutEffect(() => {
		if (sendPage && bodyRef.current) bodyRef.current.scrollTop = 0
	}, [bodyRef, sendPage])

	/** Opening a page from the list remembers its scroll position. */
	const leaveList = useCallback(() => {
		if (onList.current) listScroll.current = bodyRef.current?.scrollTop ?? 0
	}, [bodyRef])

	/** Pick mode opening remembers the form's scroll position and the button that opened it. */
	const keepFormPlace = useCallback((opener: HTMLElement | null) => {
		pickedFrom.current = { button: opener, scroll: bodyRef.current?.scrollTop ?? 0 }
	}, [bodyRef])

	return { leaveList, keepFormPlace }
}

/** The Send tab's pages: New send, the created send's link, and the row that takes focus back from it. */
export function useSendPages() {
	const [sendPage, setSendPage] = useState<SendPage | null>(null)
	const [sendBusy, setSendBusy] = useState(false)
	/** Back from a created send, whose page held focus: its row takes it. */
	const [sendFocus, setSendFocus] = useState<string | null>(null)

	const closeSend = useCallback(() => {
		setSendPage(null)
		setSendFocus(null)
	}, [])

	function newSend() {
		setSendFocus(null)
		setSendPage({ kind: 'new', key: Date.now() })
	}

	/** Only onto the form it came from: one left meanwhile keeps the link in the list alone. */
	function created(key: number, row: SendRow, link: string) {
		setSendBusy(false)
		setSendPage((page) => (page?.kind === 'new' && page.key === key ? { kind: 'created', row, link } : page))
	}

	return { sendPage, setSendPage, sendBusy, setSendBusy, sendFocus, setSendFocus, newSend, created, closeSend }
}

/** The tab shown and the pages open over it, other than the Send tab's. */
export function useTabPages(lastTab: PopupTab, closeSend: () => void) {
	// Read once: a later state refresh must not pull the user off the tab they picked.
	const [current, setCurrent] = useState(lastTab)
	const [detailId, setDetailId] = useState<string | null>(null)
	const [form, setForm] = useState<FormPage | null>(null)
	const [section, setSection] = useState<SettingsSection | null>(null)
	const [history, setHistory] = useState(false)

	/** Shows a tab with none of its pages open, as the tab bar does. */
	const show = useCallback((next: PopupTab) => {
		setCurrent(next)
		setDetailId(null)
		setForm(null)
		setSection(null)
		setHistory(false)
		closeSend()
		void request({ kind: 'popup.lastTab.set', tab: next })
	}, [closeSend])

	const closeForm = useCallback(() => setForm(null), [])
	const gone = useCallback(() => {
		setForm(null)
		setDetailId(null)
	}, [])

	return { current, detailId, setDetailId, form, setForm, section, setSection, history, setHistory, show, closeForm, gone }
}

/** The dialog over the shell, and the check that asks before a dirty form is dropped. */
export function useLeave(sendBusy: boolean) {
	const [dialog, setDialog] = useState<ShellDialog | null>(null)
	const leaveGuardRef = useRef<(() => boolean) | null>(null)

	/** Runs `then` now, or after the user agreed to drop a dirty form. */
	function leave(then: () => void) {
		// A create in flight would land on a page that is gone.
		if (sendBusy) return
		if (leaveGuardRef.current?.()) setDialog({ kind: 'discard', then })
		else then()
	}

	return { dialog, setDialog, leave, leaveGuardRef }
}

/** What Send on an item needs from the shell. */
export interface SendItemShell {
	refreshState: () => void
	toast: (message: string) => void
	show: (tab: PopupTab) => void
	setSendPage: (page: SendPage) => void
}

/** Decrypts the login here, so the form opens filled or not at all. */
export function useSendItem(shell: SendItemShell) {
	const { refreshState, toast, show, setSendPage } = shell
	return useCallback(async (item: ItemMeta) => {
		const reply = await request({ kind: 'item.decrypt', ids: [item.id], fields: ['login', 'key'] })
		const fields = reply?.ok ? reply.items[item.id] : undefined
		if (reply?.ok === false && reply.error === 'locked') return refreshState()
		if (!fields) return toast(i18n.t('send.prefillFailed'))
		show('send')
		setSendPage({ kind: 'new', key: Date.now(), prefill: { username: fields.login ?? '', password: fields.key ?? '' } })
	}, [refreshState, toast, show, setSendPage])
}

/** What the item actions open: a form or a dialog, from wherever the list was. */
export interface ItemPages {
	leaveList: () => void
	setForm: (form: FormPage) => void
	setDialog: (dialog: ShellDialog) => void
	sendItem: (item: ItemMeta) => Promise<void>
}

/** The actions the list's More menu and item detail share. */
export function useItemActions(pages: ItemPages): ItemActions {
	const { leaveList, setForm, setDialog, sendItem } = pages
	return useMemo<ItemActions>(() => {
		const openForm = (form: FormPage) => {
			leaveList()
			setForm(form)
		}
		return {
			add: () => openForm({ mode: 'add' }),
			edit: (id) => openForm({ mode: 'edit', id }),
			clone: (id) => openForm({ mode: 'clone', id }),
			move: (item) => setDialog({ kind: 'move', item }),
			remove: (item) => setDialog({ kind: 'delete', item }),
			send: (item) => void sendItem(item),
		}
	}, [leaveList, setForm, setDialog, sendItem])
}

/** The shell's own values that its context hands to the pages. */
export interface ContextParts extends Pick<ShellContextValue, 'accountId' | 'refreshState' | 'toast' | 'footer' | 'offline' | 'syncNow' | 'items' | 'generate'> {
	/** The window of the tab the popup belongs to, where a launched url opens. */
	windowId?: number
}

/** The context pages read, and the one for a form waiting under the generator, which keeps its Save and Cancel out of the bar. */
export function useShellContextValue(parts: ContextParts, leaveGuardRef: RefObject<(() => boolean) | null>) {
	const { accountId, refreshState, toast, windowId, footer, offline, syncNow, items, generate } = parts
	const context = useMemo<ShellContextValue>(() => ({
		accountId,
		refreshState,
		toast,
		launch: (url: string) => void browser.tabs.create({ url, windowId }).catch(() => toast(i18n.t('vault.openFailed'))),
		footer,
		offline,
		syncNow,
		items,
		setLeaveGuard: (guard: (() => boolean) | null) => {
			leaveGuardRef.current = guard
		},
		generate,
	}), [accountId, refreshState, toast, windowId, footer, offline, syncNow, items, generate, leaveGuardRef])
	const hiddenFormContext = useMemo(() => ({ ...context, footer: null }), [context])
	return { context, hiddenFormContext }
}
