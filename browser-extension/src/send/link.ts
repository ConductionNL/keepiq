import { toBase64Url } from '@/src/crypto/base64'

/**
 * The web app's public page for a send, through `index.php` like the web app link so it works without pretty URLs.
 * The key rides the fragment, which the browser never sends; a password send's link carries none.
 */
export function buildShareLink(serverUrl: string, token: string, rawKey: Uint8Array | null): string {
	const base = `${serverUrl}/index.php/apps/keepiq/public/send/${encodeURIComponent(token)}`
	return rawKey ? `${base}#k=${toBase64Url(rawKey)}` : base
}
