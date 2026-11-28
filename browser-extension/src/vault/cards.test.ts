import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import CARD_BRANDS from './card-brands.json'
import VECTORS from './card-brand-vectors.json'
import { brandOptions, cardBrand, cardBrandInfo, cardLast4, cardNumberHint, formatCardNumber, maskCardNumber, securityCodeHint } from './cards'

const read = (path: string) => readFileSync(new URL(path, import.meta.url), 'utf8')
// The web app's originals (Keepiq `vault-card-brand-formats`).
const WEB_TABLE = new URL('../../../src/cardIdentity/card-brands.json', import.meta.url)
const WEB_VECTORS = new URL('../../../tests/vectors/cards/brand-vectors.json', import.meta.url)

describe('card brand table', () => {
	it('is a byte-identical copy of the web app\'s table', () => {
		expect(read('./card-brands.json')).toBe(readFileSync(WEB_TABLE, 'utf8'))
	})

	it('tests against a byte-identical copy of the web app\'s vectors', () => {
		expect(read('./card-brand-vectors.json')).toBe(readFileSync(WEB_VECTORS, 'utf8'))
	})

	it('names every network it falls back to', () => {
		const ids = new Set(CARD_BRANDS.brands.map((brand) => brand.id))
		expect(ids.size).toBe(CARD_BRANDS.brands.length)
		for (const brand of CARD_BRANDS.brands) expect(('networks' in brand ? brand.networks ?? [] : []).every((id) => ids.has(id))).toBe(true)
	})
})

describe('card brands', () => {
	it.each(VECTORS.vectors)('labels $number as $label', ({ number, label, brand }) => {
		expect(cardBrand(number) ?? 'Card').toBe(label)
		expect(cardBrandInfo(number)?.id ?? null).toBe(brand)
	})

	it('takes the last four digits', () => {
		expect(cardLast4('4111-1111-1111-1234')).toBe('1234')
		expect(cardLast4('12')).toBe('')
	})
})

describe('card number display', () => {
	it.each([
		['378282246310005', '3782 822463 10005', '•••• •••••• •0005'],
		['30569309025904', '3056 930902 5904', '•••• •••••• 5904'],
		['100000000000009', '1000 00000 000009', '•••• ••••• ••0009'],
		['4111-1111-1111-1111', '4111 1111 1111 1111', '•••• •••• •••• 1111'],
		['6200000000000000005', '6200 0000 0000 0000 005', '•••• •••• •••• •••0 005'],
	])('%s', (number, shown, masked) => {
		expect(formatCardNumber(number)).toBe(shown)
		expect(maskCardNumber(number)).toBe(masked)
	})

	it('keeps text without digits and dots a number under four digits', () => {
		expect(formatCardNumber('n/a')).toBe('n/a')
		expect(maskCardNumber('12')).toBe('•••• •••• •••• ••••')
	})
})

describe('card hints', () => {
	it('names a length the brand does not issue', () => {
		expect(cardNumberHint('37828224631000')).toEqual({ code: 'length', brand: 'American Express' })
		expect(cardNumberHint('99998888777')).toEqual({ code: 'lengthUnknown' })
		expect(cardNumberHint('378282246310005')).toBeNull()
		expect(cardNumberHint('')).toBeNull()
	})

	it('names a failed Luhn check, except for UnionPay', () => {
		expect(cardNumberHint('4111111111111112')).toEqual({ code: 'luhn' })
		expect(cardNumberHint('6200000000000001')).toBeNull()
	})

	it('names a security code of the wrong length, and nothing for a brand without one', () => {
		expect(securityCodeHint('378282246310005', '123')).toEqual({ brand: 'American Express', name: 'CID', size: 4 })
		expect(securityCodeHint('378282246310005', '1234')).toBeNull()
		expect(securityCodeHint('6703000000000000', '123')).toBeNull()
		expect(securityCodeHint('9999888877776666', '12')).toBeNull()
	})
})

describe('brand options', () => {
	it('names a brand by its codes, without repeats', () => {
		expect(brandOptions('mastercard')).toEqual(['Mastercard', 'master card', 'mc'])
		expect(brandOptions('american-express')).toEqual(['American Express', 'amex', 'ae', 'ax'])
	})

	it('falls back to the networks a co-branded card also runs on', () => {
		expect(brandOptions('visa-dankort')).toEqual(['Dankort', 'visa/dankort', 'visadankort', 'Visa', 'vi'])
		expect(brandOptions('unionpay-discover')).toEqual(['UnionPay', 'union pay', 'china unionpay', 'cup', 'Discover', 'di'])
	})

	it('names nothing for an unknown id', () => {
		expect(brandOptions('')).toEqual([])
	})
})
