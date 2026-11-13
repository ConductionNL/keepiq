import { useId } from 'react'
import { i18n } from '#i18n'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { OptionCheckbox } from '../../components/OptionCheckbox'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import type { SettingsSection } from './SettingsIndex'

type AccountSettings = ReturnType<typeof useAccountSettings>
type SettingsSnapshot = NonNullable<AccountSettings['snapshot']>

/** "Coming later" for a feature this build does not deliver yet. */
function comingLater(snapshot: SettingsSnapshot, feature: SettingsSnapshot['autofillFeatures'][number]): string | undefined {
	return snapshot.autofillFeatures.includes(feature) ? undefined : i18n.t('settings.comingLater')
}

interface PromptProps {
	snapshot: SettingsSnapshot
	update: AccountSettings['update']
	later: string | undefined
}

function Prompts({ snapshot, update, later }: PromptProps) {
	return (
		<div className="card form-card">
			<OptionCheckbox label={i18n.t('settings.askToAdd')} checked={snapshot.values.askToAdd} onChange={(askToAdd) => void update({ askToAdd })} lockedBy={later} />
			<OptionCheckbox label={i18n.t('settings.askToUpdate')} checked={snapshot.values.askToUpdate} onChange={(askToUpdate) => void update({ askToUpdate })} lockedBy={later} />
			<OptionCheckbox label={i18n.t('settings.askPasskeys')} checked={snapshot.values.askPasskeys} onChange={(askPasskeys) => void update({ askPasskeys })} lockedBy={comingLater(snapshot, 'passkeys')} />
		</div>
	)
}

interface ExcludedProps {
	id: string
	later: string | undefined
	onOpen: (section: SettingsSection) => void
}

function ExcludedDomainsItem({ id, later, onOpen }: ExcludedProps) {
	const open = later ? undefined : () => onOpen('excludedDomains')
	const hint = later && <span className="menu__hint" id={`${id}-later`}>{later}</span>
	return (
		<ul className="card menu">
			<li>
				{/* `aria-disabled` keeps it focusable, so the reason can be read. */}
				<button
					type="button"
					className="menu__item"
					aria-disabled={later ? true : undefined}
					aria-labelledby={`${id}-excluded`}
					aria-describedby={later ? `${id}-later` : undefined}
					onClick={open}
				>
					<Icon name="blocked" />
					<span className="menu__label">
						<span id={`${id}-excluded`}>{i18n.t('settings.excludedDomains')}</span>
						{hint}
					</span>
					<Icon name="chevronRight" />
				</button>
			</li>
		</ul>
	)
}

function WebSection({ id, webAppUrl }: { id: string; webAppUrl: string }) {
	return (
		<section className="section" aria-labelledby={`${id}-web`}>
			<h2 className="section__title" id={`${id}-web`}>{i18n.t('settings.webTitle')}</h2>
			<div className="card form-card">
				<p>{i18n.t('settings.webText')}</p>
				<a href={webAppUrl} target="_blank" rel="noreferrer">{i18n.t('settings.openKeepiq')}</a>
			</div>
		</section>
	)
}

/** Bitwarden's Settings → Notifications, ending with where Keepiq's own preferences live (proposal). */
export function Notifications({ onOpen }: { onOpen: (section: SettingsSection) => void }) {
	const id = useId()
	const { snapshot, failed, update } = useAccountSettings()
	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	const later = comingLater(snapshot, 'savePrompts')

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<Prompts snapshot={snapshot} update={update} later={later} />
			<ExcludedDomainsItem id={id} later={later} onOpen={onOpen} />
			<WebSection id={id} webAppUrl={snapshot.webAppUrl} />
		</div>
	)
}
