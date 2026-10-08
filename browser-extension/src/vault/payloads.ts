/** Readers for the JSON that composite types keep in `key` and every type in `additionalFields` (ADR-003). */

/** A JSON object, or `null` for anything else: arrays, primitives, broken JSON. */
export function parseObject(raw: string | undefined): Record<string, unknown> | null {
	if (!raw) return null
	try {
		const parsed: unknown = JSON.parse(raw)
		return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? parsed as Record<string, unknown> : null
	} catch {
		return null
	}
}

/** A field as display text; missing and non-string values read as empty. */
export function text(payload: Record<string, unknown>, field: string): string {
	const value = payload[field]
	return typeof value === 'string' ? value : typeof value === 'number' ? String(value) : ''
}

/** Same rules as the web app's `cardBrand`; `null` where it says "Card", so the popup words that. */
export function cardBrand(number: string): string | null {
	const digits = number.replace(/\D/g, '')
	if (/^4/.test(digits)) return 'Visa'
	if (/^(5[1-5]|2[2-7])/.test(digits)) return 'Mastercard'
	if (/^3[47]/.test(digits)) return 'American Express'
	if (/^(6011|65|64[4-9])/.test(digits)) return 'Discover'
	if (/^(50|56|57|58|63|67)/.test(digits)) return 'Maestro'
	return null
}

export function cardLast4(number: string): string {
	const digits = number.replace(/\D/g, '')
	return digits.length >= 4 ? digits.slice(-4) : ''
}
