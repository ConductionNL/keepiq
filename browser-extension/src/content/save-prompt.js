/**
 * The in-page save or update offer after a login form is submitted
 * (clients-save-prompt). It lives in a closed shadow root, so page script can
 * neither read nor click it, and it acts only on trusted (user) clicks.
 *
 * @spec openspec/specs/clients-save-prompt/spec.md
 */

const HOST_ID = 'keepiq-save-prompt'

/** How long the offer stays before it closes on its own (ms). */
export const PROMPT_TTL_MS = 30000

/**
 * The words of the offer.
 *
 * @param {{action: string, name?: string}} offer The offer from the worker.
 * @param {string} host The site host.
 * @return {{text: string, primary: string|null}} The message and the main button (null: none).
 */
export function promptCopy(offer, host) {
	if (offer.action === 'refused') {
		// The org password policy refuses this password (keepiq#746): explain,
		// and offer no save button.
		return {
			text: `Keepiq did not save this login for ${host}. ${offer.reason || ''}`.trim(),
			primary: null,
		}
	}
	if (offer.action === 'update') {
		return {
			text: `Update the password of ${offer.name || host} in Keepiq?`,
			primary: 'Update',
		}
	}
	return { text: `Save this login for ${host} in Keepiq?`, primary: 'Save' }
}

/**
 * Show the offer. Resolves with the user's choice: 'save', 'update' or
 * 'dismiss' (Not now, or the offer timed out).
 *
 * @param {{action: string, name?: string}} offer The offer from the worker.
 * @param {string} host The site host.
 * @param {Document} doc The document to show it in.
 * @param {object} [options] Test seams; production uses the defaults.
 * @param {string} [options.mode] The shadow root mode, closed by default.
 * @param {Function} [options.isUserEvent] Whether a click came from the user.
 * @return {Promise<string>} The choice.
 */
export function showSavePrompt(
	offer,
	host,
	doc = document,
	{ mode = 'closed', isUserEvent = (event) => event.isTrusted } = {},
) {
	doc.getElementById(HOST_ID)?.remove()
	const holder = doc.createElement('div')
	holder.id = HOST_ID
	const root = holder.attachShadow({ mode })
	const { text, primary } = promptCopy(offer, host)

	const style = doc.createElement('style')
	style.textContent = `
		.bar { position: fixed; top: 12px; right: 12px; z-index: 2147483647;
			max-width: 360px; padding: 12px 16px; border-radius: 8px;
			background: #fff; color: #222; box-shadow: 0 2px 12px rgba(0,0,0,.25);
			font: 14px/1.4 system-ui, sans-serif; }
		.actions { display: flex; gap: 8px; margin-top: 8px; justify-content: flex-end; }
		button { font: inherit; padding: 6px 12px; border-radius: 6px; cursor: pointer;
			border: 1px solid #767676; background: #fff; color: #222; }
		button.primary { background: #00679e; border-color: #00679e; color: #fff; }
		@media (prefers-color-scheme: dark) {
			.bar { background: #1e1e1e; color: #eee; }
			button { background: #1e1e1e; color: #eee; border-color: #8c8c8c; }
		}`
	const bar = doc.createElement('div')
	bar.className = 'bar'
	bar.setAttribute('role', 'dialog')
	bar.setAttribute('aria-label', 'Keepiq')
	const message = doc.createElement('p')
	message.textContent = text
	message.style.margin = '0'
	const actions = doc.createElement('div')
	actions.className = 'actions'
	const later = doc.createElement('button')
	later.type = 'button'
	later.textContent = primary === null ? 'Close' : 'Not now'
	const main = doc.createElement('button')
	main.type = 'button'
	main.className = 'primary'
	main.textContent = primary
	// Only a new login can be declined for the site for good.
	const never = doc.createElement('button')
	never.type = 'button'
	never.textContent = 'Never for this site'
	if (primary === null) {
		actions.append(later)
	} else if (offer.action === 'save') {
		actions.append(never, later, main)
	} else {
		actions.append(later, main)
	}
	bar.append(message, actions)
	root.append(style, bar)
	;(doc.body || doc.documentElement).appendChild(holder)

	return new Promise((resolve) => {
		let done = false
		const finish = (choice) => {
			if (done) return
			done = true
			holder.remove()
			resolve(choice)
		}
		const timer = setTimeout(() => finish('dismiss'), PROMPT_TTL_MS)
		const onClick = (choice) => (event) => {
			// A script-dispatched click is not the user's choice.
			if (!isUserEvent(event)) return
			clearTimeout(timer)
			finish(choice)
		}
		main.addEventListener('click', onClick(offer.action))
		later.addEventListener('click', onClick('dismiss'))
		never.addEventListener('click', onClick('never'))
		;(primary === null ? later : main).focus?.()
	})
}

/** How long the result of a save stays on screen. */
export const RESULT_TTL_MS = 4000

/**
 * Say how a save from the bar went, for a few seconds.
 *
 * @param {{ok?: boolean, saved?: string, error?: string}} result The worker's answer.
 * @param {Document} doc The document to show it in.
 * @param {object} [options] Test seams; production uses the defaults.
 * @param {string} [options.mode] The shadow root mode, closed by default.
 * @return {string} The text shown.
 * @spec openspec/changes/clients-extension-finish/specs/extension-save-prompt-details/spec.md#requirement-a-save-that-confirms
 */
export function showSaveResult(result, doc = document, { mode = 'closed' } = {}) {
	doc.getElementById(HOST_ID)?.remove()
	const text = result?.error
		? `Keepiq could not save this login: ${result.error}`
		: result?.saved === 'updated'
			? 'Password updated in Keepiq.'
			: 'Login saved to Keepiq.'
	const holder = doc.createElement('div')
	holder.id = HOST_ID
	const root = holder.attachShadow({ mode })
	const style = doc.createElement('style')
	style.textContent = `
		.bar { position: fixed; top: 12px; right: 12px; z-index: 2147483647;
			max-width: 360px; padding: 12px 16px; border-radius: 8px;
			background: #fff; color: #222; box-shadow: 0 2px 12px rgba(0,0,0,.25);
			font: 14px/1.4 system-ui, sans-serif; }
		@media (prefers-color-scheme: dark) { .bar { background: #1e1e1e; color: #eee; } }`
	const bar = doc.createElement('p')
	bar.className = 'bar'
	bar.setAttribute('role', 'status')
	bar.textContent = text
	root.append(style, bar)
	;(doc.body || doc.documentElement).appendChild(holder)
	setTimeout(() => holder.remove(), RESULT_TTL_MS)
	return text
}
