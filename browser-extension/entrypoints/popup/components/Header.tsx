import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'

interface Props {
	title: string
	active: AccountSummary | null
	onAvatarClick?: () => void
}

export function Header({ title, active, onAvatarClick }: Props) {
	return (
		<header className="header">
			<h1 className="header__title">{title}</h1>
			{active && onAvatarClick && (
				<button type="button" className="header__avatar" onClick={onAvatarClick} aria-label={`Accounts, signed in as ${active.displayName}`}>
					<Avatar name={active.displayName} dataUrl={active.avatarDataUrl} />
				</button>
			)}
		</header>
	)
}
