export const FOLDER_COLORS = [
	{ key: 'red', label: 'Red', light: '#c92020', dark: '#ff6b66' },
	{ key: 'orange', label: 'Orange', light: '#d27600', dark: '#ffa84a' },
	{ key: 'yellow', label: 'Yellow', light: '#a88a00', dark: '#f8e664' },
	{ key: 'green', label: 'Green', light: '#2c8a3f', dark: '#6cd283' },
	{ key: 'cyan', label: 'Cyan', light: '#0099b5', dark: '#5cdbef' },
	{ key: 'blue', label: 'Blue', light: '#0064a3', dark: '#4fa8e6' },
	{ key: 'purple', label: 'Purple', light: '#823884', dark: '#c771c7' },
	{ key: 'pink', label: 'Pink', light: '#cc4e7f', dark: '#ff9cc1' },
]

const FOLDER_COLOR_MAP = Object.fromEntries(FOLDER_COLORS.map(c => [c.key, c]))

/**
 * Resolve a stored color value to a hex string for the current theme.
 *
 * Accepts a key (e.g. 'blue') and looks up the theme-appropriate variant.
 * As a backwards-compatible fallthrough, a value that starts with '#' is
 * treated as a literal hex (legacy storage from before the key-based
 * palette landed) and returned unchanged.
 *
 * @param {string|null|undefined} value The stored customColor value.
 * @param {'dark'|'light'} theme The current Nextcloud theme.
 * @return {string|null} A hex color string, or null when unset/unknown.
 */
export function resolveFolderColor(value, theme) {
	if (!value) return null
	if (value.startsWith('#')) return value
	const entry = FOLDER_COLOR_MAP[value]
	if (!entry) return null
	return theme === 'dark' ? entry.dark : entry.light
}
