/**
 * Every message envelope that crosses a runtime boundary, in one place. The
 * `webextension-polyfill` types payloads as `unknown` (see
 * WXT-AND-BROWSERS.md § 3), so each listener asserts one of these unions at its
 * boundary — a single cast in a single spot, instead of `any` leaking inward.
 *
 * Keep the unions tagged with `kind` so listeners can switch without unwrapping.
 */

/** Content script → background. Fire-and-forget. */
export type ContentToBackground =
	| { kind: 'page_ready'; url: string }

/** Background → content script. Fire-and-forget. */
export type BackgroundToContent =
	| { kind: 'enabled_changed'; enabled: boolean }

/** Popup → background. Request/response — the background replies with a value. */
export type PopupToBackground =
	| { kind: 'get_state' }
	| { kind: 'set_enabled'; enabled: boolean }

/** What the popup renders. The background owns the derivation. */
export interface PopupState {
	enabled: boolean
	/** Tabs that have reported a live content script this worker generation. */
	activeTabs: number
}
