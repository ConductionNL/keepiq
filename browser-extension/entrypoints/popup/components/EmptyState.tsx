import type { ReactNode } from 'react'
import { Icon, type IconName } from './Icon'

export function EmptyState({ icon, title, children }: { icon: IconName; title: string; children?: ReactNode }) {
	return (
		<div className="empty">
			<span className="empty__icon"><Icon name={icon} size={24} /></span>
			<h2 className="empty__title">{title}</h2>
			{children}
		</div>
	)
}
