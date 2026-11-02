import type { ButtonHTMLAttributes } from 'react'
import { Icon, type IconName } from './Icon'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: 'primary' | 'secondary' | 'link' | 'danger'
	busy?: boolean
	/** While busy or disabled it keeps focus and ignores clicks (`aria-disabled`), so focus is still there when the reply comes. */
	focusable?: boolean
}

export function Button({ variant = 'primary', busy = false, focusable = false, disabled, children, type = 'button', onClick, ...rest }: Props) {
	const off = disabled || busy
	return (
		<button
			{...rest} type={type} className={`btn btn--${variant}`} disabled={off && !focusable} aria-disabled={focusable ? off : undefined} aria-busy={busy}
			onClick={focusable && off ? (event) => event.preventDefault() : onClick}
		>
			{busy && <span className="spinner" aria-hidden="true" />}
			{children}
		</button>
	)
}

interface IconButtonProps extends Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'> {
	icon: IconName
	/** The accessible name, also shown as the tooltip. */
	label: string
}

export function IconButton({ icon, label, className, type = 'button', ...rest }: IconButtonProps) {
	return (
		<button {...rest} type={type} className={`icon-btn ${className ?? ''}`} aria-label={label} title={label}>
			<Icon name={icon} />
		</button>
	)
}
