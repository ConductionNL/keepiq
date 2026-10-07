/**
 * Suggest a strong password in a sign-up or change-password field
 * (clients-extension-generator-vault-send).
 *
 * When a field for a NEW password gets focus, a small offer appears next to
 * it. On the user's own click the worker generates a password under the org
 * policy and it is filled into that field and its confirmation field. The
 * existing save prompt then offers to save the login after the form is sent.
 * The offer lives in a closed shadow root, so page script can neither read
 * nor click it.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-suggest-a-strong-password-in-a-sign-up-field
 */

const HOST_ID = 'keepiq-password-suggest'

/** Words that mark a field for a new password, in its name, id or label. */
const NEW_PASSWORD_HINT =
	/new|confirm|repeat|again|register|signup|sign-up|create|choose/i

/**
 * Whether an input is meant for a new password, not for signing in.
 *
 * @param {HTMLInputElement} input The input.
 * @return {boolean}
 */
export function isNewPasswordField(input) {
	if (!input || (input.type || '').toLowerCase() !== 'password') {
		return false
	}
	const autocomplete = (input.getAttribute('autocomplete') || '').toLowerCase()
	if (autocomplete.includes('current-password')) {
		return false
	}
	if (autocomplete.includes('new-password')) {
		return true
	}
	const scope = input.form || input.ownerDocument
	const passwords = scope.querySelectorAll('input[type="password"]')
	if (passwords.length >= 2) {
		// A password and its confirmation: a sign-up or change form.
		return true
	}
	const words = [
		input.name,
		input.id,
		input.getAttribute('aria-label'),
		input.placeholder,
	]
		.filter(Boolean)
		.join(' ')
	return NEW_PASSWORD_HINT.test(words)
}

/**
 * The fields to fill for one new password: every password field of the same
 * form that is not for the current password.
 *
 * @param {HTMLInputElement} input The focused field.
 * @return {HTMLInputElement[]}
 */
export function fieldsToFill(input) {
	const scope = input.form || input.ownerDocument
	return Array.from(scope.querySelectorAll('input[type="password"]')).filter(
		(field) =>
			!(field.getAttribute('autocomplete') || '')
				.toLowerCase()
				.includes('current-password'),
	)
}

/**
 * Set a value so frameworks (React, Vue) see the change.
 *
 * @param {HTMLInputElement} el The input.
 * @param {string} value The value.
 */
function setValue(el, value) {
	const setter = Object.getOwnPropertyDescriptor(
		Object.getPrototypeOf(el),
		'value',
	)?.set
	if (setter) {
		setter.call(el, value)
	} else {
		el.value = value
	}
	el.dispatchEvent(new Event('input', { bubbles: true }))
	el.dispatchEvent(new Event('change', { bubbles: true }))
}

/**
 * Show the offer next to a field. Resolves with true when the user took the
 * password and it was filled, false when the offer closed without it.
 *
 * @param {HTMLInputElement} input The new-password field.
 * @param {() => Promise<{value?: string, error?: string}>} request Asks the worker for a password.
 * @param {object} [options] Test seams; production uses the defaults.
 * @param {string} [options.mode] The shadow root mode, closed by default.
 * @param {(event: Event) => boolean} [options.isUserEvent] Whether a click came from the user.
 * @return {Promise<boolean>}
 */
export function showSuggestion(
	input,
	request,
	{ mode = 'closed', isUserEvent = (event) => event.isTrusted } = {},
) {
	const doc = input.ownerDocument
	doc.getElementById(HOST_ID)?.remove()
	const holder = doc.createElement('div')
	holder.id = HOST_ID
	const root = holder.attachShadow({ mode })

	const rect = input.getBoundingClientRect()
	const style = doc.createElement('style')
	style.textContent = `
		.offer { position: fixed; z-index: 2147483647; max-width: 320px;
			padding: 8px 12px; border-radius: 8px; background: #fff; color: #222;
			box-shadow: 0 2px 12px rgba(0,0,0,.25); font: 14px/1.4 system-ui, sans-serif;
			display: flex; gap: 8px; align-items: center; }
		button { font: inherit; padding: 4px 10px; border-radius: 6px; cursor: pointer;
			border: 1px solid #00679e; background: #00679e; color: #fff; }
		button.close { background: transparent; color: inherit; border-color: #767676; }
		@media (prefers-color-scheme: dark) {
			.offer { background: #1e1e1e; color: #eee; }
		}`
	const offer = doc.createElement('div')
	offer.className = 'offer'
	offer.setAttribute('role', 'dialog')
	offer.setAttribute('aria-label', 'Keepiq')
	offer.style.top = `${Math.round(rect.bottom + 6)}px`
	offer.style.left = `${Math.round(rect.left)}px`
	const use = doc.createElement('button')
	use.type = 'button'
	use.textContent = 'Use a strong password'
	const close = doc.createElement('button')
	close.type = 'button'
	close.className = 'close'
	close.textContent = 'No thanks'
	close.setAttribute('aria-label', 'Close the Keepiq offer')
	offer.append(use, close)
	root.append(style, offer)
	;(doc.body || doc.documentElement).appendChild(holder)

	return new Promise((resolve) => {
		let done = false
		const finish = (filled) => {
			if (done) return
			done = true
			holder.remove()
			resolve(filled)
		}
		use.addEventListener('click', async (event) => {
			// A script-dispatched click is not the user's choice.
			if (!isUserEvent(event)) return
			const answer = await request().catch(() => ({}))
			if (!answer?.value) {
				finish(false)
				return
			}
			for (const field of fieldsToFill(input)) {
				setValue(field, answer.value)
			}
			finish(true)
		})
		close.addEventListener('click', (event) => {
			if (isUserEvent(event)) finish(false)
		})
		input.addEventListener('blur', () => setTimeout(() => finish(false), 300), {
			once: true,
		})
	})
}

/**
 * Offer a strong password whenever a new-password field gets focus, at most
 * once per field.
 *
 * @param {Document} doc The document.
 * @param {() => Promise<{value?: string}>} request Asks the worker for a password.
 * @return {void}
 */
export function attachPasswordSuggestions(doc, request) {
	const offered = new WeakSet()
	doc.addEventListener(
		'focusin',
		(event) => {
			const input = event.target
			if (!(input instanceof doc.defaultView.HTMLInputElement)) return
			if (
				offered.has(input)
				|| !isNewPasswordField(input)
				|| input.value !== ''
			)
				return
			offered.add(input)
			showSuggestion(input, request)
		},
		true,
	)
}
