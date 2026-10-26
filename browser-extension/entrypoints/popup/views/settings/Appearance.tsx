import { i18n } from '#i18n'
import type { Theme } from '@/src/messages'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Loading } from '../../components/Loading'
import { Select } from '../../components/Select'
import { errorText } from '../../errors'
import { useSettings } from '../../hooks/useSettings'

export function Appearance() {
	const { settings, failed, update } = useSettings()
	if (!settings) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
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
		</div>
	)
}
