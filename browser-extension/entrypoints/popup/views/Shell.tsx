import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, ItemMeta, PopupTab } from '@/src/messages'
import { folderPath } from '@/src/vault/list'
import { webUrl } from '@/src/vault/url'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Loading } from '../components/Loading'
import { TabBar, tabLabel, TABS } from '../components/TabBar'
import { Toast } from '../components/Toast'
import { errorText } from '../errors'
import { isPopout, useCurrentTab } from '../hooks/useCurrentTab'
import { request } from '../hooks/useMessage'
import { useVaultSnapshot } from '../hooks/useVaultSnapshot'
import { ShellContext, type ItemActions } from '../shell-context'
import { DeleteItemDialog, MoveItemDialog } from './ItemDialogs'
import { ItemDetail } from './ItemDetail'
import { ItemForm, type FormMode } from './ItemForm'
import { Placeholder } from './Placeholder'
import { Settings } from './settings/Settings'
import { SECTION_PARENT, sectionLabel, type SettingsSection } from './settings/SettingsIndex'
import { VaultList } from './VaultList'

const TOAST_MS = 2500

interface Props {
	active: AccountSummary
	lastTab: PopupTab
	/** Under the account switcher. */
	hidden?: boolean
	onAccounts: () => void
	refreshState: () => void
}

type FormPage = { mode: FormMode; id?: string }

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
	const [dialog, setDialog] = useState<ShellDialog | null>(null)
	// A fresh object per call, so the same message twice restarts the timer.
	const [toast, setToast] = useState<{ text: string } | null>(null)
	const [footer, setFooter] = useState<HTMLElement | null>(null)
	const body = useRef<HTMLDivElement>(null)
	const listScroll = useRef(0)
	const onList = useRef(true)
	const leaveGuard = useRef<(() => boolean) | null>(null)
	const { reply, failed, syncNow, reload } = useVaultSnapshot(tab?.url, tab !== undefined)

	useEffect(() => {
		if (reply && reply.state !== 'unlocked') refreshState()
	}, [reply, refreshState])

	useEffect(() => {
		if (!toast) return
		const timer = setTimeout(() => setToast(null), TOAST_MS)
		return () => clearTimeout(timer)
	}, [toast])

	// Back restores where the list was scrolled.
	useLayoutEffect(() => {
		onList.current = detailId === null && form === null
		if (body.current) body.current.scrollTop = onList.current ? listScroll.current : 0
	}, [detailId, form])

	/** Opening a page from the list remembers its scroll position. */
	const leaveList = useCallback(() => {
		if (onList.current) listScroll.current = body.current?.scrollTop ?? 0
	}, [])

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
	}), [leaveList])

	const snapshot = reply?.state === 'unlocked' ? reply : undefined

	const context = useMemo(() => ({
		accountId: active.id,
		refreshState,
		toast: (text: string) => setToast({ text }),
		launch: (url: string) => void browser.tabs.create({ url, windowId: tab?.windowId }).catch(() => setToast({ text: i18n.t('vault.openFailed') })),
		footer,
		offline: snapshot?.sync.offline ?? false,
		syncNow: () => void syncNow(),
		items,
		setLeaveGuard: (guard: (() => boolean) | null) => {
			leaveGuard.current = guard
		},
	}), [active.id, refreshState, tab?.windowId, footer, snapshot?.sync.offline, syncNow, items])

	/** Runs `then` now, or after the user agreed to drop a dirty form. */
	function leave(then: () => void) {
		if (leaveGuard.current?.()) setDialog({ kind: 'discard', then })
		else then()
	}

	const open = useCallback((id: string) => {
		leaveList()
		setDetailId(id)
	}, [leaveList])

	function select(next: PopupTab) {
		setCurrent(next)
		setDetailId(null)
		setForm(null)
		setSection(null)
		void request({ kind: 'popup.lastTab.set', tab: next })
	}

	/** A switch would drop the form unasked, and under the switcher its decrypted values would wait in the hidden page. */
	function accounts() {
		leave(() => {
			setForm(null)
			onAccounts()
		})
	}

	async function popout() {
		// `null` is the background's success reply; with no window opened, stay open.
		if (await request({ kind: 'popup.popout', tabId: tab?.id }) === null) window.close()
		else setToast({ text: i18n.t('vault.popoutFailed') })
	}

	/** The write's reply came after its sync, so one re-read shows the result. */
	async function saved(id: string) {
		await reload()
		setDetailId(form?.mode === 'edit' ? detailId : id)
		setForm(null)
		setToast({ text: i18n.t('item.saved') })
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
						{form && <ItemForm key={`${form.mode}:${form.id ?? ''}`} mode={form.mode} id={form.id} snapshot={snapshot} tabUrl={tabUrl} onSaved={saved} onCancel={() => leave(closeForm)} onGone={gone} />}
						{/* Unmounted under a panel, so no decrypted value waits in the hidden DOM; it decrypts again on return. */}
						{detail && !form && !hidden && <ItemDetail key={detail.id} item={detail} type={snapshot.types.find((t) => t.id === detail.typeId)} folders={snapshot.folders} webAppUrl={snapshot.webAppUrl} />}
					</>
				)
				: <Loading />
	} else if (current === 'settings') {
		content = <Settings section={section} onOpen={setSection} snapshot={snapshot} />
	} else {
		content = <Placeholder icon={TABS.find((t) => t.id === current)!.icon} title={tabLabel(current)} />
	}

	const inSection = current === 'settings' && section !== null
	let title = tabLabel(current)
	if (form) title = form.mode === 'edit' ? i18n.t('titles.editItem') : i18n.t('titles.newItem')
	else if (detail) title = i18n.t('titles.viewItem')
	else if (inSection) title = sectionLabel(section)
	let back: (() => void) | undefined
	if (form) back = () => leave(closeForm)
	else if (detail) back = () => setDetailId(null)
	else if (inSection) back = () => setSection(SECTION_PARENT[section] ?? null)
	// A page opened from a tab covers the tab bar, as in Bitwarden, and gets the bar for its own actions.
	const page = form !== null || detail !== undefined || inSection

	return (
		<ShellContext.Provider value={context}>
			<main className={`popup${isPopout ? ' popup--popout' : ''}`} hidden={hidden}>
				<Header
					title={title}
					active={active}
					onAvatarClick={accounts}
					onBack={back}
					onAdd={current === 'vault' && !page && snapshot?.items ? items.add : undefined}
					onPopout={isPopout ? undefined : () => leave(() => void popout())}
				/>
				<div className="popup__body" ref={body}>{content}</div>
				<div className="popup__bottom">
					<Toast message={toast?.text ?? null} />
					{page ? <div className="popup__footer" ref={setFooter} /> : <TabBar active={current} onSelect={select} />}
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
							setToast({ text: i18n.t('item.deleted') })
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
							setToast({ text: i18n.t('item.moved', { folder: folderPath(folders, folderId) ?? i18n.t('filters.noFolder') }) })
						}}
						onCancel={() => setDialog(null)}
					/>
				)}
			</main>
		</ShellContext.Provider>
	)
}
