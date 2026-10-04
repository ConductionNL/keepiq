/**
 * The popup's Send tab: create a send from text or a username and password,
 * copy its link once, and see or end your sends.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-create-a-send-from-the-popup
 */

import { EXPIRY_PRESETS, sendRowLabel } from '../lib/send-form.js'
import { copyText } from './clipboard.js'

/**
 * Wire the Send tab.
 *
 * @param {object} ctx The popup context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {(type: string, payload?: object) => Promise<object>} ctx.send Message the worker.
 * @param {(id: string, message: string) => void} ctx.showError Show or clear an error.
 * @param {Document} [ctx.doc] The popup document.
 * @return {{open: (prefill?: {login?: string, secret?: string}) => Promise<void>}}
 */
export function initSend({ $, send, showError, doc = document }) {
	for (const preset of EXPIRY_PRESETS) {
		const option = doc.createElement('option')
		option.value = preset.id
		option.textContent = preset.label
		$('send-expiry').appendChild(option)
	}
	$('send-expiry').value = '1d'

	// Links of sends made while this popup is open, by send id. A link holds
	// the key, so it lives only here and only until the popup closes.
	const sessionLinks = new Map()

	/** @return {string} The chosen kind of send. */
	const kind = () =>
		doc.querySelector('input[name="send-type"]:checked')?.value || 'text'

	/** Show the fields for the chosen kind. */
	function syncKind() {
		const credential = kind() === 'credential'
		$('send-credential').hidden = !credential
		$('send-text-label').hidden = credential
	}

	/** Load and render the account's sends. */
	async function loadSends() {
		const { sends = [], error } = await send('send-list')
		const list = $('send-list')
		list.replaceChildren()
		$('send-empty').hidden = sends.length > 0 || !!error
		if (error) {
			showError('send-error', error)
			return
		}
		for (const row of sends) {
			const li = doc.createElement('li')
			li.className = 'candidate row'
			const label = doc.createElement('span')
			label.textContent = `${sendRowLabel(row)} (${row.viewCount} of ${row.maxViews} opened)`
			const end = doc.createElement('button')
			end.className = 'link danger'
			end.textContent = 'End'
			end.setAttribute('aria-label', `End ${sendRowLabel(row)}`)
			end.addEventListener('click', async () => {
				const res = await send('send-revoke', { id: row.id })
				if (res.error) showError('send-error', res.error)
				await loadSends()
			})
			li.append(label)
			if (sessionLinks.has(row.id)) {
				const copy = doc.createElement('button')
				copy.className = 'link'
				copy.textContent = 'Copy link'
				copy.setAttribute(
					'aria-label',
					`Copy the link of ${sendRowLabel(row)}`,
				)
				copy.addEventListener('click', () =>
					copyText(sessionLinks.get(row.id)),
				)
				li.append(copy)
			}
			li.append(end)
			list.appendChild(li)
		}
	}

	for (const radio of doc.querySelectorAll('input[name="send-type"]')) {
		radio.addEventListener('change', syncKind)
	}
	$('send-expiry').addEventListener('change', () => {
		$('send-custom-label').hidden = $('send-expiry').value !== 'custom'
	})
	$('send-form').addEventListener('submit', async (event) => {
		event.preventDefault()
		showError('send-error', '')
		const res = await send('send-create', {
			payloadType: kind(),
			text: $('send-text').value,
			username: $('send-username').value,
			password: $('send-password').value,
			maxViews: $('send-views').value,
			expiry: $('send-expiry').value,
			customHours: $('send-custom').value,
			sendPassword: $('send-protect').value,
		})
		if (res.error) {
			showError('send-error', res.error)
			return
		}
		// The link exists only here: the key is in its fragment, never stored.
		$('send-link').value = res.link
		if (res.id) sessionLinks.set(res.id, res.link)
		$('send-result').hidden = false
		for (const id of [
			'send-text',
			'send-username',
			'send-password',
			'send-protect',
		]) {
			$(id).value = ''
		}
		await loadSends()
	})
	$('send-copy').addEventListener('click', async () => {
		await copyText($('send-link').value)
		$('send-copy').textContent = 'Copied'
	})

	return {
		async open(prefill) {
			$('send-result').hidden = true
			$('send-link').value = ''
			$('send-copy').textContent = 'Copy link'
			if (prefill) {
				doc.querySelector(
					'input[name="send-type"][value="credential"]',
				).checked = true
				$('send-username').value = prefill.login || ''
				$('send-password').value = prefill.secret || ''
			}
			syncKind()
			await loadSends()
		},
	}
}
