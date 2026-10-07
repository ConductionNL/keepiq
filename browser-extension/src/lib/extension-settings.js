/**
 * The extension's own settings for this browser (clients-extension-gaps):
 * the save and update offers, password suggestions in sign-up fields, the
 * type of a new item and the colour theme. One record for every account.
 *
 * @spec openspec/specs/extension-list-and-settings/spec.md#requirement-settings-for-autofill-new-items-and-appearance
 */

const KEY = 'extension-settings'

/** The themes a user can pick. */
export const THEMES = Object.freeze(['system', 'light', 'dark'])

/** The settings before the user changes any. */
export const DEFAULT_SETTINGS = Object.freeze({
	offerSave: true,
	offerUpdate: true,
	suggestPasswords: true,
	defaultType: 'login',
	theme: 'system',
})

/**
 * Settings with unknown keys dropped and bad values replaced by defaults.
 *
 * @param {object} value The stored or proposed settings.
 * @return {object}
 */
export function cleanSettings(value) {
	const v = value && typeof value === 'object' ? value : {}
	return {
		offerSave: v.offerSave !== false,
		offerUpdate: v.offerUpdate !== false,
		suggestPasswords: v.suggestPasswords !== false,
		defaultType:
			typeof v.defaultType === 'string' && v.defaultType !== ''
				? v.defaultType.slice(0, 64)
				: DEFAULT_SETTINGS.defaultType,
		theme: THEMES.includes(v.theme) ? v.theme : DEFAULT_SETTINGS.theme,
	}
}

/**
 * Read the settings.
 *
 * @param {object|undefined} area The storage area.
 * @return {Promise<object>}
 */
export async function readSettings(area) {
	if (!area) return { ...DEFAULT_SETTINGS }
	return cleanSettings((await area.get(KEY))[KEY])
}

/**
 * Change some settings.
 *
 * @param {object|undefined} area The storage area.
 * @param {object} patch The changes.
 * @return {Promise<object>} The settings after the change.
 */
export function writeSettings(area, patch) {
	// One write at a time: two quick changes must not read the same old
	// record and undo each other.
	const run = writes.then(async () => {
		const next = cleanSettings({ ...(await readSettings(area)), ...patch })
		await area?.set({ [KEY]: next })
		return next
	})
	writes = run.catch(() => {})
	return run
}

// The pending writes, in order.
let writes = Promise.resolve()
