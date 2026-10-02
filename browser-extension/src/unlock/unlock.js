/**
 * The unlock window (extension-biometric-unlock §D3): an extension page that
 * runs the passkey ceremony for one account, because the OS prompt takes focus
 * and would close the popup. `?mode=unlock` unlocks with an enrolled passkey,
 * `?mode=enrol` sets one up. The window closes after a successful handoff.
 */

import { enrolBiometric, unlockWithBiometric } from './ceremony.js'

const params = new URLSearchParams(location.search)
const mode = params.get('mode') === 'enrol' ? 'enrol' : 'unlock'
const accountId = params.get('account') || ''

function $(id) {
	return document.getElementById(id)
}

function send(type, payload) {
	return new Promise((resolve) => {
		chrome.runtime.sendMessage({ type, payload }, (res) => resolve(res || {}))
	})
}

function showError(message) {
	$('unlock-error').textContent = message
	$('unlock-error').hidden = !message
}

async function runUnlock() {
	$('view-unlock').hidden = false
	try {
		await unlockWithBiometric({
			credentials: navigator.credentials,
			send,
			accountId,
		})
		window.close()
	} catch (e) {
		showError(e.message || String(e))
	}
}

function wireEnrol() {
	$('view-enrol').hidden = false
	$('enrol-submit').addEventListener('click', async () => {
		showError('')
		const masterPassword = $('enrol-master').value
		$('enrol-master').value = ''
		try {
			await enrolBiometric({
				credentials: navigator.credentials,
				send,
				accountId,
				masterPassword,
				label: $('enrol-label').value.trim(),
			})
			window.close()
		} catch (e) {
			showError(e.message || String(e))
		}
	})
}

$('unlock-close').addEventListener('click', () => window.close())

if (mode === 'enrol') wireEnrol()
else runUnlock()
