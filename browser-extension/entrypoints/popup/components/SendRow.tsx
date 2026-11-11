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

function expiryText(row: Row, expired: boolean): string {
	if (expired) return i18n.t('send.expired')
	return row.expiresAt === null ? i18n.t('send.neverExpires') : i18n.t('send.expires', { date: when(row.expiresAt) })
}

interface StateProps {
	row: Row
	/** The link this browser kept for the send, and whether the send has expired. */
	live: LinkState
}

interface LinkState {
	link: string | undefined
	expired: boolean
}

/** The send still opens, but this browser no longer has its link. */
const isLinkGone = ({ link, expired }: LinkState) => !link && !expired

/** The password badge, and the note that this browser lost the link of a send that still opens. */
function SendTags({ row, live }: StateProps) {
	const linkGone = isLinkGone(live)
	if (!row.hasPassword && !linkGone) return null
	return (
		<span className="send-row__tags">
			{row.hasPassword && <span className="badge badge--accent">{i18n.t('send.passwordBadge')}</span>}
			{linkGone && <span className="send-row__note">{i18n.t('send.linkGone')}</span>}
		</span>
	)
}

function SendSummary({ row, live }: StateProps) {
	return (
		<div className="send-row__main">
			<span className="item__icon"><Icon name={row.payloadType === 'credential' ? 'key' : 'note'} size={18} /></span>
			<span className="item__text">
				<span className="item__name">{sendLabel(row)}</span>
				<span className="item__sub">{i18n.t('send.viewsLeft', row.maxViews, { left: String(row.remainingViews) })}</span>
				<span className={`item__sub${live.expired ? ' send-row__expired' : ''}`}>{expiryText(row, live.expired)}</span>
				<SendTags row={row} live={live} />
			</span>
		</div>
	)
}

export function SendRow({ row, name, link, onRemove }: Props) {
	const copy = useClipboard()
	const expired = isExpired(row)
	return (
		<li className="item send-row">
			<SendSummary row={row} live={{ link, expired }} />
			<div className="item__actions">
				{link && !expired && <IconButton icon="copy" label={i18n.t('send.copyLinkOf', { name })} onClick={() => void copy(link, i18n.t('send.link'))} />}
				<IconButton icon="trash" className="icon-btn--danger" label={i18n.t('send.removeOf', { name })} onClick={() => onRemove(row)} />
			</div>
		</li>
	)
}
