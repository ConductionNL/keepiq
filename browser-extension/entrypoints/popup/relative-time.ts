const UNITS: Array<[Intl.RelativeTimeFormatUnit, number]> = [['day', 86_400_000], ['hour', 3_600_000], ['minute', 60_000]]

/** "2 hours ago", "just now". */
export function relativeTime(iso: string, now = Date.now()): string {
	const elapsed = now - Date.parse(iso)
	const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
	for (const [unit, ms] of UNITS) {
		if (elapsed >= ms) return format.format(-Math.floor(elapsed / ms), unit)
	}
	return 'just now'
}
