import { useState } from 'react'
import type { AccountSummary } from '@/src/messages'
import { Button } from './Button'

/** "Log out" deletes the account, so it always asks first. Cancel hands focus back to the trigger. */
export function useLogOutConfirm() {
	const [state, setState] = useState<'idle' | 'confirming' | 'cancelled'>('idle')
	return {
		confirming: state === 'confirming',
		ask: () => setState('confirming'),
		cancel: () => setState('cancelled'),
		triggerAutoFocus: state === 'cancelled',
	}
}

interface Props {
	account: AccountSummary
	onConfirm: () => void
	onCancel: () => void
}

export function LogOutConfirm({ account, onConfirm, onCancel }: Props) {
	const question = `Log out of ${account.displayName} on ${account.host}?`
	return (
		<div className="confirm" role="group" aria-label={question}>
			<p className="hint">{question} The account is removed from this browser and you will need an app password to add it again.</p>
			<Button variant="danger" onClick={onConfirm}>Log out</Button>
			<Button variant="link" onClick={onCancel} autoFocus>Cancel</Button>
		</div>
	)
}
