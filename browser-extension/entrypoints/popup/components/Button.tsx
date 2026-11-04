import type { ButtonHTMLAttributes, MouseEventHandler } from 'react'
import { Icon, type IconName } from './Icon'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: 'primary' | 'secondary' | 'link' | 'danger'
	busy?: boolean
	/** While busy or disabled it keeps focus and ignores clicks (`aria-disabled`), so focus is still there when the reply comes. */
	focusable?: boolean
}

interface OffProps {
	disabled: boolean
	'aria-disabled': boolean | undefined
	onClick: MouseEventHandler<HTMLButtonElement> | undefined
}

/** How a button is off: natively disabled, or when focusable, `aria-disabled` with its clicks swallowed. */
function offProps(off: boolean, focusable: boolean, onClick: MouseEventHandler<HTMLButtonElement> | undefined): OffProps {
	if (!focusable) return { disabled: off, 'aria-disabled': undefined, onClick }
	return { disabled: false, 'aria-disabled': off, onClick: off ? (event) => event.preventDefault() : onClick }
}

export function Button({ variant = 'primary', busy = false, focusable = false, disabled, children, type = 'button', onClick, ...rest }: Props) {
	return (
		<button {...rest} type={type} className={`btn btn--${variant}`} {...offProps(disabled || busy, focusable, onClick)} aria-busy={busy}>
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
