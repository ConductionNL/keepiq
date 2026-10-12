import { THEMES, type Theme } from '@/src/messages'

/**
 * A display cache of `settings.global`, read before mounting so a pinned theme paints the first frame.
 * `storage.local` stays the source of truth (ext-theme design).
 */
export const THEME_MIRROR_KEY = 'keepiq.theme'

const isTheme = (value: unknown): value is Theme => THEMES.includes(value as never)

/** System default sets no attribute, so `popup.css`'s `prefers-color-scheme` query decides. */
export function applyTheme(theme: Theme) {
	if (theme === 'system') delete document.documentElement.dataset.theme
	else document.documentElement.dataset.theme = theme
}

// localStorage can be missing or throw, for example with site data blocked.
export function mirroredTheme(): Theme {
	try {
		const stored = localStorage.getItem(THEME_MIRROR_KEY)
		return isTheme(stored) ? stored : 'system'
	} catch {
		return 'system'
	}
}

/** Writing the mirror also repaints other open extension pages, through their `storage` event. */
export function showTheme(theme: Theme) {
	applyTheme(theme)
	try {
		localStorage.setItem(THEME_MIRROR_KEY, theme)
	} catch {
		// The background's value still applies on every open.
	}
}

export function followMirror(event: StorageEvent) {
	if (event.key === THEME_MIRROR_KEY) applyTheme(isTheme(event.newValue) ? event.newValue : 'system')
}
