/** A modal ceremony's deadline in ms: the page's `timeout`, clamped as Bitwarden does (spec § A ceremony ends cleanly). */
export function ceremonyTimeout(timeout: number | undefined, userVerification: string | undefined): number {
	const [min, max, fallback] = userVerification === 'discouraged' ? [30_000, 180_000, 120_000] : [30_000, 600_000, 300_000]
	return typeof timeout === 'number' && Number.isFinite(timeout) ? Math.min(Math.max(timeout, min), max) : fallback
}
