/**
 * Pure render helpers for the popup (no worker calls, no globals), so the
 * account switcher and the idle settings render the same in the popup and in
 * tests.
 */

/**
 * Fill the account switcher: one option per account with its lock state, the
 * active one selected.
 *
 * @param {HTMLSelectElement} select The switcher.
 * @param {object} state The worker's get-state answer.
 * @return {void}
 */
export function renderAccountSwitcher(select, state) {
	const doc = select.ownerDocument
	select.textContent = ''
	for (const account of state.accounts || []) {
		const option = doc.createElement('option')
		option.value = account.id
		const name = account.label || account.user + '@' + account.host
		option.textContent = name + (account.unlocked ? '' : ' (locked)')
		option.selected = account.id === state.activeAccountId
		select.appendChild(option)
	}
}

/**
 * Whether another account can still be added.
 *
 * @param {object} state The worker's get-state answer.
 * @return {boolean}
 */
export function canAddAccount(state) {
	return (state.accounts || []).length < (state.maxAccounts || 5)
}

/**
 * A human label for an idle delay.
 *
 * @param {number} minutes The delay.
 * @return {string}
 */
export function idleLabel(minutes) {
	if (minutes === 1) return '1 minute'
	if (minutes < 60) return minutes + ' minutes'
	if (minutes === 60) return '1 hour'
	return minutes / 60 + ' hours'
}

/**
 * Render the idle delay choices as radio buttons. A delay above the
 * organisation's maximum is shown, disabled, with the reason.
 *
 * @param {HTMLElement} container Where the choices go.
 * @param {{idleChoices: number[], idleMinutes: number, maxIdleMinutes: number|null}} state The active account's settings.
 * @param {function(number): void} onPick Called with the picked delay.
 * @return {void}
 */
export function renderIdleChoices(container, state, onPick) {
	const doc = container.ownerDocument
	container.textContent = ''
	const max = state.maxIdleMinutes
	for (const minutes of state.idleChoices || []) {
		const label = doc.createElement('label')
		label.className = 'idle-choice'
		const input = doc.createElement('input')
		input.type = 'radio'
		input.name = 'idle-minutes'
		input.value = String(minutes)
		input.checked = minutes === state.idleMinutes
		const tooLong = typeof max === 'number' && minutes > max
		input.disabled = tooLong
		input.addEventListener('change', () => {
			if (input.checked) onPick(minutes)
		})
		label.appendChild(input)
		label.appendChild(doc.createTextNode(' ' + idleLabel(minutes)))
		if (tooLong) {
			const note = doc.createElement('span')
			note.className = 'hint'
			note.textContent = " (above your organisation's maximum)"
			label.appendChild(note)
		}
		container.appendChild(label)
	}
	if (typeof max === 'number' && state.idleMinutes > max) {
		const note = doc.createElement('p')
		note.className = 'hint'
		note.textContent =
			'Your organisation locks the extension after '
			+ idleLabel(max)
			+ ' at most.'
		container.appendChild(note)
	}
}
