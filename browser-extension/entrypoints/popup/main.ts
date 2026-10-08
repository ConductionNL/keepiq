/**
 * Popup script. Reads state from the background over request/response
 * `sendMessage` and toggles the flag back.
 *
 * The popup is a fresh document every time it opens and is destroyed the moment
 * it closes, so it holds no state of its own — the background is the source of
 * truth. Anything that must survive the popup closing lives there.
 */

import type { PopupState, PopupToBackground } from '@/src/messages'

const pill = document.getElementById('status-pill')!
const label = document.getElementById('status-label')!
const detail = document.getElementById('detail')!
const toggle = document.getElementById('toggle') as HTMLButtonElement

/** Typed wrapper so the `unknown` cast lives in exactly one place. */
async function request(msg: PopupToBackground): Promise<PopupState> {
	return (await browser.runtime.sendMessage(msg)) as PopupState
}

function render(state: PopupState): void {
	pill.classList.toggle('pill--on', state.enabled)
	label.textContent = state.enabled ? 'On' : 'Off'
	detail.textContent = `${state.activeTabs} tab${state.activeTabs === 1 ? '' : 's'} with a live content script.`
	toggle.textContent = state.enabled ? 'Disable' : 'Enable'
	toggle.disabled = false
	toggle.dataset.enabled = String(state.enabled)
}

toggle.addEventListener('click', () => {
	toggle.disabled = true
	const enabled = toggle.dataset.enabled !== 'true'
	void request({ kind: 'set_enabled', enabled }).then(render)
})

void request({ kind: 'get_state' }).then(render)
