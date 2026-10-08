import type { ButtonHTMLAttributes } from 'react'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: 'primary' | 'secondary' | 'link' | 'danger'
	busy?: boolean
}

export function Button({ variant = 'primary', busy = false, disabled, children, type = 'button', ...rest }: Props) {
	return (
		<button {...rest} type={type} className={`btn btn--${variant}`} disabled={disabled || busy} aria-busy={busy}>
			{children}
		</button>
	)
}
