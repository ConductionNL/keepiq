import type { ReactNode } from 'react'
import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'

/** Who and where, at the top of the unlock, re-login and unlocked screens. */
export function Identity({ account, children }: { account: AccountSummary; children?: ReactNode }) {
	return (
		<div className="identity">
			<Avatar name={account.displayName} dataUrl={account.avatarDataUrl} size={64} />
			<p className="identity__name">{account.displayName}</p>
			<p className="identity__host">{account.host}</p>
			{children && <p className="identity__note">{children}</p>}
		</div>
	)
}
