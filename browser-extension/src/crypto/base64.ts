export function toBase64(bytes: Uint8Array): string {
	let binary = ''
	// Chunked: spreading a large array into fromCharCode overflows the stack.
	for (let i = 0; i < bytes.length; i += 0x8000) {
		binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
	}
	return btoa(binary)
}

export function fromBase64(base64: string): Uint8Array<ArrayBuffer> {
	return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0))
}

/** Unpadded, as a send link's `#k=` carries it. */
export function toBase64Url(bytes: Uint8Array): string {
	return toBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export function fromBase64Url(base64url: string): Uint8Array<ArrayBuffer> {
	const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/')
	return fromBase64(base64 + '='.repeat((4 - (base64.length % 4)) % 4))
}
