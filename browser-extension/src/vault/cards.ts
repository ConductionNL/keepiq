/**
 * Card brands from `card-brands.json`, a byte-identical copy of the web app's `src/cardIdentity/card-brands.json` (Keepiq `vault-card-brand-formats`, ext-card-formats).
 * The brand is derived from the decrypted number and never stored.
 */
import CARD_BRANDS from './card-brands.json'

export interface CardBrand {
	id: string
	label: string
	ranges: Array<string | string[]>
	lengths: number[]
	code: { name: string; size: number } | null
	gaps: Partial<Record<string, number[]>> & { default: number[] }
	luhn?: boolean
	selectCodes: string[]
	networks?: string[]
}

// JSON infers a union per entry that does not narrow to the interface; the table's shape is tested in cards.test.ts.
const BRANDS = CARD_BRANDS.brands as unknown as CardBrand[]
const BY_ID = new Map(BRANDS.map((brand) => [brand.id, brand]))

// Ranges are compared at this many digits to find the narrowest (Keepiq design D3).
const RANGE_WIDTH = 8

const RANGES = BRANDS.flatMap((brand) => brand.ranges.map((range) => {
	const [start, end] = Array.isArray(range) ? range as [string, string] : [range, range]
	return { brand, start, end, width: Number(end.padEnd(RANGE_WIDTH, '9')) - Number(start.padEnd(RANGE_WIDTH, '0')) + 1 }
}))

const digitsOf = (number: string) => number.replace(/\D/g, '')

/** The brand of the narrowest matching range; null when none matches or two brands tie. */
export function cardBrandInfo(number: string): CardBrand | null {
	const digits = digitsOf(number)
	let best: typeof RANGES[number] | null = null
	let tied = false
	for (const range of RANGES) {
		const head = digits.slice(0, range.start.length)
		// Equal-length digit strings compare as numbers do.
		if (head.length < range.start.length || head < range.start || head > range.end) continue
		if (!best || range.width < best.width) {
			best = range
			tied = false
		} else if (range.width === best.width && range.brand !== best.brand) {
			tied = true
		}
	}
	return best && !tied ? best.brand : null
}

/** The brand's name; `null` where the popup says "Card". */
export const cardBrand = (number: string): string | null => cardBrandInfo(number)?.label ?? null

export function cardLast4(number: string): string {
	const digits = digitsOf(number)
	return digits.length >= 4 ? digits.slice(-4) : ''
}

/** The digits split at the brand's gaps for their length, every four for an unknown brand. */
function cardGroups(digits: string): string[] {
	const gaps = cardBrandInfo(digits)?.gaps
	const at = gaps ? gaps[digits.length] ?? gaps.default : [4, 8, 12, 16]
	const groups: string[] = []
	let from = 0
	for (const gap of at.filter((each) => each < digits.length)) {
		groups.push(digits.slice(from, gap))
		from = gap
	}
	groups.push(digits.slice(from))
	return groups
}

/** Grouped as the brand prints it (Amex 4-6-5); text without digits as stored. */
export function formatCardNumber(number: string): string {
	const digits = digitsOf(number)
	return digits ? cardGroups(digits).join(' ') : number
}

/** Dots in the brand's grouping with the last four digits shown, as the popup has always masked a card. */
export function maskCardNumber(number: string): string {
	const digits = digitsOf(number)
	if (digits.length < 4) return '•••• •••• •••• ••••'
	let index = 0
	return cardGroups(digits).map((group) => [...group].map((digit) => (index++ >= digits.length - 4 ? digit : '•')).join('')).join(' ')
}

export function luhnValid(number: string): boolean {
	const digits = digitsOf(number)
	let sum = 0
	for (let i = digits.length - 1, double = false; i >= 0; i--, double = !double) {
		let digit = digits.charCodeAt(i) - 48
		if (double && (digit *= 2) > 9) digit -= 9
		sum += digit
	}
	return digits.length >= 12 && sum % 10 === 0
}

export type CardNumberHint = { code: 'length'; brand: string } | { code: 'lengthUnknown' } | { code: 'luhn' }

/** A length the brand does not issue (outside 12 to 19 without a brand), else a failed Luhn check, which UnionPay numbers need not pass. Never blocks. */
export function cardNumberHint(number: string): CardNumberHint | null {
	const digits = digitsOf(number)
	if (!digits) return null
	const brand = cardBrandInfo(digits)
	if (brand ? !brand.lengths.includes(digits.length) : digits.length < 12 || digits.length > 19) {
		return brand ? { code: 'length', brand: brand.label } : { code: 'lengthUnknown' }
	}
	return brand?.luhn !== false && !luhnValid(digits) ? { code: 'luhn' } : null
}

/** A security code whose length is not the brand's; nothing for an unknown brand or one without a code. Never blocks. */
export function securityCodeHint(number: string, code: string): { brand: string; name: string; size: number } | null {
	const typed = code.trim()
	const brand = cardBrandInfo(number)
	if (!typed || !brand?.code || typed.length === brand.code.size) return null
	return { brand: brand.label, name: brand.code.name, size: brand.code.size }
}

/** What a checkout's card type `<select>` may call the brand: its name and codes, then those of the networks a co-branded card also runs on. */
export function brandOptions(id: string): string[] {
	const brand = BY_ID.get(id)
	if (!brand) return []
	const names = [brand, ...(brand.networks ?? []).map((network) => BY_ID.get(network)).filter((each) => each !== undefined)]
		.flatMap((each) => [each.label, ...each.selectCodes])
	const seen = new Set<string>()
	return names.filter((name) => !seen.has(name.toLowerCase()) && seen.add(name.toLowerCase()))
}
