import { EmptyState } from '../components/EmptyState'
import type { IconName } from '../components/Icon'

/** Generator, Send and Settings until their changes replace it. */
export function Placeholder({ icon, title }: { icon: IconName; title: string }) {
	return (
		<EmptyState icon={icon} title={title}>
			<p className="empty__text">This arrives in a later update.</p>
		</EmptyState>
	)
}
