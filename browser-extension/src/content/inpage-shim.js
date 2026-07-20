/**
 * Page-context WebAuthn shim (extension-passkey-provider §1.2) — injected into
 * the page's own JS world on browsers without a native WebAuthn proxy (Firefox
 * and others). It overrides `navigator.credentials.create/get`, forwards the
 * ceremony to the content script (origin-checked `postMessage`), and returns a
 * PublicKeyCredential-shaped result. On decline/no-match it re-invokes the
 * ORIGINAL implementation so the platform authenticator still works.
 */
(function() {
	const original = {
		create: navigator.credentials && navigator.credentials.create.bind(navigator.credentials),
		get: navigator.credentials && navigator.credentials.get.bind(navigator.credentials),
	}
	if (!original.create) return

	let seq = 0
	const pending = new Map()

	window.addEventListener('message', (event) => {
		if (event.source !== window) return
		const data = event.data
		if (!data || data.__doriath !== 'response') return
		const entry = pending.get(data.id)
		if (!entry) return
		pending.delete(data.id)
		entry(data)
	})

	function ask(op, options, publicKey) {
		return new Promise((resolve) => {
			const id = ++seq
			pending.set(id, resolve)
			window.postMessage({
				__doriath: 'request',
				id,
				op,
				origin: location.origin,
				options: serializeOptions(publicKey),
			}, location.origin)
		})
	}

	function b64url(buf) {
		const bytes = new Uint8Array(buf)
		let s = ''
		for (const b of bytes) s += String.fromCharCode(b)
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
	}
	function bytes(arr) {
		return Uint8Array.from(arr).buffer
	}

	function serializeOptions(pk) {
		const out = { ...pk }
		if (pk.challenge) out.challenge = b64url(pk.challenge)
		if (pk.user && pk.user.id) out.user = { ...pk.user, id: b64url(pk.user.id) }
		if (pk.allowCredentials) out.allowCredentials = pk.allowCredentials.map((c) => ({ ...c, id: b64url(c.id) }))
		if (pk.excludeCredentials) out.excludeCredentials = pk.excludeCredentials.map((c) => ({ ...c, id: b64url(c.id) }))
		return out
	}

	function toCredential(r) {
		const resp = r.response
		const response = {}
		if (resp.attestationObject) {
			response.clientDataJSON = bytes(resp.clientDataJSON)
			response.attestationObject = bytes(resp.attestationObject)
			response.getAuthenticatorData = () => bytes([])
			response.getPublicKey = () => null
		} else {
			response.clientDataJSON = bytes(resp.clientDataJSON)
			response.authenticatorData = bytes(resp.authenticatorData)
			response.signature = bytes(resp.signature)
			response.userHandle = resp.userHandle ? bytes(resp.userHandle) : null
		}
		return {
			id: r.id,
			rawId: bytes(r.rawId),
			type: 'public-key',
			response,
			getClientExtensionResults: () => ({}),
			authenticatorAttachment: 'platform',
		}
	}

	navigator.credentials.create = async function(options) {
		if (!options || !options.publicKey) return original.create(options)
		const res = await ask('create', options, options.publicKey)
		if (res.error || !res.credential) return original.create(options) // fall-through
		return toCredential(res.credential)
	}

	navigator.credentials.get = async function(options) {
		if (!options || !options.publicKey) return original.get(options)
		const res = await ask('get', options, options.publicKey)
		if (res.error || !res.assertion) return original.get(options) // fall-through
		return toCredential(res.assertion)
	}
})()
