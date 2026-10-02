/**
 * Watch a page for a one-time-code field that shows up after the login step
 * (extension-totp-autofill, next step): on load and, throttled, whenever the
 * DOM changes. Each field is reported once; the worker decides whether a
 * pending intent allows a fill.
 *
 * @spec openspec/specs/extension-totp-autofill/spec.md#requirement-one-time-code-fill-on-the-step-after-the-login
 */

/**
 * Start watching.
 *
 * @param {object} deps The dependencies.
 * @param {Document} deps.doc The document to watch.
 * @param {function(): (Element|null)} deps.find Returns the visible code field, if any.
 * @param {function(): void} deps.report Tells the worker a new field is there.
 * @param {number} [deps.throttleMs] The shortest time between two checks.
 * @return {function(): void} Stops watching.
 */
export function watchForOtpField({ doc, find, report, throttleMs = 250 }) {
	const reported = new WeakSet()
	let timer = null

	function check() {
		timer = null
		// A throttled check can fire after the page is gone (navigation, or a
		// test environment torn down); a detached document has nothing to watch.
		if (!doc.defaultView || typeof document === 'undefined') {
			return
		}
		const field = find()
		if (field && !reported.has(field)) {
			reported.add(field)
			report()
		}
	}

	const view = doc.defaultView || globalThis
	const observer = new view.MutationObserver(() => {
		if (timer === null) timer = view.setTimeout(check, throttleMs)
	})
	observer.observe(doc.documentElement || doc, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: ['style', 'class', 'hidden'],
	})
	check()

	return () => {
		observer.disconnect()
		if (timer !== null) view.clearTimeout(timer)
	}
}
