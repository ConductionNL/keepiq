import { useSettingsStore } from './modules/settings.js'

/**
 * Bootstrap the Pinia stores: load the settings (admin flag and app config)
 * that the dashboard and settings UI render from.
 *
 * @return {object} The initialised settings store.
 * @spec openspec/specs/app-shell/spec.md#requirement-keepiq-operates-without-any-other-conduction-app
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()

	await settingsStore.fetchSettings()

	return { settingsStore }
}

export { useSettingsStore }
