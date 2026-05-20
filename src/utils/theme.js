import Vue from 'vue'

function detectTheme() {
	if (typeof document === 'undefined') return 'light'
	if (document.body.hasAttribute('data-theme-dark')) return 'dark'
	if (document.body.hasAttribute('data-theme-default')) {
		return window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark'
	}
	return 'light'
}

const state = Vue.observable({ theme: detectTheme() })

let initialized = false
function init() {
	if (initialized || typeof document === 'undefined') return
	initialized = true

	const refresh = () => { state.theme = detectTheme() }

	const observer = new MutationObserver(refresh)
	observer.observe(document.body, {
		attributes: true,
		attributeFilter: ['data-theme-dark', 'data-theme-light', 'data-theme-default'],
	})

	const mq = window.matchMedia('(prefers-color-scheme: light)')
	if (mq.addEventListener) {
		mq.addEventListener('change', refresh)
	} else if (mq.addListener) {
		mq.addListener(refresh)
	}
}

/**
 * Current Nextcloud theme as a reactive value. Reads track the observable
 * so Vue components re-render when the user flips the theme attribute on
 * `<body>` (or their OS-level color-scheme preference changes).
 *
 * @return {'dark'|'light'} The current theme.
 */
export function currentTheme() {
	init()
	return state.theme
}
