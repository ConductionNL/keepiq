import type { ReactNode } from 'react'
import { Icon } from './Icon'

export function Banner({ tone, role, children }: { tone: 'error' | 'warning'; role: 'alert' | 'status'; children: ReactNode }) {
	return (
		<div className={`banner banner--${tone}`} role={role}>
			<Icon name="alert" />
			<p>{children}</p>
		</div>
	)
}

export function ErrorBanner({ children }: { children: string | null }) {
	if (!children) return null
	return <Banner tone="error" role="alert">{children}</Banner>
}
