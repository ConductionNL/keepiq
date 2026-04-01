import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'
import { useDashboardStore } from './modules/dashboard.js'
import { useAdminSettingsStore } from './modules/adminSettings.js'
import { useUserSettingsStore } from './modules/userSettings.js'

/**
 * Initialize all Pinia stores and seed them with initial data.
 *
 * @return {Promise<{settingsStore: object, objectStore: object}>}
 */
export async function initializeStores() {
	const settingsStore = useSettingsStore()
	const objectStore = useObjectStore()

	objectStore.configure({
		baseUrl: generateUrl('/apps/openregister/api/objects'),
		schemaBaseUrl: generateUrl('/apps/openregister/api/schemas'),
	})

	await settingsStore.fetchSettings()

	return { settingsStore, objectStore }
}

export { useObjectStore, useSettingsStore, useDashboardStore, useAdminSettingsStore, useUserSettingsStore }
