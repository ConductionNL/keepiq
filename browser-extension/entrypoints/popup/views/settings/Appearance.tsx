import { useEffect, useId } from 'react'
import { i18n } from '#i18n'
import type { Theme } from '@/src/messages'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Loading } from '../../components/Loading'
import { OptionCheckbox } from '../../components/OptionCheckbox'
import { Select } from '../../components/Select'
import { errorText } from '../../errors'
import { useSettings } from '../../hooks/useSettings'
import { language } from '../../i18n'

/** The browser's UI language, named in the catalog's language; there is no picker (ext-i18n). */
function browserLanguage(): string {
	const tag = browser.i18n.getUILanguage()
	try {
		return new Intl.DisplayNames([language()], { type: 'language' }).of(tag) ?? tag
	} catch {
		return tag
	}
}

export function Appearance() {
	const id = useId()
	const { settings, failed, update, retry } = useSettings()
	// The popup's first read failed: try again now this view needs the values.
	useEffect(() => {
		if (!settings && failed) void retry()
	}, [settings, failed, retry])
	if (!settings) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<div className="card form-card">
				<Select<Theme>
					label={i18n.t('settings.theme')}
					value={settings.theme}
					options={[
						{ value: 'system', label: i18n.t('settings.themeSystem') },
						{ value: 'light', label: i18n.t('settings.themeLight') },
						{ value: 'dark', label: i18n.t('settings.themeDark') },
					]}
					onChange={(theme) => void update({ theme })}
				/>
				<OptionCheckbox label={i18n.t('settings.compactMode')} checked={settings.compactMode} onChange={(compactMode) => void update({ compactMode })} />
				<OptionCheckbox label={i18n.t('settings.showAnimations')} checked={settings.showAnimations} onChange={(showAnimations) => void update({ showAnimations })} />
				<OptionCheckbox label={i18n.t('settings.quickCopy')} checked={settings.quickCopy} onChange={(quickCopy) => void update({ quickCopy })} />
				<OptionCheckbox
					label={i18n.t('settings.showBadgeCounter')}
					checked={settings.showBadgeCounter}
					onChange={(showBadgeCounter) => void update({ showBadgeCounter })}
				/>
				<div className="field" role="group" aria-labelledby={`${id}-language`}>
					<span className="field__label" id={`${id}-language`}>{i18n.t('settings.language')}</span>
					<p>{browserLanguage()}</p>
					<p className="field__hint">{i18n.t('settings.languageFollows')}</p>
				</div>
			</div>
		</div>
	)
}
