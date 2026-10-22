import { useCallback, useMemo, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { ErrorBanner } from './components/ErrorBanner'
import { Header } from './components/Header'
import { Loading } from './components/Loading'
import { tabLabel } from './components/TabBar'
import { Toast } from './components/Toast'
import { useBackgroundMessage } from './hooks/useBackgroundMessage'
import { usePopupState } from './hooks/usePopupState'
import { useTheme } from './hooks/useTheme'
import { useToast } from './hooks/useToast'
import { DEFAULT_SHELL, ShellContext } from './shell-context'
import { AccountSwitcher } from './views/AccountSwitcher'
import { AddAccount } from './views/AddAccount'
import { Generator } from './views/Generator'
import { GeneratorHistory } from './views/GeneratorHistory'
import { LogInAgain } from './views/LogInAgain'
import { Shell } from './views/Shell'
import { Unlock } from './views/Unlock'

/** Panels the popup opens on top of the background's screen. */
type Panel = 'switcher' | 'add_account' | 'generator' | null

export default function App() {
	const { state, error, dispatch } = usePopupState()
	useTheme()
	const [panel, setPanel] = useState<Panel>(null)
	const [lockedHistory, setLockedHistory] = useState(false)
	/** Unlock was left for the generator, so focus returns to its link. */
	const [fromGenerator, setFromGenerator] = useState(false)
	const { message: toast, show: showToast } = useToast()
	const refreshState = useCallback(() => void dispatch({ kind: 'vault.status', passive: true }), [dispatch])
	// A lock from the timeout or another window swaps this popup to the unlock view.
	useBackgroundMessage('vault.locked', refreshState)
	const activeId = state?.active?.id
	// The lock screen's generator: no vault, so only what the generator's requests and Copy need.
	const lockedContext = useMemo(() => ({ ...DEFAULT_SHELL, accountId: activeId ?? '', refreshState, toast: showToast }), [activeId, refreshState, showToast])

	// It is the lock screen's; an unlock in another window leaves it.
	if (panel === 'generator' && state && state.screen !== 'unlock') setPanel(null)
	if (lockedHistory && panel !== 'generator') setLockedHistory(false)
	if (fromGenerator && state && state.screen !== 'unlock') setFromGenerator(false)

	if (!state) {
		return (
			<main className="popup">
				{/* No title until the background names the screen, so nothing flashes before it. */}
				<Header title={error ? 'Keepiq' : ''} active={null} />
				<div className="popup__body">
					{error ? <ErrorBanner>{error}</ErrorBanner> : <Loading />}
				</div>
			</main>
		)
	}

	const { active } = state
	const close = () => setPanel(null)
	const openPanel = (next: Panel) => {
		setFromGenerator(next === null && panel === 'generator')
		setPanel(next)
	}
	const back = () => {
		if (lockedHistory) setLockedHistory(false)
		else openPanel(panel === 'add_account' ? 'switcher' : null)
	}
	// Kept mounted under a panel, so leaving the switcher returns to the same tab, filters and scroll.
	const shell = state.screen === 'unlocked' && active
		? <Shell key={active.id} hidden={panel !== null} active={active} lastTab={state.lastTab} onAccounts={() => setPanel('switcher')} refreshState={refreshState} />
		: null
	let title: string
	let body: ReactNode
	if (panel === 'switcher') {
		title = i18n.t('titles.accounts')
		body = <AccountSwitcher state={state} dispatch={dispatch} onClose={close} onAddAccount={() => setPanel('add_account')} />
	} else if (panel === 'generator' && active) {
		title = lockedHistory ? i18n.t('generator.history') : tabLabel('generator')
		body = (
			<ShellContext.Provider value={lockedContext}>
				{lockedHistory ? <GeneratorHistory /> : <Generator key={active.id} takeFocus onOpenHistory={() => setLockedHistory(true)} />}
			</ShellContext.Provider>
		)
	} else if (panel === 'add_account' || !active || state.screen === 'add_account') {
		title = i18n.t('common.addAccount')
		body = <AddAccount dispatch={dispatch} onAdded={close} />
	} else if (state.screen === 'reauthenticate') {
		title = i18n.t('titles.logInAgain')
		body = <LogInAgain key={active.id} account={active} notice={state.notice} dispatch={dispatch} />
	} else if (state.screen === 'unlock') {
		title = i18n.t('titles.unlock')
		body = <Unlock key={active.id} account={active} notice={state.notice} dispatch={dispatch} onGenerate={() => setPanel('generator')} focusGenerate={fromGenerator} />
	} else {
		return shell
	}

	return (
		<>
			{shell}
			<main className="popup">
				<Header title={title} active={active} onAvatarClick={panel ? undefined : () => openPanel('switcher')} onBack={panel && active ? back : undefined} />
				<div className="popup__body">{body}</div>
				{panel === 'generator' && <div className="popup__bottom"><Toast message={toast} /></div>}
			</main>
		</>
	)
}
