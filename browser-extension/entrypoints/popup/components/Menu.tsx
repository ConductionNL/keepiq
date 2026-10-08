import { useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { Icon, type IconName } from './Icon'

export interface MenuItem {
	label: string
	onSelect: () => void
	/** Shown as the tooltip; the entry stays focusable so the reason can be read. */
	disabledReason?: string
}

interface Props {
	label: string
	items: MenuItem[]
	/** An icon-only trigger; otherwise `text` is shown and `label` is still its name. */
	icon?: IconName
	text?: ReactNode
	className?: string
	pressed?: boolean
	disabled?: boolean
}

export function Menu({ label, items, icon, text, className, pressed, disabled }: Props) {
	const [open, setOpen] = useState(false)
	const [up, setUp] = useState(false)
	const wrapper = useRef<HTMLDivElement>(null)
	const trigger = useRef<HTMLButtonElement>(null)
	const id = useId()

	const entries = () => [...(wrapper.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])]

	// Opens upward when the scroll area has no room below, e.g. on the last row.
	useLayoutEffect(() => {
		const popover = wrapper.current?.querySelector('.popover')
		if (!open || !popover) return setUp(false)
		const area = wrapper.current!.closest('.popup__body')?.getBoundingClientRect()
		const box = popover.getBoundingClientRect()
		const anchor = wrapper.current!.getBoundingClientRect()
		setUp(!!area && box.bottom > area.bottom && anchor.top - box.height - 4 >= area.top)
	}, [open])

	useEffect(() => {
		if (!open) return
		entries()[0]?.focus()
		const outside = (event: MouseEvent) => {
			if (!wrapper.current?.contains(event.target as Node)) setOpen(false)
		}
		document.addEventListener('mousedown', outside)
		return () => document.removeEventListener('mousedown', outside)
	}, [open])

	function close() {
		setOpen(false)
		trigger.current?.focus()
	}

	function onKeyDown(event: KeyboardEvent) {
		const all = entries()
		const at = all.indexOf(document.activeElement as HTMLElement)
		if (event.key === 'Escape') {
			event.preventDefault()
			close()
		} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault()
			all[(at + (event.key === 'ArrowDown' ? 1 : all.length - 1)) % all.length]?.focus()
		} else if (event.key === 'Home' || event.key === 'End') {
			event.preventDefault()
			all[event.key === 'Home' ? 0 : all.length - 1]?.focus()
		} else if (event.key === 'Tab') {
			setOpen(false)
		}
	}

	return (
		<div className="menu-anchor" ref={wrapper} onKeyDown={open ? onKeyDown : undefined}>
			<button
				ref={trigger}
				type="button"
				className={className ?? 'icon-btn'}
				aria-label={text ? undefined : label}
				title={text ? undefined : label}
				aria-haspopup="menu"
				aria-expanded={open}
				aria-controls={open ? id : undefined}
				aria-pressed={pressed}
				disabled={disabled}
				onClick={() => setOpen(!open)}
			>
				{icon && <Icon name={icon} />}
				{text}
			</button>
			{open && (
				<ul className={`popover${up ? ' popover--up' : ''}`} role="menu" id={id} aria-label={label}>
					{items.map((item) => (
						<li key={item.label} role="none">
							<button
								type="button"
								role="menuitem"
								className="popover__item"
								tabIndex={-1}
								aria-disabled={item.disabledReason ? true : undefined}
								title={item.disabledReason}
								onClick={() => {
									if (item.disabledReason) return
									close()
									item.onSelect()
								}}
							>
								{item.label}
							</button>
						</li>
					))}
				</ul>
			)}
		</div>
	)
}
