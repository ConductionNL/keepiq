import { useCallback, useState, type ReactNode } from 'react'
import { ErrorBanner } from './components/ErrorBanner'
import { Header } from './components/Header'
import { Loading } from './components/Loading'
import { useBackgroundMessage } from './hooks/useBackgroundMessage'
import { usePopupState } from './hooks/usePopupState'
import { AccountSwitcher } from './views/AccountSwitcher'
import { AddAccount } from './views/AddAccount'
import { LogInAgain } from './views/LogInAgain'
import { Shell } from './views/Shell'
import { Unlock } from './views/Unlock'

/** Panels the popup opens on top of the background's screen. */
type Panel = 'switcher' | 'add_account' | null

export default function App() {
	const { state, error, dispatch } = usePopupState()
	const [panel, setPanel] = useState<Panel>(null)
	const refreshState = useCallback(() => void dispatch({ kind: 'vault.status', passive: true }), [dispatch])
	// A lock from the timeout or another window swaps this popup to the unlock view.
	useBackgroundMessage('vault.locked', refreshState)

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
	// Kept mounted under a panel, so leaving the switcher returns to the same tab, filters and scroll.
	const shell = state.screen === 'unlocked' && active
		? <Shell key={active.id} hidden={panel !== null} active={active} lastTab={state.lastTab} onAccounts={() => setPanel('switcher')} refreshState={refreshState} />
		: null
	let title: string
	let body: ReactNode
	if (panel === 'switcher') {
		title = 'Accounts'
		body = <AccountSwitcher state={state} dispatch={dispatch} onClose={close} onAddAccount={() => setPanel('add_account')} />
	} else if (panel === 'add_account' || !active || state.screen === 'add_account') {
		title = 'Add account'
		body = <AddAccount dispatch={dispatch} onAdded={close} />
	} else if (state.screen === 'reauthenticate') {
		title = 'Log in again'
		body = <LogInAgain key={active.id} account={active} notice={state.notice} dispatch={dispatch} />
	} else if (state.screen === 'unlock') {
		title = 'Unlock'
		body = <Unlock key={active.id} account={active} notice={state.notice} dispatch={dispatch} />
	} else {
		return shell
	}

	return (
		<>
			{shell}
			<main className="popup">
				<Header title={title} active={active} onAvatarClick={panel ? undefined : () => setPanel('switcher')} onBack={panel && active ? () => setPanel(panel === 'add_account' ? 'switcher' : null) : undefined} />
				<div className="popup__body">{body}</div>
			</main>
		</>
	)
}
