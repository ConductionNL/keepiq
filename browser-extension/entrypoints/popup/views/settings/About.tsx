import { i18n } from '#i18n'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Icon, type IconName } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import { ABOUT_LINKS } from './about-links'

/** Versions are shown as received; only `src/api/keepiq-version.ts` may read one. */
export function About() {
	const { snapshot, failed } = useAccountSettings()
	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	const rate = import.meta.env.BROWSER === 'firefox' ? ABOUT_LINKS.rate.firefox : ABOUT_LINKS.rate.chrome
	const links: { href: string; label: string; icon: IconName }[] = [
		{ href: ABOUT_LINKS.help, label: i18n.t('settings.help'), icon: 'info' },
		{ href: ABOUT_LINKS.reportBug, label: i18n.t('settings.reportBug'), icon: 'alert' },
		{ href: ABOUT_LINKS.contactSupport, label: i18n.t('settings.contactSupport'), icon: 'send' },
		{ href: ABOUT_LINKS.privacyPolicy, label: i18n.t('settings.privacyPolicy'), icon: 'shield' },
		{ href: snapshot.webAppUrl, label: i18n.t('settings.webApp'), icon: 'vault' },
		...(rate ? [{ href: rate, label: i18n.t('settings.rate'), icon: 'check' as const }] : []),
	]

	return (
		<div className="stack">
			<div className="card form-card about">
				<p>{i18n.t('settings.version', { version: browser.runtime.getManifest().version })}</p>
				<p>{i18n.t('settings.server', { url: snapshot.serverUrl })}</p>
				<p>{snapshot.keepiqVersion ? i18n.t('settings.keepiqVersion', { version: snapshot.keepiqVersion }) : i18n.t('settings.keepiqVersionUnknown')}</p>
			</div>
			<ul className="card menu">
				{links.map((link) => (
					<li key={link.label}>
						<a className="menu__item" href={link.href} target="_blank" rel="noreferrer">
							<Icon name={link.icon} />
							<span className="menu__label">{link.label}</span>
							<Icon name="external" />
						</a>
					</li>
				))}
			</ul>
		</div>
	)
}
