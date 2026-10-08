import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'

/** Who and where, at the top of the unlock and re-login screens. */
export function Identity({ account }: { account: AccountSummary }) {
	return (
		<div className="identity">
			<Avatar name={account.displayName} dataUrl={account.avatarDataUrl} size={40} />
			<div>
				<p className="identity__name">{account.displayName}</p>
				<p className="identity__host">{account.host}</p>
			</div>
		</div>
	)
}
