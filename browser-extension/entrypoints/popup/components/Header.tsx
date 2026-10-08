import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'
import { IconButton } from './Button'
import { Logo } from './Logo'

interface Props {
	title: string
	active: AccountSummary | null
	onAvatarClick?: () => void
	/** Set on panels opened over the background's screen; replaces the logo. */
	onBack?: () => void
}

export function Header({ title, active, onAvatarClick, onBack }: Props) {
	return (
		<header className="header">
			{onBack ? <IconButton icon="back" label="Back" onClick={onBack} /> : <Logo size={22} />}
			<h1 className="header__title">{title}</h1>
			{active && onAvatarClick && (
				<button type="button" className="header__avatar" onClick={onAvatarClick} aria-label={`Accounts, signed in as ${active.displayName}`} title={`${active.displayName} on ${active.host}`}>
					<Avatar name={active.displayName} dataUrl={active.avatarDataUrl} />
				</button>
			)}
		</header>
	)
}
