/**
 * Which frames may receive a fill (#740).
 *
 * The content script runs in every frame, and a fill message to a tab reaches
 * all of them. A credential matched for the site in the address bar must not
 * land in a third party's iframe (an embedded widget, an advert), so each frame
 * fills only when its own host is the host the fill was matched for. The match
 * is exact: a sibling subdomain or a parent domain is a different site here.
 *
 * @spec openspec/specs/browser-extension-autofill/spec.md
 */

/**
 * Whether the frame at `frameHost` may fill a credential matched for `matchedHost`.
 *
 * @param {string} frameHost The frame's own `location.hostname`.
 * @param {string} matchedHost The host of the tab the fill was matched for.
 * @return {boolean} True only for the same, non-empty host.
 */
export function frameMayFill(frameHost, matchedHost) {
	const frame = String(frameHost || '').toLowerCase()
	const matched = String(matchedHost || '').toLowerCase()
	return frame !== '' && frame === matched
}
