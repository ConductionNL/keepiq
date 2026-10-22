import { useEffect, useRef } from 'react'
import { i18n } from '#i18n'
import type { SendRow } from '@/src/messages'
import { Button } from '../../components/Button'
import { Icon } from '../../components/Icon'
import { PageFooter } from '../../components/PageFooter'
import { sendLabel } from '../../components/SendRow'
import { TextField } from '../../components/TextField'
import { useClipboard } from '../../hooks/useClipboard'

interface Props {
	row: SendRow
	link: string
	onDone: () => void
}

const keep = () => {}

/** The only screen that shows a link without a password in full; the list can copy it until the popup closes. */
export function SendCreated({ row, link, onDone }: Props) {
	const copy = useClipboard()
	const heading = useRef<HTMLHeadingElement>(null)
	// The form that had focus is gone.
	useEffect(() => heading.current?.focus(), [])
	return (
		<div className="stack">
			<header className="detail-head">
				<span className="item__icon item__icon--large"><Icon name="check" size={22} /></span>
				<div className="detail-head__text">
					<h2 className="detail-head__name" ref={heading} tabIndex={-1}>{i18n.t('send.ready')}</h2>
					<p className="detail-head__meta">{sendLabel(row)}</p>
				</div>
			</header>
			<div className="card form-card">
				<TextField label={i18n.t('send.link')} value={link} onChange={keep} readOnly verbatim onFocus={(event) => event.currentTarget.select()} />
			</div>
			<p className="hint">{row.hasPassword ? i18n.t('send.passwordNote') : i18n.t('send.shownOnce', row.maxViews)}</p>
			<PageFooter>
				<Button onClick={() => void copy(link, i18n.t('send.link'))}>{i18n.t('send.copyLink')}</Button>
				<Button variant="secondary" onClick={onDone}>{i18n.t('send.done')}</Button>
			</PageFooter>
		</div>
	)
}
