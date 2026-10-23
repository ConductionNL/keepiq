import { describe, expect, it } from 'vitest'
import { serializeCredential } from './payload'

describe('serializeCredential', () => {
	it('writes two English-labelled lines', () => {
		expect(serializeCredential('alice', 's3cret')).toBe('Username: alice\nPassword: s3cret')
	})

	it('keeps the values as they are, without a length cap', () => {
		const long = 'x'.repeat(5000)
		expect(serializeCredential('', long)).toBe(`Username: \nPassword: ${long}`)
		expect(serializeCredential('één', 'p:w\nd')).toBe('Username: één\nPassword: p:w\nd')
	})
})
