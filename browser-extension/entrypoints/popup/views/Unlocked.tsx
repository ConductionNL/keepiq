import type { AccountSummary } from '@/src/messages'
import { Button } from '../components/Button'
import { Identity } from '../components/Identity'
import type { Dispatch } from '../hooks/usePopupState'

/** Placeholder until ext-vault-browse puts the vault here. */
export function Unlocked({ account, dispatch }: { account: AccountSummary; dispatch: Dispatch }) {
	return (
		<div className="stack">
			<Identity account={account} />
			<p className="hint">Your vault is unlocked.</p>
			<Button variant="secondary" onClick={() => void dispatch({ kind: 'vault.lock', accountId: account.id })}>Lock</Button>
		</div>
	)
}
