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
	return (
		<Confirm
			question={`Log out of ${account.displayName} on ${account.host}?`}
			detail="The account is removed from this browser. You will need an app password to add it again."
			action="Log out"
			onConfirm={onConfirm}
			onCancel={onCancel}
		/>
	)
}

interface ConfirmProps {
	question: string
	detail: string
	action: string
	onConfirm: () => void
	onCancel: () => void
}

export function Confirm({ question, detail, action, onConfirm, onCancel }: ConfirmProps) {
	return (
		<div className="confirm" role="group" aria-label={question}>
			<p className="confirm__question">{question}</p>
			<p className="confirm__detail">{detail}</p>
			<div className="confirm__actions">
				<Button variant="secondary" onClick={onCancel} autoFocus>Cancel</Button>
				<Button variant="danger" onClick={onConfirm}>{action}</Button>
			</div>
		</div>
	)
}
