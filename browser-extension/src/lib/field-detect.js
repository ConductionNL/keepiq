/**
 * Finding login fields in a page (clients-extension-gaps): the username and
 * password inputs, also inside open shadow roots, never a hidden field or
 * one under `aria-hidden`, with the username recognised by its type and
 * name or, failing those, by its label, placeholder or accessible name.
 *
 * Pure apart from reading the document it is given.
 *
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-find-fields-in-shadow-roots-and-by-their-label
 */

export const USERNAME_SELECTORS = [
	'input[autocomplete="username"]',
	'input[autocomplete="email"]',
	'input[type="email"]',
	'input[name*="user" i]',
	'input[name*="email" i]',
	'input[name*="login" i]',
	'input[id*="user" i]',
	'input[id*="email" i]',
	'input[id*="login" i]',
]

export const PASSWORD_SELECTORS = [
	'input[type="password"]',
	'input[autocomplete="current-password"]',
]

// Words that mark a username field in a label, placeholder or name. A
// leading boundary only, so a compound such as "E-mailadres" counts too.
const USERNAME_WORDS = /\b(user ?name|user|e-?mail|login|account|gebruiker)/i

/**
 * Every element matching a selector in a root and its open shadow roots.
 *
 * @param {Document|ShadowRoot|Element} root Where to look.
 * @param {string} selector The selector.
 * @return {Array<Element>}
 */
export function deepQueryAll(root, selector) {
	const found = [...root.querySelectorAll(selector)]
	for (const el of root.querySelectorAll('*')) {
		if (el.shadowRoot) found.push(...deepQueryAll(el.shadowRoot, selector))
	}
	return found
}

/**
 * Whether a field can be seen and used.
 *
 * @param {Element} el The field.
 * @return {boolean}
 */
export function usable(el) {
	if (!el || el.disabled || el.readOnly) return false
	if (String(el.type || '').toLowerCase() === 'hidden') return false
	if (el.closest?.('[aria-hidden="true"]')) return false
	const rect = el.getBoundingClientRect()
	if (rect.width === 0 && rect.height === 0) return false
	const style = el.ownerDocument.defaultView.getComputedStyle(el)
	return style.visibility !== 'hidden' && style.display !== 'none'
}

/**
 * The first usable element for a list of selectors, in order.
 *
 * @param {Document} doc The document.
 * @param {Array<string>} selectors The selectors.
 * @return {Element|null}
 */
export function firstUsable(doc, selectors) {
	for (const selector of selectors) {
		for (const el of deepQueryAll(doc, selector)) {
			if (usable(el)) return el
		}
	}
	return null
}

/**
 * The text a field is known by: its labels, placeholder and accessible name.
 *
 * @param {HTMLInputElement} el The field.
 * @return {string}
 */
function describedAs(el) {
	const labels = [...(el.labels || [])].map((l) => l.textContent)
	const labelledBy = (el.getAttribute('aria-labelledby') || '')
		.split(/\s+/)
		.filter(Boolean)
		.map((id) => el.getRootNode().getElementById?.(id)?.textContent || '')
	return [
		...labels,
		...labelledBy,
		el.getAttribute('aria-label') || '',
		el.getAttribute('placeholder') || '',
	].join(' ')
}

/**
 * The login fields of a document.
 *
 * @param {Document} doc The document.
 * @return {{username: HTMLInputElement|null, password: HTMLInputElement|null}}
 */
export function findLoginFields(doc) {
	const password = firstUsable(doc, PASSWORD_SELECTORS)
	let username = firstUsable(doc, USERNAME_SELECTORS)
	const textInputs = deepQueryAll(doc, 'input').filter((el) => {
		const type = String(el.type || 'text').toLowerCase()
		return (type === 'text' || type === 'email' || type === 'tel') && usable(el)
	})
	if (!username) {
		username =
			textInputs.find((el) => USERNAME_WORDS.test(describedAs(el))) || null
	}
	// Else the text field right before the password field.
	if (!username && password) {
		const all = deepQueryAll(doc, 'input')
		const at = all.indexOf(password)
		username =
			all
				.slice(0, at)
				.reverse()
				.find((el) => textInputs.includes(el)) || null
	}
	return { username, password }
}
