// Content script: a card's or identity's members as each field wants them (spec `card-identity-fill`, Expiry formatting, Split numbers and brand fields).
import type { FormRole, FormValues } from '@/src/messages'
import { labelTexts, type FormControl } from './fields'
import type { Expiry } from './wallet'

export interface ExpiryPattern {
	month: 1 | 2
	separator: string
	year: 2 | 4
}

const WRITTEN = /\b(MM?)(\s*[/.\- ]?\s*)(YYYY|YY|JJJJ|JJ)\b/i

const BY_LENGTH: Partial<Record<number, ExpiryPattern>> = {
	4: { month: 2, separator: '', year: 2 },
	5: { month: 2, separator: '/', year: 2 },
	6: { month: 2, separator: '', year: 4 },
	7: { month: 2, separator: '/', year: 4 },
}

const DEFAULT_PATTERN: ExpiryPattern = { month: 2, separator: '/', year: 2 }

/** As the placeholder or a label writes it, else by `maxlength`, else `MM/YY`. */
export function expiryPattern(control: HTMLInputElement): ExpiryPattern {
	for (const text of labelTexts(control)) {
		const match = WRITTEN.exec(text)
		if (match) return { month: match[1]!.length === 1 ? 1 : 2, separator: match[2]!, year: match[3]!.length === 4 ? 4 : 2 }
	}
	return BY_LENGTH[control.maxLength] ?? DEFAULT_PATTERN
}

const MONTH = /^(0[1-9]|1[0-2])$/
const YEAR = /^\d{4}$/

const monthIn = (month: string, digits: 1 | 2) => (digits === 1 ? String(Number(month)) : month)
const yearIn = (year: string, digits: 2 | 4) => (digits === 2 ? year.slice(2) : year)

/** `month` "01" to "12" and a four-digit `year`, as the background sends them. */
export const formatExpiry = ({ month, year }: Expiry, pattern: ExpiryPattern) =>
	`${monthIn(month, pattern.month)}${pattern.separator}${yearIn(year, pattern.year)}`

/** The written pattern, then without its spaces, then by `maxlength`: the first that fits the field, or none. */
function fittingExpiry(input: HTMLInputElement, expiry: Expiry): string[] {
	const written = expiryPattern(input)
	const patterns = [written, { ...written, separator: written.separator.replace(/\s+/g, '') }, BY_LENGTH[input.maxLength] ?? DEFAULT_PATTERN]
	const value = patterns.map((pattern) => formatExpiry(expiry, pattern)).find((each) => input.maxLength < 0 || each.length <= input.maxLength)
	return value ? [value] : []
}

const EN_MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']
const NL_MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december']
const NL_SHORT = ['jan', 'feb', 'mrt', 'apr', 'mei', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dec']

export function monthOptions(month: string): string[] {
	const at = Number(month) - 1
	return [month, String(at + 1), EN_MONTHS[at]!, EN_MONTHS[at]!.slice(0, 3), NL_MONTHS[at]!, NL_SHORT[at]!]
}

export const yearOptions = (year: string) => [year, year.slice(2)]

const placeholderIs = (control: FormControl, pattern: RegExp) => pattern.test((control.getAttribute('placeholder') ?? '').trim())
const isSelect = (control: FormControl) => control.localName === 'select'

function monthFor(control: FormControl, month: unknown): string[] {
	if (typeof month !== 'string' || !MONTH.test(month)) return []
	if (isSelect(control)) return monthOptions(month)
	const one = (control as HTMLInputElement).maxLength === 1 || placeholderIs(control, /^M$/i)
	return [monthIn(month, one ? 1 : 2)]
}

function yearFor(control: FormControl, year: unknown): string[] {
	if (typeof year !== 'string' || !YEAR.test(year)) return []
	if (isSelect(control)) return yearOptions(year)
	const two = (control as HTMLInputElement).maxLength === 2 || placeholderIs(control, /^(YY|JJ)$/i)
	return [yearIn(year, two ? 2 : 4)]
}

function expiryFor(control: FormControl, { expMonth, expYear }: FormValues): string[] {
	if (!expMonth || !expYear || !MONTH.test(expMonth) || !YEAR.test(expYear) || isSelect(control)) return []
	return fittingExpiry(control as HTMLInputElement, { month: expMonth, year: expYear })
}

const BRAND_CODES: Partial<Record<string, string[]>> = {
	'Visa': ['vi'],
	'Mastercard': ['master card', 'mc'],
	'American Express': ['amex', 'ax'],
	'Discover': ['di'],
}

export const brandOptions = (brand: string) => [brand, ...(BRAND_CODES[brand] ?? [])]

const one = (value: unknown): string[] => (typeof value === 'string' && value !== '' ? [value] : [])

function addressFor(control: FormControl, address: unknown): string[] {
	if (typeof address !== 'string') return []
	return one(control.localName === 'textarea' ? address : address.split('\n').join(', '))
}

type Formatter = (control: FormControl, values: FormValues) => string[]

/** What a control takes for each role: its one value, or a `<select>`'s options to look for; empty to leave it as it is. */
export const FORMATTERS: Record<Exclude<FormRole, 'number'>, Formatter> = {
	cardholder: (_, values) => one(values.cardholder),
	expiry: expiryFor,
	expMonth: (control, values) => monthFor(control, values.expMonth),
	expYear: (control, values) => yearFor(control, values.expYear),
	securityCode: (_, values) => one(values.cvv),
	brand: (control, values) => (isSelect(control) && typeof values.brand === 'string' ? brandOptions(values.brand) : one(values.brand)),
	fullName: (_, values) => one(values.fullName),
	firstName: (_, values) => one(values.firstName),
	lastName: (_, values) => one(values.lastName),
	email: (_, values) => one(values.email),
	phone: (_, values) => one(values.phone),
	streetAddress: (control, values) => addressFor(control, values.streetAddress),
	addressLine1: (_, values) => one(values.addressLine1),
}

/** The digits split over the fields by their `maxlength`, or null when they do not fit exactly. */
export function splitNumber(digits: string, lengths: readonly number[]): string[] | null {
	if (lengths.some((length) => length < 1) || lengths.reduce((sum, length) => sum + length, 0) !== digits.length) return null
	let at = 0
	return lengths.map((length) => digits.slice(at, (at += length)))
}

const isPart = (field: HTMLInputElement) => field.maxLength >= 1 && field.maxLength <= 8

/** Each number field's part, in order; null when the number does not fit them. Fields too long to be parts, such as a number and its confirmation, take it whole into the first. */
export function numberParts(fields: readonly HTMLInputElement[], number: unknown): string[] | null {
	const digits = typeof number === 'string' ? number.replace(/\D/g, '') : ''
	const [first] = fields
	if (!digits || !first) return null
	if (fields.length > 1 && fields.every(isPart)) return splitNumber(digits, fields.map((field) => field.maxLength))
	return first.maxLength < 0 || first.maxLength >= digits.length ? [digits] : null
}
