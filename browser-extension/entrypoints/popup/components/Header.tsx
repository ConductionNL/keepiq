import { i18n } from '#i18n'
import type { AccountSummary } from '@/src/messages'
import { Avatar } from './Avatar'
import { IconButton } from './Button'
import { Icon } from './Icon'
import { accountName } from './LogOutConfirm'
import { Logo } from './Logo'

interface Props {
	title: string
	active: AccountSummary | null
	onAvatarClick?: () => void
	/** Set on views opened over another; replaces the logo. */
	onBack?: () => void
	/** Shows the New button, on the Vault and Send tabs. */
	onAdd?: () => void
	/** Shows the pop-out button; left out inside a popped-out window. */
	onPopout?: () => void
	/** Disables the ways out of the page, e.g. while a send is being created. */
	busy?: boolean
}

export function Header({ title, active, onAvatarClick, onBack, onAdd, onPopout, busy = false }: Props) {
	return (
		<header className="header">
			{onBack ? <IconButton icon="back" label={i18n.t('common.back')} onClick={onBack} disabled={busy} /> : <Logo size={22} />}
			{/* A spacer while loading; an empty heading would still be announced. */}
			{title ? <h1 className="header__title">{title}</h1> : <span className="header__title" />}
			{onAdd && <button type="button" className="btn btn--primary btn--small" onClick={onAdd}><Icon name="plus" />{i18n.t('header.new')}</button>}
			{onPopout && <IconButton icon="popout" label={i18n.t('header.popout')} onClick={onPopout} disabled={busy} />}
			{active && onAvatarClick && (
				<button type="button" className="header__avatar" onClick={onAvatarClick} disabled={busy} aria-label={i18n.t('header.accounts', { name: active.displayName })} title={accountName(active)}>
					<Avatar name={active.displayName} dataUrl={active.avatarDataUrl} />
				</button>
			)}
		</header>
	)
}
