/**
 * Passkey consent window — names the relying-party origin and requires an
 * explicit Allow before the service worker signs or registers (extension-
 * passkey-provider §2.1/§3.1: "explicit user confirmation before every sign").
 * Runs in the extension origin, so a page script cannot spoof it.
 */

const params = new URLSearchParams(location.search)
const rp = params.get('rp') || 'this site'
const op = params.get('op') || 'get'
const id = params.get('id')

document.getElementById('title').textContent =
	op === 'create' ? 'Create a passkey?' : 'Sign in with a passkey?'
document.getElementById('body').textContent =
	op === 'create'
		? `Create and store a new passkey for “${rp}” in your Doriath vault?`
		: `Use a passkey stored in your Doriath vault to sign in to “${rp}”?`

function respond(allow) {
	chrome.runtime.sendMessage({ type: 'passkey-consent-result', id, allow })
	window.close()
}

document.getElementById('allow').addEventListener('click', () => respond(true))
document.getElementById('deny').addEventListener('click', () => respond(false))
