import { useState } from 'react'
import type { AccountStatus, AccountSummary, PopupState } from '@/src/messages'
import { Avatar } from '../components/Avatar'
import { Button } from '../components/Button'
import { LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
import type { Dispatch } from '../hooks/usePopupState'

const STATUS_LABEL: Record<AccountStatus, string> = { unlocked: 'Unlocked', locked: 'Locked', logged_out: 'Logged out' }

function AccountRow({ account, dispatch, onSelect }: { account: AccountSummary; dispatch: Dispatch; onSelect: () => void }) {
	const logOut = useLogOutConfirm()
	// Every row repeats these buttons, so each names its account for screen readers.
	const which = `${account.displayName} on ${account.host}`
	return (
		<li className="accounts__row">
			<button type="button" className="accounts__select" onClick={onSelect} aria-current={account.active ? 'true' : undefined}>
				<Avatar name={account.displayName} dataUrl={account.avatarDataUrl} />
				<span className="accounts__text">
					<span className="accounts__name">{account.displayName}{account.active && <span className="accounts__active"> (active)</span>}</span>
					<span className="accounts__host">{account.host}</span>
					<span className={`status status--${account.status}`}>{STATUS_LABEL[account.status]}</span>
				</span>
			</button>
			<span className="accounts__actions">
				{account.status === 'unlocked' && (
					<Button variant="link" aria-label={`Lock ${which}`} onClick={() => void dispatch({ kind: 'vault.lock', accountId: account.id })}>Lock</Button>
				)}
				{!logOut.confirming && (
					<Button variant="link" aria-label={`Log out of ${which}`} onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus}>Log out</Button>
				)}
			</span>
			{logOut.confirming && (
				<LogOutConfirm account={account} onConfirm={() => void dispatch({ kind: 'accounts.remove', accountId: account.id })} onCancel={logOut.cancel} />
			)}
		</li>
	)
}

interface Props {
	state: PopupState
	dispatch: Dispatch
	onClose: () => void
	onAddAccount: () => void
}

export function AccountSwitcher({ state, dispatch, onClose, onAddAccount }: Props) {
	const [confirmingLogOutAll, setConfirmingLogOutAll] = useState(false)

	async function select(accountId: string) {
		const result = await dispatch({ kind: 'accounts.switch', accountId })
		if (result.ok) onClose()
	}

	return (
		<div className="stack">
			<ul className="accounts">
				{state.accounts.map((account) => (
					<AccountRow key={account.id} account={account} dispatch={dispatch} onSelect={() => void select(account.id)} />
				))}
			</ul>

			<Button variant="secondary" onClick={onAddAccount} disabled={!state.canAddAccount}>Add account</Button>
			{!state.canAddAccount && <p className="hint">Maximum of 5 accounts reached</p>}
			<Button variant="secondary" onClick={() => void dispatch({ kind: 'vault.lockAll' })}>Lock all</Button>
			{confirmingLogOutAll
				? (
					<div className="confirm" role="group" aria-label="Log out of every account?">
						<p className="hint">Log out of every account? You will need each app password again.</p>
						<Button variant="danger" onClick={() => void dispatch({ kind: 'accounts.removeAll' }).then(onClose)}>Log out all</Button>
						<Button variant="link" onClick={() => setConfirmingLogOutAll(false)}>Cancel</Button>
					</div>
				)
				: <Button variant="secondary" onClick={() => setConfirmingLogOutAll(true)}>Log out all</Button>}
			<Button variant="link" onClick={onClose}>Back</Button>
		</div>
	)
}
