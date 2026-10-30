import { THEMES, type GlobalSettings, type Theme } from '@/src/messages'

/**
 * Display caches of `settings.global`, read before mounting so the theme and appearance paint the first frame.
 * `storage.local` stays the source of truth (ext-theme design).
 */
export const THEME_MIRROR_KEY = 'keepiq.theme'
export const APPEARANCE_MIRROR_KEY = 'keepiq.appearance'

type Appearance = Pick<GlobalSettings, 'compactMode' | 'showAnimations'>
const DEFAULT_APPEARANCE: Appearance = { compactMode: false, showAnimations: true }

const isTheme = (value: unknown): value is Theme => THEMES.includes(value as never)

/** System default sets no attribute, so `popup.css`'s `prefers-color-scheme` query decides. */
export function applyTheme(theme: Theme) {
	if (theme === 'system') delete document.documentElement.dataset.theme
	else document.documentElement.dataset.theme = theme
}

/** Root classes `popup.css` keys the compact tokens and the motion stop on. */
export function applyAppearance({ compactMode, showAnimations }: Appearance) {
	document.documentElement.classList.toggle('compact', compactMode)
	document.documentElement.classList.toggle('no-animations', !showAnimations)
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

function parseAppearance(stored: string | null): Appearance {
	try {
		const value: unknown = JSON.parse(stored ?? '')
		const { compactMode, showAnimations } = (typeof value === 'object' && value !== null ? value : {}) as Record<string, unknown>
		return typeof compactMode === 'boolean' && typeof showAnimations === 'boolean' ? { compactMode, showAnimations } : DEFAULT_APPEARANCE
	} catch {
		return DEFAULT_APPEARANCE
	}
}

export function mirroredAppearance(): Appearance {
	try {
		return parseAppearance(localStorage.getItem(APPEARANCE_MIRROR_KEY))
	} catch {
		return DEFAULT_APPEARANCE
	}
}

export function showAppearance({ compactMode, showAnimations }: Appearance) {
	applyAppearance({ compactMode, showAnimations })
	try {
		localStorage.setItem(APPEARANCE_MIRROR_KEY, JSON.stringify({ compactMode, showAnimations }))
	} catch {
		// As for the theme.
	}
}

export function followMirror(event: StorageEvent) {
	if (event.key === THEME_MIRROR_KEY) applyTheme(isTheme(event.newValue) ? event.newValue : 'system')
	else if (event.key === APPEARANCE_MIRROR_KEY) applyAppearance(parseAppearance(event.newValue))
}
