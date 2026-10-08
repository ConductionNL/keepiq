import { i18n } from '#i18n'
import { EmptyState } from '../components/EmptyState'
import type { IconName } from '../components/Icon'

/** Generator, Send and Settings until their changes replace it. */
export function Placeholder({ icon, title }: { icon: IconName; title: string }) {
	return (
		<EmptyState icon={icon} title={title}>
			<p className="empty__text">{i18n.t('common.laterUpdate')}</p>
		</EmptyState>
	)
}
