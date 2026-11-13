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

/** A card's or identity's members, as the web app's `parsePayload` reads them: only from a key that starts a JSON object. */
export const parsePayload = (key: string): Record<string, unknown> | null => (key.startsWith('{') ? parseObject(key) : null)

/** A field as display text; missing and non-string values read as empty. */
export function text(payload: Record<string, unknown>, field: string): string {
	const value = payload[field]
	return typeof value === 'string' ? value : typeof value === 'number' ? String(value) : ''
}
