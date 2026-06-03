import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from './modules/object.js'
import { useSettingsStore } from './modules/settings.js'

/**
 * Bootstrap the Pinia stores: point the object store at OpenRegister and
 * load settings (admin/openregister flags) that the dashboard + settings UI
 * render from.
 *
 * @return {object} The initialised settings + object stores.
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
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

export { useObjectStore, useSettingsStore }
