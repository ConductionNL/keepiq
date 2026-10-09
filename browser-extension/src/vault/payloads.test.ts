import { describe, expect, it } from 'vitest'
import { typeIcon } from './icons'
import { cardBrand, cardLast4, parseObject, text } from './payloads'

describe('parseObject', () => {
	it('accepts only JSON objects', () => {
		expect(parseObject('{"a":"b"}')).toEqual({ a: 'b' })
		for (const raw of [undefined, '', '[1]', '"x"', '1', 'null', '{']) expect(parseObject(raw)).toBeNull()
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

describe('cards', () => {
	it.each([
		['4111 1111 1111 1111', 'Visa'], ['5500000000000004', 'Mastercard'], ['2221000000000009', 'Mastercard'],
		['340000000000009', 'American Express'], ['6011000000000004', 'Discover'], ['6759000000000000', 'Maestro'], ['', null],
	])('%s is %s', (number, brand) => {
		expect(cardBrand(number)).toBe(brand)
	})

	it('takes the last four digits', () => {
		expect(cardLast4('4111-1111-1111-1234')).toBe('1234')
		expect(cardLast4('12')).toBe('')
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
