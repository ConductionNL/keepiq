import { useState, type ReactNode } from 'react'
import { ErrorBanner } from './components/ErrorBanner'
import { Header } from './components/Header'
import { usePopupState } from './hooks/usePopupState'
import { AccountSwitcher } from './views/AccountSwitcher'
import { AddAccount } from './views/AddAccount'
import { LogInAgain } from './views/LogInAgain'
import { Unlock } from './views/Unlock'
import { Unlocked } from './views/Unlocked'

/** Panels the popup opens on top of the background's screen. */
type Panel = 'switcher' | 'add_account' | null

export default function App() {
	const { state, error, dispatch } = usePopupState()
	const [panel, setPanel] = useState<Panel>(null)

	if (!state) {
		return (
			<main className="popup">
				<Header title="Keepiq" active={null} />
				<div className="popup__body">
					{error ? <ErrorBanner>{error}</ErrorBanner> : <p className="loading" role="status"><span className="spinner" aria-hidden="true" />Loading…</p>}
				</div>
			</main>
		)
	}

	const { active } = state
	const close = () => setPanel(null)
	let title: string
	let body: ReactNode
	if (panel === 'switcher') {
		title = 'Accounts'
		body = <AccountSwitcher state={state} dispatch={dispatch} onClose={close} onAddAccount={() => setPanel('add_account')} />
	} else if (panel === 'add_account' || !active || state.screen === 'add_account') {
		title = 'Add account'
		body = <AddAccount dispatch={dispatch} welcome={!active} onAdded={close} />
	} else if (state.screen === 'reauthenticate') {
		title = 'Log in again'
		body = <LogInAgain key={active.id} account={active} notice={state.notice} dispatch={dispatch} />
	} else if (state.screen === 'unlock') {
		title = 'Unlock'
		body = <Unlock key={active.id} account={active} dispatch={dispatch} />
	} else {
		title = 'Keepiq'
		body = <Unlocked account={active} dispatch={dispatch} />
	}

	return (
		<main className="popup">
			<Header title={title} active={active} onAvatarClick={panel ? undefined : () => setPanel('switcher')} onBack={panel && active ? () => setPanel(panel === 'add_account' ? 'switcher' : null) : undefined} />
			<div className="popup__body">{body}</div>
		</main>
	)
}
