/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the card/identity payload model (card-identity-items
 * §7.2/§7.3/§7.5): payload round-trips, in-browser brand + last-4
 * derivation (never stored), Luhn/expiry hinting, and the import
 * mapper's Bitwarden card/identity routing.
 *
 * @spec openspec/specs/card-identity-items/spec.md#requirement-composite-payload-stored-as-ciphertext-in-the-key-field
 */

import { describe, expect, it } from 'vitest'
import cardBrands from '../../src/cardIdentity/card-brands.json'
import {
	cardBrand,
	cardBrandInfo,
	cardLast4,
	cardNumberHint,
	expiryFormatValid,
	formatCardNumber,
	luhnValid,
	maskCardNumber,
	parsePayload,
	securityCodeHint,
	serializeCard,
	serializeIdentity,
} from '../../src/cardIdentity/cardIdentity.js'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'
import brandVectors from '../vectors/cards/brand-vectors.json'

describe('card brand vectors', () => {
	it.each(brandVectors.vectors)('labels $number as $label', ({ number, label, brand }) => {
		expect(cardBrand(number)).toBe(label)
		expect(cardBrandInfo(number)?.id ?? null).toBe(brand)
	})
})

describe('card brand table', () => {
	const ids = new Set(cardBrands.brands.map((brand) => brand.id))

	it.each(cardBrands.brands)('$id is well formed', (brand) => {
		for (const range of brand.ranges) {
			const [start, end] = Array.isArray(range) ? range : [range, range]
			expect(start).toMatch(/^\d{1,8}$/)
			expect(end.length).toBe(start.length)
			expect(start <= end).toBe(true)
		}
		expect(brand.lengths.every((length) => length >= 12 && length <= 19)).toBe(true)
		for (const gaps of Object.values(brand.gaps)) {
			expect(gaps.every((gap, at) => gap > (gaps[at - 1] ?? 0))).toBe(true)
		}
		expect(brand.code === null || brand.code.size === 3 || brand.code.size === 4).toBe(true)
		expect((brand.networks ?? []).every((id) => ids.has(id))).toBe(true)
	})

	it('has unique ids and no two brands tie for the narrowest range on a vector', () => {
		expect(ids.size).toBe(cardBrands.brands.length)
		const ranges = cardBrands.brands.flatMap((brand) => brand.ranges.map((range) => {
			const [start, end] = Array.isArray(range) ? range : [range, range]
			return { id: brand.id, start, end, width: Number(end.padEnd(8, '9')) - Number(start.padEnd(8, '0')) }
		}))
		for (const { number } of brandVectors.vectors) {
			const digits = number.replace(/\D/g, '')
			const hits = ranges.filter(({ start, end }) => digits.length >= start.length && digits.slice(0, start.length) >= start && digits.slice(0, start.length) <= end)
			const narrowest = Math.min(...hits.map((hit) => hit.width))
			expect(new Set(hits.filter((hit) => hit.width === narrowest).map((hit) => hit.id)).size, number).toBeLessThanOrEqual(1)
		}
	})
})

describe('card number grouping and masking', () => {
	it.each([
		['378282246310005', '3782 822463 10005', '3782 •••••• •0005'],
		['30569309025904', '3056 930902 5904', '3056 •••••• 5904'],
		['100000000000009', '1000 00000 000009', '1000 ••••• ••0009'],
		['4111-1111-1111-1111', '4111 1111 1111 1111', '4111 •••• •••• 1111'],
		['6200000000000000005', '6200 0000 0000 0000 005', '6200 •••• •••• •••0 005'],
		['9999888877776666', '9999 8888 7777 6666', '9999 •••• •••• 6666'],
	])('%s', (number, formatted, masked) => {
		expect(formatCardNumber(number)).toBe(formatted)
		expect(maskCardNumber(number)).toBe(masked)
	})

	it('fully dots a number under 12 digits and keeps text without digits', () => {
		expect(maskCardNumber('12345')).toBe('•••• •••• •••• ••••')
		expect(formatCardNumber('n/a')).toBe('n/a')
	})
})

describe('card format hints', () => {
	it('hints at a length the brand does not issue', () => {
		expect(cardNumberHint('37828224631000')).toEqual({ code: 'length', brand: 'American Express' })
		expect(cardNumberHint('99998888777')).toEqual({ code: 'lengthUnknown' })
		expect(cardNumberHint('378282246310005')).toBeNull()
		expect(cardNumberHint('')).toBeNull()
	})

	it('hints at a failed Luhn check, except for UnionPay', () => {
		expect(cardNumberHint('4111111111111112')).toEqual({ code: 'luhn' })
		expect(cardNumberHint('6200000000000001')).toBeNull()
	})

	it('hints at a security code of the wrong length', () => {
		expect(securityCodeHint('378282246310005', '123')).toEqual({ brand: 'American Express', name: 'CID', size: 4 })
		expect(securityCodeHint('378282246310005', '1234')).toBeNull()
		expect(securityCodeHint('4111111111111111', '1234')).toEqual({ brand: 'Visa', name: 'CVV', size: 3 })
		expect(securityCodeHint('6703000000000000', '123')).toBeNull()
		expect(securityCodeHint('9999888877776666', '12')).toBeNull()
	})
})

describe('card payload', () => {
	it('round-trips a card payload with number, CVV, and PIN intact', () => {
		const json = serializeCard({
			number: '4111 1111 1111 1111',
			expiry: '12/28',
			cvv: '123',
			pin: '4321',
			cardholder: 'R. van der Linde',
		})
		const payload = parsePayload(json)
		expect(payload.number).toBe('4111 1111 1111 1111')
		expect(payload.cvv).toBe('123')
		expect(payload.pin).toBe('4321')
		expect(payload.expiry).toBe('12/28')
		expect(payload.cardholder).toBe('R. van der Linde')
		// Derived facts are NOT part of the stored payload.
		expect(json).not.toContain('brand')
		expect(json).not.toContain('last4')
	})

	it('derives brand + last-4 from the number, never stores them', () => {
		expect(cardBrand('4111 1111 1111 1111')).toBe('Visa')
		expect(cardBrand('5555 5555 5555 4444')).toBe('Mastercard')
		expect(cardBrand('3782 822463 10005')).toBe('American Express')
		expect(cardLast4('4111 1111 1111 1111')).toBe('1111')
	})

	it('yields "Card" for an unknown prefix — never a fabricated brand', () => {
		expect(cardBrand('9999 8888 7777 6666')).toBe('Card')
		expect(cardBrand('')).toBe('Card')
	})

	it('Luhn + expiry hints are best-effort and format-only', () => {
		expect(luhnValid('4111111111111111')).toBe(true)
		expect(luhnValid('4111111111111112')).toBe(false)
		expect(expiryFormatValid('12/28')).toBe(true)
		expect(expiryFormatValid('13/28')).toBe(false)
	})
})

describe('identity payload', () => {
	it('round-trips an identity payload with the BSN intact', () => {
		const json = serializeIdentity({
			firstName: 'Test',
			lastName: 'Persoon',
			address: 'Stationsplein 1, Amsterdam',
			phone: '+31 6 12345678',
			email: 'test@example.nl',
			bsn: '999990019',
		})
		const payload = parsePayload(json)
		expect(payload.bsn).toBe('999990019')
		expect(payload.firstName).toBe('Test')
	})

	it('tolerates a legacy plain-string value by returning null', () => {
		expect(parsePayload('just-a-password')).toBeNull()
		expect(parsePayload('')).toBeNull()
	})
})

describe('import mapper: Bitwarden card/identity routing', () => {
	it('routes a Bitwarden card item into a card row with the payload in password', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{
						type: 3,
						name: 'My Visa',
						card: {
							cardholderName: 'R. Tester',
							brand: 'Visa',
							number: '4111111111111111',
							expMonth: '3',
							expYear: '2028',
							code: '123',
						},
					},
				],
			}),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('card')
		expect(rows[0].errors).toEqual([])
		const payload = parsePayload(rows[0].password)
		expect(payload.number).toBe('4111111111111111')
		expect(payload.expiry).toBe('03/28')
		expect(payload.cvv).toBe('123')
		// The Bitwarden brand field is deliberately dropped (derived on render).
		expect(rows[0].password).not.toContain('Visa')
	})

	it('routes a Bitwarden identity item into an identity row with ssn -> bsn', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{
						type: 4,
						name: 'My identity',
						identity: {
							firstName: 'Test',
							lastName: 'Persoon',
							ssn: '999990019',
							email: 't@example.nl',
							address1: 'Stationsplein 1',
							city: 'Amsterdam',
						},
					},
				],
			}),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('identity')
		expect(rows[0].errors).toEqual([])
		const payload = parsePayload(rows[0].password)
		expect(payload.bsn).toBe('999990019')
		expect(payload.address).toContain('Amsterdam')
	})

	it('rejects a card item without a number and an empty identity item', () => {
		const rows = parseBitwardenJson(
			JSON.stringify({
				items: [
					{ type: 3, name: 'Empty card', card: {} },
					{ type: 4, name: 'Empty identity', identity: {} },
				],
			}),
		)
		expect(rows).toHaveLength(2)
		expect(rows[0].errors.length).toBeGreaterThan(0)
		expect(rows[1].errors.length).toBeGreaterThan(0)
	})
})

describe('card identity edge cases', () => {
	it('finds no brand for no digits, too few digits or an unknown prefix', () => {
		expect(cardBrandInfo('')).toBeNull()
		expect(cardBrandInfo(null)).toBeNull()
		expect(cardBrandInfo('0000000000000000')).toBeNull()
		expect(cardBrandInfo('4')?.id).toBe('visa')
	})

	it('hints the length of an unknown number outside 12 to 19 digits only', () => {
		expect(cardNumberHint('00000000000000000000')).toEqual({ code: 'lengthUnknown' })
		expect(cardNumberHint('000000000000')).toBeNull()
		expect(cardNumberHint('000000000001')).toEqual({ code: 'luhn' })
	})

	it('parses only a JSON object', () => {
		expect(parsePayload(5)).toBeNull()
		expect(parsePayload('{bad')).toBeNull()
		expect(parsePayload('{"a":1}')).toEqual({ a: 1 })
		expect(parsePayload('[1]')).toBeNull()
	})
})
