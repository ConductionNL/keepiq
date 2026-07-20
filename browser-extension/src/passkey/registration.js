/**
 * Browser-adaptive WebAuthn provider registration (extension-passkey-provider
 * §1). Where the browser exposes the native proxy API (`chrome.webAuthentication
 * Proxy`, Chrome/Edge) we register through it; otherwise the page-context shim
 * (`inpage-shim.js`) + content-script relay drives the same orchestrator
 * (Firefox and others). This module wires ONLY the native proxy; it is a no-op
 * when the API is absent.
 *
 * On any orchestrator error (declined / no-credential / unsupported / locked)
 * the request is completed with a DOM error so the browser falls through to the
 * platform authenticator rather than hanging the page ceremony.
 */

function fallThroughError(message) {
	// NotAllowedError makes the RP fall back to another authenticator.
	return { name: 'NotAllowedError', message: 'Doriath: ' + message }
}

/**
 * @param {{ handleCreate: Function, handleGet: Function }} orchestrator
 * @return {void}
 */
export function registerWebAuthnProxy(orchestrator) {
	const proxy = (typeof chrome !== 'undefined') ? chrome.webAuthenticationProxy : undefined
	if (!proxy || !proxy.onCreateRequest) {
		return // no native proxy — the page-context shim path handles it
	}

	try {
		proxy.attach(() => {})
	} catch {
		// attach may reject if another provider is active; the shim still works.
	}

	proxy.onCreateRequest.addListener(async (details) => {
		try {
			const options = JSON.parse(details.requestDetailsJson).publicKey || JSON.parse(details.requestDetailsJson)
			const credential = await orchestrator.handleCreate(options, originOf(details))
			proxy.completeCreateRequest({ requestId: details.requestId, responseJson: JSON.stringify(credential) })
		} catch (e) {
			proxy.completeCreateRequest({ requestId: details.requestId, error: fallThroughError(e.message || String(e)) })
		}
	})

	proxy.onGetRequest.addListener(async (details) => {
		try {
			const options = JSON.parse(details.requestDetailsJson).publicKey || JSON.parse(details.requestDetailsJson)
			const assertion = await orchestrator.handleGet(options, originOf(details))
			proxy.completeGetRequest({ requestId: details.requestId, responseJson: JSON.stringify(assertion) })
		} catch (e) {
			proxy.completeGetRequest({ requestId: details.requestId, error: fallThroughError(e.message || String(e)) })
		}
	})
}

function originOf(details) {
	// The proxy provides the requesting frame's origin on newer builds; fall back
	// to the rp id embedded in the request when absent.
	if (details.origin) return details.origin
	try {
		const rp = JSON.parse(details.requestDetailsJson)
		const rpId = rp.publicKey?.rp?.id || rp.publicKey?.rpId || rp.rp?.id || rp.rpId
		return rpId ? 'https://' + rpId : ''
	} catch {
		return ''
	}
}
