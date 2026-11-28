import { describe, expect, it } from 'vitest'
import { isAuthenticatorKey, normaliseAuthenticatorKey, otpauthFromQrText } from './authenticator-key'

const LINK = 'otpauth://totp/Keepiq%20test%20site:test-user?secret=JBSWY3DPEHPK3PXP&issuer=Keepiq%20test%20site'

describe('normaliseAuthenticatorKey', () => {
	it('joins a key copied in groups and upper-cases it', () => {
		expect(normaliseAuthenticatorKey(' jbsw y3dp\tehpk 3pxp ')).toBe('JBSWY3DPEHPK3PXP')
	})

	it('keeps a link as typed, only trimmed', () => {
		expect(normaliseAuthenticatorKey(`  ${LINK}\n`)).toBe(LINK)
		expect(normaliseAuthenticatorKey('OTPAUTH://totp/a?secret=jbswy3dpehpk3pxp')).toBe('OTPAUTH://totp/a?secret=jbswy3dpehpk3pxp')
	})

	it('gives nothing for nothing', () => {
		expect(normaliseAuthenticatorKey('   ')).toBe('')
	})
})

describe('isAuthenticatorKey', () => {
	it.each([
		['a bare secret', 'JBSWY3DPEHPK3PXP'],
		['a secret in lower case and groups', 'jbsw y3dp ehpk 3pxp'],
		['a totp link', LINK],
		['a link with parameters', 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&algorithm=SHA256&digits=8&period=60'],
	])('takes %s', (_, key) => {
		expect(isAuthenticatorKey(key)).toBe(true)
	})

	it.each([
		['an empty key', ''],
		['text that is not base32', 'not a key!'],
		['an hotp link', 'otpauth://hotp/x?secret=JBSWY3DPEHPK3PXP&counter=1'],
		['a steam link', 'steam://JBSWY3DPEHPK3PXP'],
		['a link without a secret', 'otpauth://totp/x?issuer=Example'],
		['an unknown algorithm', 'otpauth://totp/x?secret=JBSWY3DPEHPK3PXP&algorithm=MD5'],
	])('refuses %s', (_, key) => {
		expect(isAuthenticatorKey(key)).toBe(false)
	})
})

describe('otpauthFromQrText', () => {
	it('reads a totp link', () => {
		expect(otpauthFromQrText(`${LINK}\n`)).toBe(LINK)
	})

	it.each([
		['a website', 'https://example.com/'],
		['a bare secret', 'JBSWY3DPEHPK3PXP'],
		['an hotp link', 'otpauth://hotp/x?secret=JBSWY3DPEHPK3PXP&counter=1'],
		['a totp link that does not read', 'otpauth://totp/x?secret=not-base32!'],
	])('refuses %s', (_, text) => {
		expect(otpauthFromQrText(text)).toBeNull()
	})
})
