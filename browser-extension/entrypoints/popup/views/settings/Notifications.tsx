import { useId } from 'react'
import { i18n } from '#i18n'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { OptionCheckbox } from '../../components/OptionCheckbox'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import type { SettingsSection } from './SettingsIndex'

/** Bitwarden's Settings → Notifications, ending with where Keepiq's own preferences live (proposal). */
export function Notifications({ onOpen }: { onOpen: (section: SettingsSection) => void }) {
	const id = useId()
	const { snapshot, failed, update } = useAccountSettings()
	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	const later = snapshot.autofillFeatures.includes('savePrompts') ? undefined : i18n.t('settings.comingLater')

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<div className="card form-card">
				<OptionCheckbox label={i18n.t('settings.askToAdd')} checked={snapshot.values.askToAdd} onChange={(askToAdd) => void update({ askToAdd })} lockedBy={later} />
				<OptionCheckbox label={i18n.t('settings.askToUpdate')} checked={snapshot.values.askToUpdate} onChange={(askToUpdate) => void update({ askToUpdate })} lockedBy={later} />
			</div>
			<ul className="card menu">
				<li>
					{/* `aria-disabled` keeps it focusable, so the reason can be read. */}
					<button
						type="button"
						className="menu__item"
						aria-disabled={later ? true : undefined}
						aria-labelledby={`${id}-excluded`}
						aria-describedby={later ? `${id}-later` : undefined}
						onClick={later ? undefined : () => onOpen('excludedDomains')}
					>
						<Icon name="blocked" />
						<span className="menu__label">
							<span id={`${id}-excluded`}>{i18n.t('settings.excludedDomains')}</span>
							{later && <span className="menu__hint" id={`${id}-later`}>{later}</span>}
						</span>
						<Icon name="chevronRight" />
					</button>
				</li>
			</ul>
			<section className="section" aria-labelledby={`${id}-web`}>
				<h2 className="section__title" id={`${id}-web`}>{i18n.t('settings.webTitle')}</h2>
				<div className="card form-card">
					<p>{i18n.t('settings.webText')}</p>
					<a href={snapshot.webAppUrl} target="_blank" rel="noreferrer">{i18n.t('settings.openKeepiq')}</a>
				</div>
			</section>
		</div>
	)
}
