import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { Button } from './Button'
import { Banner } from './ErrorBanner'

export interface DialogAction {
	label: string
	onClick: () => void
	variant?: 'primary' | 'secondary' | 'danger'
	busy?: boolean
	disabled?: boolean
}

interface Props {
	title: string
	children?: ReactNode
	actions: DialogAction[]
	onCancel: () => void
	cancelLabel?: string
	error?: string | null
	/** Offered beside the error, for failures a second try can fix. */
	onRetry?: () => void
}

const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]'

/** In the page, never `window.confirm`: Firefox closes an extension popup when a native dialog opens. */
export function ConfirmDialog({ title, children, actions, onCancel, cancelLabel, error, onRetry }: Props) {
	const id = useId()
	const box = useRef<HTMLDivElement>(null)

	useEffect(() => {
		const opener = document.activeElement as HTMLElement | null
		box.current?.querySelector<HTMLElement>(FOCUSABLE)?.focus()
		return () => opener?.focus()
	}, [])

	function onKeyDown(event: KeyboardEvent) {
		if (event.key === 'Escape') {
			event.stopPropagation()
			onCancel()
		} else if (event.key === 'Tab') {
			// Keeps focus inside, as `aria-modal` promises.
			const all = [...(box.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])]
			const at = all.indexOf(document.activeElement as HTMLElement)
			const next = event.shiftKey ? (at <= 0 ? all.length - 1 : at - 1) : (at === all.length - 1 ? 0 : at + 1)
			event.preventDefault()
			all[next]?.focus()
		}
	}

	return (
		<div className="dialog-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onCancel()}>
			<div className="dialog" role="dialog" aria-modal="true" aria-labelledby={id} ref={box} onKeyDown={onKeyDown}>
				<h2 className="dialog__title" id={id}>{title}</h2>
				{children && <div className="dialog__body">{children}</div>}
				{error && <Banner tone="error" role="alert" action={onRetry && <Button variant="link" onClick={onRetry}>{i18n.t('vault.tryAgain')}</Button>}>{error}</Banner>}
				<div className="dialog__actions">
					{actions.map((action) => (
						<Button key={action.label} variant={action.variant ?? 'primary'} busy={action.busy} disabled={action.disabled} onClick={action.onClick}>{action.label}</Button>
					))}
					<Button variant="secondary" onClick={onCancel}>{cancelLabel ?? i18n.t('common.cancel')}</Button>
				</div>
			</div>
		</div>
	)
}
