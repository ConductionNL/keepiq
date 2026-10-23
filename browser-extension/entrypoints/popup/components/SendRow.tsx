import { i18n } from '#i18n'
import type { SendRow as Row } from '@/src/messages'
import { useClipboard } from '../hooks/useClipboard'
import { language } from '../i18n'
import { IconButton } from './Button'
import { Icon } from './Icon'

/** Short and 24-hour, so a row's lines fit the popup; the year only when it isn't this one. */
function when(iso: string, now = new Date()): string {
	const date = new Date(iso)
	return date.toLocaleString(language(), {
		day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
		...(date.getFullYear() !== now.getFullYear() && { year: 'numeric' }),
	})
}

/** Keepiq sends have no name, so type and creation time stand in. */
export function sendLabel(row: Row): string {
	const time = when(row.createdAt)
	return row.payloadType === 'credential' ? i18n.t('send.labelCredential', { time }) : i18n.t('send.labelText', { time })
}

export const isExpired = (row: Row, now = Date.now()) => row.expiresAt !== null && Date.parse(row.expiresAt) <= now

interface Props {
	row: Row
	/** What the row's buttons are named after; unique in the list, unlike two labels of the same minute. */
	name: string
	link: string | undefined
	onRemove: (row: Row) => void
}

export function SendRow({ row, name, link, onRemove }: Props) {
	const copy = useClipboard()
	const expired = isExpired(row)
	let expiry: string
	if (expired) expiry = i18n.t('send.expired')
	else expiry = row.expiresAt === null ? i18n.t('send.neverExpires') : i18n.t('send.expires', { date: when(row.expiresAt) })
	return (
		<li className="item send-row">
			<div className="send-row__main">
				<span className="item__icon"><Icon name={row.payloadType === 'credential' ? 'key' : 'note'} size={18} /></span>
				<span className="item__text">
					<span className="item__name">{sendLabel(row)}</span>
					<span className="item__sub">{i18n.t('send.viewsLeft', row.maxViews, { left: String(row.remainingViews) })}</span>
					<span className={`item__sub${expired ? ' send-row__expired' : ''}`}>{expiry}</span>
					{(row.hasPassword || (!link && !expired)) && (
						<span className="send-row__tags">
							{row.hasPassword && <span className="badge badge--accent">{i18n.t('send.passwordBadge')}</span>}
							{!link && !expired && <span className="send-row__note">{i18n.t('send.linkGone')}</span>}
						</span>
					)}
				</span>
			</div>
			<div className="item__actions">
				{link && !expired && <IconButton icon="copy" label={i18n.t('send.copyLinkOf', { name })} onClick={() => void copy(link, i18n.t('send.link'))} />}
				<IconButton icon="trash" className="icon-btn--danger" label={i18n.t('send.removeOf', { name })} onClick={() => onRemove(row)} />
			</div>
		</li>
	)
}
