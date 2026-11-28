import { GUARD_MS } from './visibility'

export type VisibilityTracker = { allows: (at: number) => boolean; stop: () => void }

/**
 * For the list and bar pages. Chrome's IntersectionObserver v2 sees overlays and every ancestor frame: a press needs
 * the page reported visible for the `GUARD_MS` before it. Firefox has no such report and relies on the host's checks.
 */
export function trackVisibility(target: Element): VisibilityTracker {
	if (typeof IntersectionObserverEntry === 'undefined' || !('isVisible' in IntersectionObserverEntry.prototype)) {
		return { allows: () => true, stop: () => {} }
	}
	let visible = false
	let lastNotVisibleAt = Date.now()
	const observer = new IntersectionObserver((entries) => {
		for (const entry of entries) {
			const now = entry.isIntersecting && (entry as IntersectionObserverEntry & { isVisible?: boolean }).isVisible === true
			// Becoming visible ends a hidden stretch, so it counts as its last moment.
			if (!now || !visible) lastNotVisibleAt = Date.now()
			visible = now
		}
	}, { trackVisibility: true, delay: 100 } as IntersectionObserverInit)
	observer.observe(target)
	return { allows: (at) => visible && at - lastNotVisibleAt >= GUARD_MS, stop: () => observer.disconnect() }
}
