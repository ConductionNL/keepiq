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
