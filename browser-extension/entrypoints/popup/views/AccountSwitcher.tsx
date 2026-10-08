import type { AccountStatus, AccountSummary, PopupState } from '@/src/messages'
import { Avatar } from '../components/Avatar'
import { IconButton } from '../components/Button'
import { Icon } from '../components/Icon'
import { Confirm, LogOutConfirm, useLogOutConfirm } from '../components/LogOutConfirm'
import type { Dispatch } from '../hooks/usePopupState'

const STATUS_LABEL: Record<AccountStatus, string> = { unlocked: 'Unlocked', locked: 'Locked', logged_out: 'Logged out' }

function AccountRow({ account, dispatch, onSelect }: { account: AccountSummary; dispatch: Dispatch; onSelect: () => void }) {
	const logOut = useLogOutConfirm()
	// Every row repeats these buttons, so each names its account for screen readers.
	const which = `${account.displayName} on ${account.host}`
	return (
		<li className="accounts__row">
			<button type="button" className="accounts__select" onClick={onSelect} aria-current={account.active ? 'true' : undefined}>
				<Avatar name={account.displayName} dataUrl={account.avatarDataUrl} size={36} />
				<span className="accounts__text">
					<span className="accounts__name">{account.displayName}{account.active && <span className="sr-only"> (active)</span>}</span>
					<span className="accounts__host">{account.host}</span>
					<span className={`status status--${account.status}`}>{STATUS_LABEL[account.status]}</span>
				</span>
				{account.active && <span className="accounts__check"><Icon name="check" /></span>}
			</button>
			<span className="accounts__actions">
				{account.status === 'unlocked' && (
					<IconButton icon="lock" label={`Lock ${which}`} onClick={() => void dispatch({ kind: 'vault.lock', accountId: account.id })} />
				)}
				{!logOut.confirming && (
					<IconButton icon="logOut" label={`Log out of ${which}`} onClick={logOut.ask} autoFocus={logOut.triggerAutoFocus} />
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
	const logOutAll = useLogOutConfirm()

	async function select(accountId: string) {
		const result = await dispatch({ kind: 'accounts.switch', accountId })
		if (result.ok) onClose()
	}

	return (
		<div className="stack">
			<ul className="card accounts">
				{state.accounts.map((account) => (
					<AccountRow key={account.id} account={account} dispatch={dispatch} onSelect={() => void select(account.id)} />
				))}
			</ul>

			<div>
				<ul className="card menu">
					<li>
						<button type="button" className="menu__item" onClick={onAddAccount} disabled={!state.canAddAccount} aria-describedby={state.canAddAccount ? undefined : 'limit-hint'}>
							<Icon name="plus" />Add account
						</button>
					</li>
					<li>
						<button type="button" className="menu__item" onClick={() => void dispatch({ kind: 'vault.lockAll' })}>
							<Icon name="lock" />Lock all
						</button>
					</li>
					<li>
						{logOutAll.confirming
							? (
								<Confirm
									question="Log out of every account?"
									detail="Every account is removed from this browser. You will need each app password again."
									action="Log out all"
									onConfirm={() => void dispatch({ kind: 'accounts.removeAll' }).then(onClose)}
									onCancel={logOutAll.cancel}
								/>
							)
							: (
								<button type="button" className="menu__item menu__item--danger" onClick={logOutAll.ask} autoFocus={logOutAll.triggerAutoFocus}>
									<Icon name="logOut" />Log out all
								</button>
							)}
					</li>
				</ul>
				{!state.canAddAccount && <p className="hint" id="limit-hint">Maximum of 5 accounts reached</p>}
			</div>
		</div>
	)
}
