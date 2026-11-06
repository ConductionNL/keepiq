// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest'
import { fillSelect } from './fields'
import { expiryPattern, formatExpiry, FORMATTERS, monthOptions, numberParts, splitNumber, yearOptions } from './format'

const input = (html: string) => {
	document.body.innerHTML = html
	return document.querySelector('input, select, textarea') as HTMLInputElement
}
const values = { expMonth: '03', expYear: '2027' }

beforeEach(() => {
	document.body.innerHTML = ''
})

describe('combined expiry', () => {
	it.each([
		['<input placeholder="MM/YY">', '03/27'],
		['<input placeholder="MM / YY">', '03 / 27'],
		['<input placeholder="MM / YYYY">', '03 / 2027'],
		['<input placeholder="MM-YYYY">', '03-2027'],
		['<input placeholder="MMJJ">', '0327'],
		['<label>Vervaldatum (MM/JJJJ) <input></label>', '03/2027'],
		['<input placeholder="M/YY">', '3/27'],
		['<input maxlength="4">', '0327'],
		['<input maxlength="5">', '03/27'],
		['<input maxlength="6">', '032027'],
		['<input maxlength="7">', '03/2027'],
		['<input>', '03/27'],
		['<input placeholder="MM / YY" maxlength="5">', '03/27'],
		['<input placeholder="MM / YYYY" maxlength="5">', '03/27'],
		['<input placeholder="MM / YYYY" maxlength="7">', '03/2027'],
		['<input placeholder="MM / YYYY" maxlength="8">', '03/2027'],
	])('formats %s as %s', (html, expected) => {
		expect(FORMATTERS.expiry(input(html), values)).toEqual([expected])
	})

	it('fills nothing longer than the field holds', () => {
		expect(FORMATTERS.expiry(input('<input placeholder="MM/YY" maxlength="3">'), values)).toEqual([])
	})

	it('prefers the written pattern over maxlength', () => {
		expect(expiryPattern(input('<input placeholder="MM / YYYY" maxlength="9">'))).toEqual({ month: 2, separator: ' / ', year: 4 })
	})

	it('fills nothing without a month and year', () => {
		expect(FORMATTERS.expiry(input('<input>'), { expMonth: '03' })).toEqual([])
		expect(formatExpiry({ month: '11', year: '2030' }, { month: 1, separator: '.', year: 2 })).toBe('11.30')
	})
})

describe('month and year', () => {
	it.each([
		['<input>', '03'],
		['<input maxlength="1">', '3'],
		['<input placeholder="M">', '3'],
	])('fills a month input %s with %s', (html, expected) => {
		expect(FORMATTERS.expMonth(input(html), values)).toEqual([expected])
	})

	it.each([
		['<input>', '2027'],
		['<input maxlength="2">', '27'],
		['<input placeholder="YY">', '27'],
		['<input placeholder="JJ">', '27'],
	])('fills a year input %s with %s', (html, expected) => {
		expect(FORMATTERS.expYear(input(html), values)).toEqual([expected])
	})

	it('offers a month select its two- and one-digit forms and its English and Dutch names', () => {
		expect(monthOptions('03')).toEqual(['03', '3', 'march', 'mar', 'maart', 'mrt'])
		expect(monthOptions('10')).toEqual(['10', '10', 'october', 'oct', 'oktober', 'okt'])
		expect(yearOptions('2027')).toEqual(['2027', '27'])
	})

	it.each([
		['<option value="02">02</option><option value="03">03</option>', '03'],
		['<option value="2">2</option><option value="3">3</option>', '3'],
		['<option value="a">Februari</option><option value="b">Maart</option>', 'b'],
		['<option value="x">Feb</option><option value="y">Mar</option>', 'y'],
	])('selects March among %s', (options, expected) => {
		const select = input(`<select>${options}</select>`) as unknown as HTMLSelectElement
		expect(fillSelect(select, FORMATTERS.expMonth(select, values))).toBe(true)
		expect(select.value).toBe(expected)
	})

	it('leaves a year select without the year as it was', () => {
		const select = input('<select><option>2030</option><option>2031</option></select>') as unknown as HTMLSelectElement
		expect(fillSelect(select, FORMATTERS.expYear(select, values))).toBe(false)
		expect(select.value).toBe('2030')
	})

	it('fills nothing for a month or year the background did not send in its form', () => {
		expect(FORMATTERS.expMonth(input('<input>'), { expMonth: '13' })).toEqual([])
		expect(FORMATTERS.expYear(input('<input>'), { expYear: '27' })).toEqual([])
	})
})

describe('number', () => {
	it('splits a 15-digit Amex over 4, 6 and 5', () => {
		expect(splitNumber('378282246310005', [4, 6, 5])).toEqual(['3782', '822463', '10005'])
	})

	it('splits nothing that does not fit exactly', () => {
		expect(splitNumber('4111111111111111', [4, 4, 4, 3])).toBeNull()
		expect(splitNumber('4111111111111111', [4, 4, 4, 7])).toBeNull()
		expect(splitNumber('4111', [4, -1])).toBeNull()
	})

	it('fills one field only when the digits fit it', () => {
		document.body.innerHTML = '<input id="a"><input id="b" maxlength="12">'
		const [a, b] = [...document.querySelectorAll('input')]
		expect(numberParts([a!], '4111 1111 1111 1111')).toEqual(['4111111111111111'])
		expect(numberParts([b!], '4111 1111 1111 1111')).toBeNull()
		expect(numberParts([a!], '')).toBeNull()
	})

	it('fills a number and its confirmation field, too long to be parts, into the first only', () => {
		document.body.innerHTML = '<input id="a" maxlength="19"><input id="b" maxlength="19"><input id="c">'
		const [a, b, c] = [...document.querySelectorAll('input')]
		expect(numberParts([a!, b!], '4111 1111 1111 1111')).toEqual(['4111111111111111'])
		expect(numberParts([c!, a!], '4111 1111 1111 1111')).toEqual(['4111111111111111'])
	})
})

describe('other members', () => {
	it('selects the brand option by its id and types the brand name into an input', () => {
		const select = input('<select><option>VISA</option><option>MC</option><option>AMEX</option></select>') as unknown as HTMLSelectElement
		expect(fillSelect(select, FORMATTERS.brand(select, { brand: 'Mastercard', brandId: 'mastercard' }))).toBe(true)
		expect(select.value).toBe('MC')
		expect(FORMATTERS.brand(input('<input>'), { brand: 'Visa', brandId: 'visa' })).toEqual(['Visa'])
	})

	it('selects a co-branded card\'s other network when the page offers only that one', () => {
		const select = input('<select><option>Visa</option><option>Mastercard</option></select>') as unknown as HTMLSelectElement
		expect(fillSelect(select, FORMATTERS.brand(select, { brand: 'Dankort', brandId: 'visa-dankort' }))).toBe(true)
		expect(select.value).toBe('Visa')
	})

	it('joins an address on one line in an input and keeps its lines in a textarea', () => {
		const address = { streetAddress: 'Kerkstraat 1\n1234 AB Utrecht' }
		expect(FORMATTERS.streetAddress(input('<input>'), address)).toEqual(['Kerkstraat 1, 1234 AB Utrecht'])
		expect(FORMATTERS.streetAddress(input('<textarea></textarea>'), address)).toEqual(['Kerkstraat 1\n1234 AB Utrecht'])
	})

	it('fills nothing for an empty or missing member', () => {
		expect(FORMATTERS.cardholder(input('<input>'), { cardholder: '' })).toEqual([])
		expect(FORMATTERS.securityCode(input('<input>'), {})).toEqual([])
	})
})
