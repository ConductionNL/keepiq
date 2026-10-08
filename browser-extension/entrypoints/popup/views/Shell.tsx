import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import type { AccountSummary, PopupTab } from '@/src/messages'
import { ErrorBanner } from '../components/ErrorBanner'
import { Header } from '../components/Header'
import { Loading } from '../components/Loading'
import { TabBar, TABS } from '../components/TabBar'
import { Toast } from '../components/Toast'
import { isPopout, useCurrentTab } from '../hooks/useCurrentTab'
import { NOT_RESPONDING, request } from '../hooks/useMessage'
import { useVaultSnapshot } from '../hooks/useVaultSnapshot'
import { ShellContext } from '../shell-context'
import { ItemDetail } from './ItemDetail'
import { Placeholder } from './Placeholder'
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

/** The unlocked popup: tabs, and on Vault the list with an item detail stacked over it. */
export function Shell({ active, lastTab, hidden = false, onAccounts, refreshState }: Props) {
	const tab = useCurrentTab()
	// Read once: a later state refresh must not pull the user off the tab they picked.
	const [current, setCurrent] = useState(lastTab)
	const [detailId, setDetailId] = useState<string | null>(null)
	// A fresh object per call, so the same message twice restarts the timer.
	const [toast, setToast] = useState<{ text: string } | null>(null)
	const body = useRef<HTMLDivElement>(null)
	const listScroll = useRef(0)
	const { reply, failed, syncNow } = useVaultSnapshot(tab?.url, tab !== undefined)

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
		if (body.current) body.current.scrollTop = detailId === null ? listScroll.current : 0
	}, [detailId])

	const context = useMemo(() => ({
		refreshState,
		toast: (text: string) => setToast({ text }),
		launch: (url: string) => void browser.tabs.create({ url, windowId: tab?.windowId }).catch(() => setToast({ text: 'Could not open the website' })),
	}), [refreshState, tab?.windowId])

	const open = useCallback((id: string) => {
		listScroll.current = body.current?.scrollTop ?? 0
		setDetailId(id)
	}, [])

	function select(next: PopupTab) {
		setCurrent(next)
		setDetailId(null)
		void request({ kind: 'popup.lastTab.set', tab: next })
	}

	async function popout() {
		// `null` is the background's success reply; with no window opened, stay open.
		if (await request({ kind: 'popup.popout', tabId: tab?.id }) === null) window.close()
		else setToast({ text: 'Could not open a new window' })
	}

	const snapshot = reply?.state === 'unlocked' ? reply : undefined
	const detail = snapshot && detailId !== null ? snapshot.items?.find((item) => item.id === detailId) : undefined

	let content: ReactNode
	if (current === 'vault') {
		content = failed
			? <ErrorBanner>{NOT_RESPONDING}</ErrorBanner>
			: snapshot && tab
				? (
					<>
						{/* Hidden rather than unmounted, so back keeps search, filters and decrypted subtitles. */}
						<div hidden={detail !== undefined}>
							<VaultList snapshot={snapshot} tab={tab} syncNow={() => void syncNow()} onOpen={open} />
						</div>
						{/* Unmounted under a panel, so no decrypted value waits in the hidden DOM; it decrypts again on return. */}
						{detail && !hidden && <ItemDetail key={detail.id} item={detail} type={snapshot.types.find((t) => t.id === detail.typeId)} folders={snapshot.folders} webAppUrl={snapshot.webAppUrl} />}
					</>
				)
				: <Loading />
	} else {
		const meta = TABS.find((t) => t.id === current)!
		content = <Placeholder icon={meta.icon} title={meta.label} />
	}

	const title = detail ? 'View item' : TABS.find((t) => t.id === current)!.label

	return (
		<ShellContext.Provider value={context}>
			<main className={`popup${isPopout ? ' popup--popout' : ''}`} hidden={hidden}>
				<Header
					title={title}
					active={active}
					onAvatarClick={onAccounts}
					onBack={detail ? () => setDetailId(null) : undefined}
					onPopout={isPopout ? undefined : () => void popout()}
				/>
				<div className="popup__body" ref={body}>{content}</div>
				<Toast message={toast?.text ?? null} />
				<TabBar active={current} onSelect={select} />
			</main>
		</ShellContext.Provider>
	)
}
