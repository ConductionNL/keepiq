import { useState } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary } from '@/src/messages'
import { Button } from './Button'

/** "Alice on cloud.example.org", for labels that must say which account. */
export const accountName = (account: Pick<AccountSummary, 'displayName' | 'host'>) =>
	i18n.t('common.accountOnHost', { name: account.displayName, host: account.host })

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
			question={i18n.t('accounts.logOutQuestion', { account: accountName(account) })}
			detail={i18n.t('accounts.logOutDetail')}
			action={i18n.t('common.logOut')}
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
				<Button variant="secondary" onClick={onCancel} autoFocus>{i18n.t('common.cancel')}</Button>
				<Button variant="danger" onClick={onConfirm}>{action}</Button>
			</div>
		</div>
	)
}
