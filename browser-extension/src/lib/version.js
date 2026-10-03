/**
 * The version handshake with the Keepiq server (extension-store-release D5).
 * A store update can reach users before their organisation updates the
 * server; the extension then says so instead of failing on a missing route.
 *
 * @spec openspec/specs/extension-store-release/spec.md#requirement-the-extension-checks-the-server-version-on-pairing
 */

/**
 * The oldest Keepiq server this extension works with: the first one that
 * reports its version on pairing and serves the extension policy route.
 */
export const MIN_SERVER_VERSION = '0.3.4'

function parts(version) {
	const core = String(version || '').split(/[-+]/)[0]
	if (!/^\d+(\.\d+)*$/.test(core)) return null
	return core.split('.').map(Number)
}

/**
 * Whether a server version is at least the minimum. A missing or unreadable
 * version (an older server that does not report one) is not.
 *
 * @param {string|null|undefined} version The server's app version, as paired.
 * @param {string} minimum The minimum (defaults to MIN_SERVER_VERSION).
 * @return {boolean}
 */
export function isServerSupported(version, minimum = MIN_SERVER_VERSION) {
	const have = parts(version)
	const need = parts(minimum)
	if (!have || !need) return false
	for (let i = 0; i < Math.max(have.length, need.length); i++) {
		const a = have[i] ?? 0
		const b = need[i] ?? 0
		if (a !== b) return a > b
	}
	return true
}
