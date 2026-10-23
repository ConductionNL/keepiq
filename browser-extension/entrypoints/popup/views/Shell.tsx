import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, ItemMeta, PopupTab, SendRow, ShareSaveNote } from '@/src/messages'
import { folderPath } from '@/src/vault/list'
import { webUrl } from '@/src/vault/url'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Loading } from '../components/Loading'
import { TabBar, tabLabel } from '../components/TabBar'
import { Toast } from '../components/Toast'
import { errorText } from '../errors'
import { isPopout, useCurrentTab } from '../hooks/useCurrentTab'
import { request } from '../hooks/useMessage'
import { SendProvider } from '../hooks/useSends'
import { useToast } from '../hooks/useToast'
import { useVaultSnapshot } from '../hooks/useVaultSnapshot'
import { ShellContext, type GenerateTarget, type ItemActions, type ShellContextValue } from '../shell-context'
import { Generator } from './Generator'
import { GeneratorHistory } from './GeneratorHistory'
import { DeleteItemDialog, MoveItemDialog } from './ItemDialogs'
import { ItemDetail } from './ItemDetail'
import { ItemForm, type FormMode } from './ItemForm'
import { NewSend, type SendPrefill } from './send/NewSend'
import { SendCreated } from './send/SendCreated'
import { SendList } from './send/SendList'
import { Settings } from './settings/Settings'
import { SECTION_PARENT, sectionLabel, type SettingsSection } from './settings/SettingsIndex'
import { VaultList } from './VaultList'

interface Props {
	active: AccountSummary
	lastTab: PopupTab
	/** Under the account switcher. */
	hidden?: boolean
	onAccounts: () => void
	refreshState: () => void
}

type FormPage = { mode: FormMode; id?: string }

type Pick = { target: GenerateTarget; apply: (value: string) => void }

/** `key` remounts the form when another item's Send opens it. */
type SendPage = { kind: 'new'; key: number; prefill?: SendPrefill } | { kind: 'created'; row: SendRow; link: string }

type ShellDialog =
	| { kind: 'delete'; item: ItemMeta }
	| { kind: 'move'; item: ItemMeta }
	| { kind: 'discard'; then: () => void }

/** The unlocked popup: tabs, and pages such as an item's detail, its form or a settings section opened over them. */
export function Shell({ active, lastTab, hidden = false, onAccounts, refreshState }: Props) {
	const tab = useCurrentTab()
	// Read once: a later state refresh must not pull the user off the tab they picked.
	const [current, setCurrent] = useState(lastTab)
	const [detailId, setDetailId] = useState<string | null>(null)
	const [form, setForm] = useState<FormPage | null>(null)
	const [section, setSection] = useState<SettingsSection | null>(null)
	const [history, setHistory] = useState(false)
	const [pick, setPick] = useState<Pick | null>(null)
	const [sendPage, setSendPage] = useState<SendPage | null>(null)
	const [sendBusy, setSendBusy] = useState(false)
	/** Back from a created send, whose page held focus: its row takes it. */
	const [sendFocus, setSendFocus] = useState<string | null>(null)
	const [dialog, setDialog] = useState<ShellDialog | null>(null)
	// In memory only, so it is gone once the popup closes (share-sync).
	const [shareSave, setShareSave] = useState<{ id: string; note: ShareSaveNote } | null>(null)
	const { message: toast, show: showToast } = useToast()
	const [footer, setFooter] = useState<HTMLElement | null>(null)
	const body = useRef<HTMLDivElement>(null)
	const listScroll = useRef(0)
	const onList = useRef(true)
	const leaveGuard = useRef<(() => boolean) | null>(null)
	/** Where the form was when pick mode opened, restored when it ends. */
	const pickedFrom = useRef<{ button: HTMLElement | null; scroll: number } | null>(null)
	const { reply, failed, syncNow, reload } = useVaultSnapshot(tab?.url, tab !== undefined)

	// Pick mode belongs to the form under it.
	if (pick && !form) setPick(null)

	useEffect(() => {
		if (reply && reply.state !== 'unlocked') refreshState()
	}, [reply, refreshState])

	// Before the list's effect, which wins when the form closed too.
	useLayoutEffect(() => {
		if (!body.current) return
		if (pick) {
			body.current.scrollTop = 0
			return
		}
		const from = pickedFrom.current
		pickedFrom.current = null
		if (!from) return
		body.current.scrollTop = from.scroll
		from.button?.focus()
	}, [pick])

	// Back restores where the list was scrolled.
	useLayoutEffect(() => {
		onList.current = detailId === null && form === null
		if (body.current) body.current.scrollTop = onList.current ? listScroll.current : 0
	}, [detailId, form])

	useLayoutEffect(() => {
		if (sendPage && body.current) body.current.scrollTop = 0
	}, [sendPage])

	/** Opening a page from the list remembers its scroll position. */
	const leaveList = useCallback(() => {
		if (onList.current) listScroll.current = body.current?.scrollTop ?? 0
	}, [])

	/** Shows a tab with none of its pages open, as the tab bar does. */
	const show = useCallback((next: PopupTab) => {
		setCurrent(next)
		setDetailId(null)
		setForm(null)
		setSection(null)
		setHistory(false)
		setSendPage(null)
		setSendFocus(null)
		void request({ kind: 'popup.lastTab.set', tab: next })
	}, [])

	/** Decrypts the login here, so the form opens filled or not at all. */
	const sendItem = useCallback(async (item: ItemMeta) => {
		const reply = await request({ kind: 'item.decrypt', ids: [item.id], fields: ['login', 'key'] })
		const fields = reply?.ok ? reply.items[item.id] : undefined
		if (reply?.ok === false && reply.error === 'locked') return refreshState()
		if (!fields) return showToast(i18n.t('send.prefillFailed'))
		show('send')
		setSendPage({ kind: 'new', key: Date.now(), prefill: { username: fields.login ?? '', password: fields.key ?? '' } })
	}, [refreshState, showToast, show])

	const items = useMemo<ItemActions>(() => ({
		add: () => {
			leaveList()
			setForm({ mode: 'add' })
		},
		edit: (id) => {
			leaveList()
			setForm({ mode: 'edit', id })
		},
		clone: (id) => {
			leaveList()
			setForm({ mode: 'clone', id })
		},
		move: (item) => setDialog({ kind: 'move', item }),
		remove: (item) => setDialog({ kind: 'delete', item }),
		send: (item) => void sendItem(item),
	}), [leaveList, sendItem])

	const snapshot = reply?.state === 'unlocked' ? reply : undefined

	// The opener, not `document.activeElement`: Firefox on macOS and Safari don't focus a clicked button.
	const generate = useCallback((target: GenerateTarget, apply: (value: string) => void, opener: HTMLElement | null) => {
		pickedFrom.current = { button: opener, scroll: body.current?.scrollTop ?? 0 }
		setPick({ target, apply })
	}, [])

	const context = useMemo<ShellContextValue>(() => ({
		accountId: active.id,
		refreshState,
		toast: showToast,
		launch: (url: string) => void browser.tabs.create({ url, windowId: tab?.windowId }).catch(() => showToast(i18n.t('vault.openFailed'))),
		footer,
		offline: snapshot?.sync.offline ?? false,
		syncNow: () => void syncNow(),
		items,
		setLeaveGuard: (guard: (() => boolean) | null) => {
			leaveGuard.current = guard
		},
		generate,
	}), [active.id, refreshState, showToast, tab?.windowId, footer, snapshot?.sync.offline, syncNow, items, generate])
	// The form under the generator keeps its Save and Cancel out of the bar.
	const hiddenFormContext = useMemo(() => ({ ...context, footer: null }), [context])

	function newSend() {
		setSendFocus(null)
		setSendPage({ kind: 'new', key: Date.now() })
	}

	/** Only onto the form it came from: one left meanwhile keeps the link in the list alone. */
	function created(key: number, row: SendRow, link: string) {
		setSendBusy(false)
		setSendPage((page) => (page?.kind === 'new' && page.key === key ? { kind: 'created', row, link } : page))
	}

	/** Runs `then` now, or after the user agreed to drop a dirty form. */
	function leave(then: () => void) {
		// A create in flight would land on a page that is gone.
		if (sendBusy) return
		if (leaveGuard.current?.()) setDialog({ kind: 'discard', then })
		else then()
	}

	const open = useCallback((id: string) => {
		leaveList()
		setDetailId(id)
		setShareSave((current) => (current?.id === id ? current : null))
	}, [leaveList])

	/** A switch would drop the form unasked, and under the switcher its decrypted values would wait in the hidden page. */
	function accounts() {
		leave(() => {
			setForm(null)
			setSendPage(null)
			onAccounts()
		})
	}

	async function popout() {
		// `null` is the background's success reply; with no window opened, stay open.
		if (await request({ kind: 'popup.popout', tabId: tab?.id }) === null) window.close()
		else showToast(i18n.t('vault.popoutFailed'))
	}

	/** The write's reply came after its sync, so one re-read shows the result. A note opens the item's detail, which shows it. */
	async function saved(id: string, note?: ShareSaveNote) {
		await reload()
		setDetailId(form?.mode === 'edit' && !note ? detailId : id)
		setShareSave(note ? { id, note } : null)
		setForm(null)
		showToast(i18n.t('item.saved'))
	}

	const closeForm = useCallback(() => setForm(null), [])
	const gone = useCallback(() => {
		setForm(null)
		setDetailId(null)
	}, [])

	const detail = snapshot && detailId !== null ? snapshot.items?.find((item) => item.id === detailId) : undefined
	// Origin and path only: a query or fragment can hold a token, and the URL is stored unencrypted.
	const tabPage = tab?.host ? webUrl(tab.url) : null
	const tabUrl = tabPage ? `${tabPage.origin}${tabPage.pathname}` : undefined

	let content: ReactNode
	if (current === 'vault') {
		content = failed
			? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>
			: snapshot && tab
				? (
					<>
						{/* Hidden rather than unmounted, so back keeps search, filters and decrypted subtitles. */}
						<div hidden={detail !== undefined || form !== null}>
							<VaultList snapshot={snapshot} tab={tab} syncNow={() => void syncNow()} onOpen={open} />
						</div>
						{form && (
							// Hidden under the generator, so pick mode keeps the draft.
							<div hidden={pick !== null}>
								<ShellContext.Provider value={pick ? hiddenFormContext : context}>
									<ItemForm key={`${form.mode}:${form.id ?? ''}`} mode={form.mode} id={form.id} snapshot={snapshot} tabUrl={tabUrl} onSaved={saved} onCancel={() => leave(closeForm)} onGone={gone} />
								</ShellContext.Provider>
							</div>
						)}
						{form && pick && (
							<Generator
								pick={{
									target: pick.target,
									onUse: (value) => {
										pick.apply(value)
										setPick(null)
									},
								}}
							/>
						)}
						{/* Unmounted under a panel, so no decrypted value waits in the hidden DOM; it decrypts again on return. */}
						{detail && !form && !hidden && (
							<ItemDetail
								key={detail.id}
								item={detail}
								type={snapshot.types.find((t) => t.id === detail.typeId)}
								folders={snapshot.folders}
								webAppUrl={snapshot.webAppUrl}
								shareSave={shareSave?.id === detail.id ? shareSave.note : undefined}
							/>
						)}
					</>
				)
				: <Loading />
	} else if (current === 'settings') {
		content = <Settings section={section} onOpen={setSection} snapshot={snapshot} />
	} else if (current === 'generator') {
		content = history ? <GeneratorHistory /> : <Generator onOpenHistory={() => setHistory(true)} />
	} else {
		content = sendPage?.kind === 'new'
			? <NewSend key={sendPage.key} prefill={sendPage.prefill} onCreated={(row, link) => created(sendPage.key, row, link)} onCancel={() => leave(() => setSendPage(null))} onBusy={setSendBusy} />
			: sendPage?.kind === 'created'
				? (
					<SendCreated
						row={sendPage.row}
						link={sendPage.link}
						onDone={() => {
							setSendFocus(sendPage.row.id)
							setSendPage(null)
						}}
					/>
				)
				: <SendList focusId={sendFocus} onNew={newSend} />
	}

	const inSection = current === 'settings' && section !== null
	const inHistory = current === 'generator' && history
	const inSend = current === 'send' ? sendPage : null
	let title = tabLabel(current)
	if (form && pick) title = tabLabel('generator')
	else if (form) title = form.mode === 'edit' ? i18n.t('titles.editItem') : i18n.t('titles.newItem')
	else if (detail) title = i18n.t('titles.viewItem')
	else if (inSection) title = sectionLabel(section)
	else if (inHistory) title = i18n.t('generator.history')
	else if (inSend) title = inSend.kind === 'new' ? i18n.t('titles.newSend') : i18n.t('titles.sendCreated')
	let back: (() => void) | undefined
	if (form && pick) back = () => setPick(null)
	else if (form) back = () => leave(closeForm)
	else if (detail) back = () => setDetailId(null)
	else if (inSection) back = () => setSection(SECTION_PARENT[section] ?? null)
	else if (inHistory) back = () => setHistory(false)
	else if (inSend) back = () => leave(() => setSendPage(null))
	// A page opened from a tab covers the tab bar, as in Bitwarden, and gets the bar for its own actions.
	const page = form !== null || detail !== undefined || inSection || inHistory || inSend !== null
	let add: (() => void) | undefined
	if (!page && current === 'vault' && snapshot?.items) add = items.add
	else if (!page && current === 'send') add = newSend

	return (
		<ShellContext.Provider value={context}>
			<SendProvider>
				<main className={`popup${isPopout ? ' popup--popout' : ''}`} hidden={hidden}>
					<Header
						title={title}
						active={active}
						busy={sendBusy}
						onAvatarClick={accounts}
						onBack={back}
						onAdd={add}
						onPopout={isPopout ? undefined : () => leave(() => void popout())}
					/>
					<div className="popup__body" ref={body}>{content}</div>
					<div className="popup__bottom">
						<Toast message={toast} />
						{page ? <div className="popup__footer" ref={setFooter} /> : <TabBar active={current} onSelect={show} />}
					</div>
					{dialog?.kind === 'discard' && (
						<ConfirmDialog
							title={i18n.t('form.unsavedTitle')}
							actions={[{
								label: i18n.t('form.discard'),
								variant: 'danger',
								onClick: () => {
									leaveGuard.current = null
									setDialog(null)
									dialog.then()
								},
							}]}
							cancelLabel={i18n.t('form.keepEditing')}
							onCancel={() => setDialog(null)}
						>
							<p>{i18n.t('form.unsaved')}</p>
						</ConfirmDialog>
					)}
					{dialog?.kind === 'delete' && (
						<DeleteItemDialog
							item={dialog.item}
							onDeleted={async () => {
								await reload()
								setDialog(null)
								setDetailId(null)
								showToast(i18n.t('item.deleted'))
							}}
							onCancel={() => setDialog(null)}
						/>
					)}
					{dialog?.kind === 'move' && snapshot && (
						<MoveItemDialog
							item={dialog.item}
							folders={snapshot.folders}
							onMoved={async (folderId) => {
								// The re-read, not this render's snapshot: the folder may have been made in the picker.
								const next = await reload()
								const folders = next?.state === 'unlocked' ? next.folders : snapshot.folders
								setDialog(null)
								showToast(i18n.t('item.moved', { folder: folderPath(folders, folderId) ?? i18n.t('filters.noFolder') }))
							}}
							onCancel={() => setDialog(null)}
						/>
					)}
				</main>
			</SendProvider>
		</ShellContext.Provider>
	)
}
