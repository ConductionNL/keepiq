// The test site's relying party: it registers and checks passkeys as a real site's server would, in the page and with
// WebCrypto only, so whatever authenticator answered must produce data that verifies. Passkeys it registered stay in
// this origin's localStorage.
const STORE = 'keepiq-test-site.passkeys'
const FLAGS = { UP: 0x01, UV: 0x04, BE: 0x08, BS: 0x10, AT: 0x40, ED: 0x80 }

export const toBase64url = (bytes) => btoa(String.fromCharCode(...new Uint8Array(bytes))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
const fromBase64url = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (char) => char.charCodeAt(0))
const hex = (bytes) => [...bytes].map((byte) => byte.toString(16).padStart(2, '0')).join('')
const sha256 = async (bytes) => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
const same = (a, b) => a.length === b.length && a.every((byte, i) => byte === b[i])

export function savedPasskeys() {
	try {
		return JSON.parse(localStorage.getItem(STORE) ?? '[]')
	} catch {
		return []
	}
}

const storePasskeys = (list) => localStorage.setItem(STORE, JSON.stringify(list))
export const forgetPasskeys = () => localStorage.removeItem(STORE)

/** The few CBOR shapes WebAuthn uses: integers, byte and text strings, arrays and maps. */
function decodeCbor(bytes) {
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
	let offset = 0
	const length = (info) => {
		if (info < 24) return info
		const size = { 24: 1, 25: 2, 26: 4 }[info]
		if (!size) throw new Error(`CBOR length ${info} is not supported`)
		const value = size === 1 ? view.getUint8(offset) : size === 2 ? view.getUint16(offset) : view.getUint32(offset)
		offset += size
		return value
	}
	const item = () => {
		const head = view.getUint8(offset++)
		const value = length(head & 0x1f)
		switch (head >> 5) {
			case 0: return value
			case 1: return -1 - value
			case 2: return bytes.slice(offset, offset += value)
			case 3: return new TextDecoder().decode(bytes.slice(offset, offset += value))
			case 4: return Array.from({ length: value }, () => item())
			case 5: {
				const map = new Map()
				for (let i = 0; i < value; i++) map.set(item(), item())
				return map
			}
			default: throw new Error(`CBOR major type ${head >> 5} is not supported`)
		}
	}
	return { value: item(), end: offset }
}

// Cross-origin frames get `crossOrigin: true` from the browser; reading `top.location` is how a page tells.
function framedCrossOrigin() {
	try {
		return window.top.location.origin !== location.origin
	} catch {
		return true
	}
}

function checkClientData(bytes, type, challenge) {
	const data = JSON.parse(new TextDecoder().decode(bytes))
	if (data.type !== type) throw new Error(`clientDataJSON type is ${data.type}, not ${type}`)
	if (data.challenge !== toBase64url(challenge)) throw new Error('clientDataJSON holds another challenge')
	if (data.origin !== location.origin) throw new Error(`clientDataJSON origin is ${data.origin}, not ${location.origin}`)
	if (Boolean(data.crossOrigin) !== framedCrossOrigin()) throw new Error(`clientDataJSON crossOrigin is ${data.crossOrigin}`)
}

async function readAuthData(authData, attested) {
	if (!same(authData.slice(0, 32), await sha256(new TextEncoder().encode(location.hostname)))) throw new Error(`rpIdHash is not the hash of ${location.hostname}`)
	const flags = authData[32]
	if (!(flags & FLAGS.UP)) throw new Error('the user-present flag is not set')
	const counter = new DataView(authData.buffer, authData.byteOffset).getUint32(33)
	const names = Object.keys(FLAGS).filter((name) => flags & FLAGS[name])
	if (!attested) return { flags: names, counter }
	if (!(flags & FLAGS.AT)) throw new Error('the attested-credential flag is not set')
	const view = new DataView(authData.buffer, authData.byteOffset)
	const idLength = view.getUint16(53)
	const credentialId = authData.slice(55, 55 + idLength)
	const { value: cose, end } = decodeCbor(authData.slice(55 + idLength))
	if (55 + idLength + end !== authData.length && !(flags & FLAGS.ED)) throw new Error('authenticator data has bytes after the public key')
	return { flags: names, counter, aaguid: hex(authData.slice(37, 53)), credentialId, cose }
}

/** An EC2 P-256 ES256 COSE key as a JWK. */
function coseToJwk(cose) {
	if (cose.get(1) !== 2 || cose.get(3) !== -7 || cose.get(-1) !== 1) throw new Error('the public key is not ES256 on P-256')
	const x = cose.get(-2)
	const y = cose.get(-3)
	if (x?.length !== 32 || y?.length !== 32) throw new Error('the public key coordinates are not 32 bytes')
	return { kty: 'EC', crv: 'P-256', x: toBase64url(x), y: toBase64url(y), ext: true }
}

/** ASN.1 DER `SEQUENCE { INTEGER r, INTEGER s }` to the 64 bytes WebCrypto verifies. */
function derToRaw(der) {
	if (der[0] !== 0x30 || der[1] !== der.length - 2) throw new Error('the signature is not DER')
	let offset = 2
	const integer = () => {
		if (der[offset] !== 0x02) throw new Error('the signature is not DER')
		const size = der[offset + 1]
		let value = der.slice(offset + 2, offset + 2 + size)
		offset += 2 + size
		while (value.length > 32 && value[0] === 0) value = value.slice(1)
		if (value.length > 32) throw new Error('a signature integer is over 32 bytes')
		const out = new Uint8Array(32)
		out.set(value, 32 - value.length)
		return out
	}
	const raw = new Uint8Array(64)
	raw.set(integer(), 0)
	raw.set(integer(), 32)
	if (offset !== der.length) throw new Error('the signature has trailing bytes')
	return raw
}

function checkCredentialObject(credential, response) {
	if (!(credential instanceof PublicKeyCredential)) throw new Error('the result is not a PublicKeyCredential')
	if (!(credential.response instanceof response)) throw new Error(`the response is not an ${response.name}`)
	if (credential.type !== 'public-key' || credential.id !== toBase64url(credential.rawId)) throw new Error('the credential id does not match its rawId')
}

const userHandleFor = async (userName) => (await sha256(new TextEncoder().encode(`keepiq-test-site:${userName}`))).slice(0, 16)

export async function register(userName, userVerification, signal) {
	const challenge = crypto.getRandomValues(new Uint8Array(32))
	const userHandle = await userHandleFor(userName)
	const credential = await navigator.credentials.create({
		signal,
		publicKey: {
			rp: { name: 'Keepiq test site' },
			user: { id: userHandle, name: userName, displayName: userName },
			challenge,
			pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
			authenticatorSelection: { residentKey: 'required', userVerification },
			excludeCredentials: savedPasskeys().filter((saved) => saved.userName === userName).map((saved) => ({ type: 'public-key', id: fromBase64url(saved.id) })),
			attestation: 'none',
			timeout: 120_000,
		},
	})
	checkCredentialObject(credential, AuthenticatorAttestationResponse)
	checkClientData(new Uint8Array(credential.response.clientDataJSON), 'webauthn.create', challenge)
	const { value: attestation } = decodeCbor(new Uint8Array(credential.response.attestationObject))
	if (attestation.get('fmt') !== 'none' || attestation.get('attStmt')?.size !== 0) throw new Error(`attestation format is ${attestation.get('fmt')}, not none`)
	const authData = await readAuthData(attestation.get('authData'), true)
	if (!same(authData.credentialId, new Uint8Array(credential.rawId))) throw new Error('the attested credential id is not the rawId')
	const jwk = coseToJwk(authData.cose)
	await crypto.subtle.importKey('jwk', jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
	const saved = { id: credential.id, jwk, counter: authData.counter, userName, userHandle: toBase64url(userHandle), aaguid: authData.aaguid }
	storePasskeys([...savedPasskeys().filter((entry) => entry.id !== saved.id), saved])
	return { ...saved, flags: authData.flags }
}

export async function signIn(options, signal) {
	const challenge = crypto.getRandomValues(new Uint8Array(32))
	const credential = await navigator.credentials.get({ ...options, signal, publicKey: { challenge, rpId: location.hostname, ...options.publicKey } })
	checkCredentialObject(credential, AuthenticatorAssertionResponse)
	const saved = savedPasskeys().find((entry) => entry.id === credential.id)
	if (!saved) throw new Error('this site has no passkey with that id; register one first')
	const { response } = credential
	const clientDataJSON = new Uint8Array(response.clientDataJSON)
	checkClientData(clientDataJSON, 'webauthn.get', challenge)
	const authenticatorData = new Uint8Array(response.authenticatorData)
	const authData = await readAuthData(authenticatorData, false)
	if ((authData.counter !== 0 || saved.counter !== 0) && authData.counter <= saved.counter) throw new Error(`the counter ${authData.counter} did not go up from ${saved.counter}`)
	if (response.userHandle && toBase64url(response.userHandle) !== saved.userHandle) throw new Error('the user handle is not the registered one')
	const key = await crypto.subtle.importKey('jwk', saved.jwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
	const signed = new Uint8Array([...authenticatorData, ...await sha256(clientDataJSON)])
	if (!await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, derToRaw(new Uint8Array(response.signature)), signed)) throw new Error('the signature does not verify')
	storePasskeys(savedPasskeys().map((entry) => (entry.id === saved.id ? { ...entry, counter: authData.counter } : entry)))
	return { ...saved, counter: authData.counter, flags: authData.flags }
}

/** Says how a request ended in `status`, with what the e2e tests read as data attributes. */
export function report(status, outcome) {
	for (const name of ['result', 'user', 'counter', 'flags', 'aaguid', 'error']) delete status.dataset[name]
	if (outcome.error) {
		const { name, message } = outcome.error
		Object.assign(status.dataset, { result: 'failed', error: name })
		status.textContent = `Failed: ${name}: ${message}`
		return
	}
	const { result, passkey } = outcome
	Object.assign(status.dataset, { result, user: passkey.userName, counter: passkey.counter, flags: passkey.flags.join(' '), aaguid: passkey.aaguid })
	const verb = result === 'registered' ? `Registered a passkey for ${passkey.userName}` : `Signed in as ${passkey.userName}`
	status.textContent = `${verb}. Counter ${passkey.counter}, flags ${passkey.flags.join(' ')}, authenticator ${passkey.aaguid === '0'.repeat(32) ? 'without AAGUID' : passkey.aaguid}.`
}
