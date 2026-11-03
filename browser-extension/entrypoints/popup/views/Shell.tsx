import { useCallback, useEffect, useRef, useState, type ComponentProps, type ReactNode, type RefObject } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, PopupTab, ShareSaveNote } from '@/src/messages'
import { folderPath } from '@/src/vault/list'
import { webUrl } from '@/src/vault/url'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Loading } from '../components/Loading'
import { TabBar } from '../components/TabBar'
import { Toast } from '../components/Toast'
import { errorText } from '../errors'
import { isPopout, useCurrentTab } from '../hooks/useCurrentTab'
import { request } from '../hooks/useMessage'
import { SendProvider } from '../hooks/useSends'
import { useToast } from '../hooks/useToast'
import { useVaultSnapshot } from '../hooks/useVaultSnapshot'
import { ShellContext, type GenerateTarget } from '../shell-context'
import { Generator } from './Generator'
import { GeneratorHistory } from './GeneratorHistory'
import { DeleteItemDialog, MoveItemDialog } from './ItemDialogs'
import { ItemDetail } from './ItemDetail'
import { ItemForm } from './ItemForm'
import { NewSend } from './send/NewSend'
import { SendCreated } from './send/SendCreated'
import { SendList } from './send/SendList'
import { Settings } from './settings/Settings'
import { SECTION_PARENT } from './settings/SettingsIndex'
import { useBodyScroll, useItemActions, useLeave, useSendItem, useSendPages, useShellContextValue, useTabPages } from './shellHooks'
import { headerFor, type ShellPage } from './shellPage'
import { VaultList } from './VaultList'

interface Props {
	active: AccountSummary
	lastTab: PopupTab
	/** Under the account switcher. */
	hidden?: boolean
	onAccounts: () => void
	refreshState: () => void
}

type Pick = { target: GenerateTarget; apply: (value: string) => void }

/** The detail's Fill: a suggestion, or a card or identity, which the Vault view settings only keep out of the suggestions. */
const offersFill = (snapshot: { suggestionIds: string[]; formIds: string[] }, id: string) => snapshot.suggestionIds.includes(id) || snapshot.formIds.includes(id)

/** The unlocked popup: tabs, and pages such as an item's detail, its form or a settings section opened over them. */
export function Shell({ active, lastTab, hidden = false, onAccounts, refreshState }: Props) {
	const tab = useCurrentTab()
	const { sendPage, setSendPage, sendBusy, setSendBusy, sendFocus, setSendFocus, newSend, created, closeSend } = useSendPages()
	const { current, detailId, setDetailId, form, setForm, section, setSection, history, setHistory, show, closeForm, gone } = useTabPages(lastTab, closeSend)
	const [pick, setPick] = useState<Pick | null>(null)
	const { dialog, setDialog, leave, leaveGuardRef } = useLeave(sendBusy)
	// In memory only, so it is gone once the popup closes (share-sync).
	const [shareSave, setShareSave] = useState<{ id: string; note: ShareSaveNote } | null>(null)
	const { message: toast, show: showToast } = useToast()
	const [footer, setFooter] = useState<HTMLElement | null>(null)
	const body = useRef<HTMLDivElement>(null)
	const { reply, failed, syncNow, reload } = useVaultSnapshot(tab?.url, tab !== undefined)

	// Pick mode belongs to the form under it.
	if (pick && !form) setPick(null)

	useEffect(() => {
		if (reply && reply.state !== 'unlocked') refreshState()
	}, [reply, refreshState])

	const { leaveList, keepFormPlace } = useBodyScroll(body, { pick, detailId, form, sendPage })

	const sendItem = useSendItem({ refreshState, toast: showToast, show, setSendPage })
	const items = useItemActions({ leaveList, setForm, setDialog, sendItem })

	const snapshot = reply?.state === 'unlocked' ? reply : undefined

	// The opener, not `document.activeElement`: Firefox on macOS and Safari don't focus a clicked button.
	const generate = useCallback((target: GenerateTarget, apply: (value: string) => void, opener: HTMLElement | null) => {
		keepFormPlace(opener)
		setPick({ target, apply })
	}, [keepFormPlace])

	const parts = { accountId: active.id, refreshState, toast: showToast, windowId: tab?.windowId, footer, offline: snapshot?.sync.offline ?? false, syncNow, items, generate }
	const { context, hiddenFormContext } = useShellContextValue(parts, leaveGuardRef)

	const open = useCallback((id: string) => {
		leaveList()
		setDetailId(id)
		setShareSave((current) => (current?.id === id ? current : null))
	}, [leaveList, setDetailId])

	/** A switch would drop the form unasked, and under the switcher its decrypted values would wait in the hidden page. */
	function accounts() {
		leave(() => {
			setForm(null)
			setSendPage(null)
			onAccounts()
		})
	}

	/** The write's reply came after its sync, so one re-read shows the result. A note opens the item's detail, which shows it. */
	async function saved(id: string, note?: ShareSaveNote) {
		await reload()
		setDetailId(form?.mode === 'edit' && !note ? detailId : id)
		setShareSave(note ? { id, note } : null)
		setForm(null)
		showToast(i18n.t('item.saved'))
	}

	const detail = snapshot && detailId !== null ? snapshot.items?.find((item) => item.id === detailId) : undefined
	// Origin and path only: a query or fragment can hold a token, and the URL is stored unencrypted.
	const tabPage = tab?.host ? webUrl(tab.url) : null
	const tabUrl = tabPage ? `${tabPage.origin}${tabPage.pathname}` : undefined

	function vaultPane(): ReactNode {
		if (failed) return <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>
		if (!snapshot || !tab) return <Loading />
		return (
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
						fillTab={offersFill(snapshot, detail.id) ? tab : undefined}
					/>
				)}
			</>
		)
	}

	function sendPane(): ReactNode {
		if (sendPage?.kind === 'new') return <NewSend key={sendPage.key} prefill={sendPage.prefill} onCreated={(row, link) => created(sendPage.key, row, link)} onCancel={() => leave(() => setSendPage(null))} onBusy={setSendBusy} />
		if (sendPage?.kind === 'created') {
			return (
				<SendCreated
					row={sendPage.row}
					link={sendPage.link}
					onDone={() => {
						setSendFocus(sendPage.row.id)
						setSendPage(null)
					}}
				/>
			)
		}
		return <SendList focusId={sendFocus} onNew={newSend} />
	}

	/** The current tab's pane. */
	function pane(): ReactNode {
		const panes: Record<PopupTab, () => ReactNode> = {
			vault: vaultPane,
			settings: () => <Settings section={section} onOpen={setSection} account={active} snapshot={snapshot} snapshotFailed={failed} reload={() => void reload()} />,
			generator: () => (history ? <GeneratorHistory /> : <Generator onOpenHistory={() => setHistory(true)} />),
			send: sendPane,
		}
		return panes[current]()
	}

	function dialogView(): ReactNode {
		return (
			<>
				{dialog?.kind === 'discard' && (
					<ConfirmDialog
						title={i18n.t('form.unsavedTitle')}
						actions={[{
							label: i18n.t('form.discard'),
							variant: 'danger',
							onClick: () => {
								leaveGuardRef.current = null
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
			</>
		)
	}

	/** The open page, if any, and the header over it: its title, Back on a page and New on a tab itself. */
	function pageAndHeader() {
		const view = { current, form, picking: pick !== null, detail: detail !== undefined, section, history, sendPage }
		const backs: Record<ShellPage, () => void> = {
			pick: () => setPick(null),
			form: () => leave(closeForm),
			detail: () => setDetailId(null),
			section: () => setSection((section && SECTION_PARENT[section]) ?? null),
			history: () => setHistory(false),
			send: () => leave(() => setSendPage(null)),
		}
		// A page opened from a tab covers the tab bar, as in Bitwarden, and gets the bar for its own actions.
		const { page, title, back, add } = headerFor(view, { backs, adds: { vault: snapshot?.items ? items.add : undefined, send: newSend } })
		const onPopout = isPopout ? undefined : () => leave(() => void popOut(tab?.id, showToast))
		return { page, header: { title, active, busy: sendBusy, onAvatarClick: accounts, onBack: back, onAdd: add, onPopout } }
	}

	const { page, header } = pageAndHeader()
	return (
		<ShellContext.Provider value={context}>
			<SendProvider>
				<ShellFrame hidden={hidden} header={header} body={body} toast={toast} bar={{ page: page !== null, current, onSelect: show, setFooter }} dialogs={dialogView()}>
					{pane()}
				</ShellFrame>
			</SendProvider>
		</ShellContext.Provider>
	)
}

/** Opens the popup in a window of its own and closes this one, or says why it stays. */
async function popOut(tabId: number | undefined, toast: (message: string) => void) {
	// `null` is the background's success reply; with no window opened, stay open.
	if (await request({ kind: 'popup.popout', tabId }) === null) window.close()
	else toast(i18n.t('vault.popoutFailed'))
}

interface FrameProps {
	hidden: boolean
	header: ComponentProps<typeof Header>
	body: RefObject<HTMLDivElement | null>
	toast: string | null
	/** On a page its actions take the bar, else the tab bar shows. */
	bar: { page: boolean; current: PopupTab; onSelect: (tab: PopupTab) => void; setFooter: (footer: HTMLElement | null) => void }
	/** The current tab's pane. */
	children: ReactNode
	dialogs: ReactNode
}

/** The popup around a pane: header, scrolling body, toast, the bottom bar and the dialogs over it. */
function ShellFrame({ hidden, header, body, toast, bar: { page, current, onSelect, setFooter }, children, dialogs }: FrameProps) {
	return (
		<main className={`popup${isPopout ? ' popup--popout' : ''}`} hidden={hidden}>
			<Header {...header} />
			<div className="popup__body" ref={body}>{children}</div>
			<div className="popup__bottom">
				<Toast message={toast} />
				{page ? <div className="popup__footer" ref={setFooter} /> : <TabBar active={current} onSelect={onSelect} />}
			</div>
			{dialogs}
		</main>
	)
}
