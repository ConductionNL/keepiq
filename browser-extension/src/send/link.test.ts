import { describe, expect, it } from 'vitest'
import { fromBase64Url } from '@/src/crypto/base64'
import { vectors } from '@/src/testing/vectors'
import { buildShareLink } from './link'

const { withoutPassword: vector } = vectors('send')
const serverUrl = vector.publicBase.replace('/index.php/apps/keepiq/public', '')

describe('buildShareLink', () => {
	it('builds the web app\'s link, with the key in the fragment', () => {
		expect(buildShareLink(serverUrl, vector.token, fromBase64Url(vector.rawKeyBase64Url))).toBe(vector.link)
	})

	it('carries no key for a password send', () => {
		expect(buildShareLink(serverUrl, vector.token, null)).toBe(vector.linkWithoutKey)
	})

	it('encodes the token', () => {
		expect(buildShareLink('https://cloud.example.org', 'a/b#c', null)).toBe('https://cloud.example.org/index.php/apps/keepiq/public/send/a%2Fb%23c')
	})
})
