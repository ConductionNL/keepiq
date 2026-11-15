import { describe, expect, it } from 'vitest'
import { passkeySupport } from './passkey-support'

const chrome = (major: number) => `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/${major}.0.0.0 Safari/537.36`
const edge = (major: number) => `${chrome(major)} Edg/${major}.0.0.0`
const firefox = (major: number) => `Mozilla/5.0 (X11; Linux x86_64; rv:${major}.0) Gecko/20100101 Firefox/${major}.0`

function credential({ platform = true, prf }: { platform?: boolean; prf?: boolean } = {}) {
	return {
		isUserVerifyingPlatformAuthenticatorAvailable: async () => platform,
		...(prf !== undefined && { getClientCapabilities: async () => ({ 'extension:prf': prf }) }),
	} as unknown as typeof PublicKeyCredential
}

describe('passkeySupport', () => {
	it.each([
		['Chrome 122', 'chrome', chrome(122)],
		['Edge 122', 'edge', edge(122)],
		['Firefox 150', 'firefox', firefox(150)],
	])('supports %s with a platform authenticator', async (_name, target, userAgent) => {
		expect(await passkeySupport({ target, userAgent, credential: credential() })).toBe('supported')
	})

	it.each([
		['Chrome 121', 'chrome', chrome(121)],
		['Edge 121', 'edge', edge(121)],
		['Firefox 149', 'firefox', firefox(149)],
	])('names %s as too old', async (_name, target, userAgent) => {
		expect(await passkeySupport({ target, userAgent, credential: credential() })).toBe('browser_too_old')
	})

	it('keeps Safari on the master password', async () => {
		const safari = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15'
		expect(await passkeySupport({ target: 'safari', userAgent: safari, credential: credential() })).toBe('unsupported')
	})

	it('refuses without WebAuthn, without a platform authenticator, or when PRF is reported missing', async () => {
		expect(await passkeySupport({ target: 'chrome', userAgent: chrome(130), credential: undefined })).toBe('unsupported')
		expect(await passkeySupport({ target: 'chrome', userAgent: chrome(130), credential: credential({ platform: false }) })).toBe('unsupported')
		expect(await passkeySupport({ target: 'chrome', userAgent: chrome(133), credential: credential({ prf: false }) })).toBe('unsupported')
		expect(await passkeySupport({ target: 'chrome', userAgent: chrome(133), credential: credential({ prf: true }) })).toBe('supported')
	})
})
