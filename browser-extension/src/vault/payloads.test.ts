import { describe, expect, it } from 'vitest'
import { typeIcon } from './icons'
import { parseObject, parsePayload, text } from './payloads'

describe('parseObject', () => {
	it('accepts only JSON objects', () => {
		expect(parseObject('{"a":"b"}')).toEqual({ a: 'b' })
		for (const raw of [undefined, '', '[1]', '"x"', '1', 'null', '{']) expect(parseObject(raw)).toBeNull()
	})
})

describe('parsePayload', () => {
	it('reads members only from a key that starts a JSON object', () => {
		expect(parsePayload('{"number":"4111"}')).toEqual({ number: '4111' })
		for (const key of ['', ' {"number":"4111"}', '[1]', '{broken', 'legacy text']) expect(parsePayload(key)).toBeNull()
	})
})

describe('text', () => {
	it('reads strings and numbers, anything else as empty', () => {
		expect(text({ a: 'x', b: 4, c: null, d: {} }, 'a')).toBe('x')
		expect(text({ b: 4 }, 'b')).toBe('4')
		expect(text({ c: null }, 'c')).toBe('')
		expect(text({}, 'missing')).toBe('')
	})
})

describe('typeIcon', () => {
	it('maps system types and falls back to a key', () => {
		expect(typeIcon('login')).toBe('globe')
		expect(typeIcon('ssh_key')).toBe('terminal')
		expect(typeIcon('licence')).toBe('key')
		expect(typeIcon(undefined)).toBe('key')
	})
})
