import { i18n } from '#i18n'
import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'
import { IconButton } from './Button'
import { accountName } from './LogOutConfirm'
import { Logo } from './Logo'

interface Props {
	title: string
	active: AccountSummary | null
	onAvatarClick?: () => void
	/** Set on views opened over another; replaces the logo. */
	onBack?: () => void
	/** Shows the pop-out button; left out inside a popped-out window. */
	onPopout?: () => void
}

export function Header({ title, active, onAvatarClick, onBack, onPopout }: Props) {
	return (
		<header className="header">
			{onBack ? <IconButton icon="back" label={i18n.t('common.back')} onClick={onBack} /> : <Logo size={22} />}
			{/* A spacer while loading; an empty heading would still be announced. */}
			{title ? <h1 className="header__title">{title}</h1> : <span className="header__title" />}
			{onPopout && <IconButton icon="popout" label={i18n.t('header.popout')} onClick={onPopout} />}
			{active && onAvatarClick && (
				<button type="button" className="header__avatar" onClick={onAvatarClick} aria-label={i18n.t('header.accounts', { name: active.displayName })} title={accountName(active)}>
					<Avatar name={active.displayName} dataUrl={active.avatarDataUrl} />
				</button>
			)}
		</header>
	)
}
