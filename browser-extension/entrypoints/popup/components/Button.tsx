import type { ButtonHTMLAttributes } from 'react'
import { Icon, type IconName } from './Icon'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: 'primary' | 'secondary' | 'link' | 'danger'
	busy?: boolean
}

export function Button({ variant = 'primary', busy = false, disabled, children, type = 'button', ...rest }: Props) {
	return (
		<button {...rest} type={type} className={`btn btn--${variant}`} disabled={disabled || busy} aria-busy={busy}>
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
