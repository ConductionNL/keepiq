/**
 * The popup's Generator tab: passwords and passphrases, made in the popup
 * with the web app's generator and the account's org policy.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-generator/spec.md#requirement-generator-tab-in-the-popup
 */

import {
	generateKey,
	generatePassphrase,
	generatorPolicy,
	passphraseAllowed,
} from '../../../src/generator/generator.js'

/**
 * Copy text, quietly doing nothing where the clipboard is unavailable.
 *
 * @param {string} text The text.
 * @return {Promise<void>}
 */
async function copyText(text) {
	try {
		await navigator.clipboard.writeText(text)
	} catch {
		// No clipboard (no focus, or not allowed): the value stays visible.
	}
}

/**
 * Read the form into generator options.
 *
 * @param {(id: string) => HTMLElement} $ Element by id.
 * @param {Document} doc The popup document.
 * @return {{mode: string, options: object}}
 */
export function readGeneratorForm($, doc) {
	const mode =
		doc.querySelector('input[name="gen-mode"]:checked')?.value || 'password'
	if (mode === 'passphrase') {
		return {
			mode,
			options: {
				words: Number($('gen-words').value),
				separator: $('gen-separator').value,
				capitalise: $('gen-capitalise').checked,
				includeNumber: $('gen-number').checked,
			},
		}
	}
	return {
		mode,
		options: {
			length: Number($('gen-length').value),
			includeSpecialCharacters: $('gen-symbols').checked,
			excludedCharacters: $('gen-exclude').value,
		},
	}
}

/**
 * Generate from the form, or return the reason it cannot.
 *
 * @param {{mode: string, options: object}} request The form's request.
 * @param {object|null} policy The org policy, raw.
 * @return {{value: string}|{error: string}}
 */
export function generateFrom(request, policy) {
	try {
		const value =
			request.mode === 'passphrase' && passphraseAllowed(policy)
				? generatePassphrase(request.options, policy)
				: generateKey(request.options, policy)
		return { value }
	} catch (e) {
		return { error: e.message }
	}
}

/**
 * The line that tells the user the policy shapes the result, or ''.
 *
 * @param {object|null} policy The org policy, raw.
 * @return {string}
 */
export function policyHint(policy) {
	const normalised = generatorPolicy(policy)
	if (normalised === null) {
		return ''
	}
	return `Your organisation asks for at least ${normalised.minLength} characters.`
}

/**
 * Wire the Generator tab.
 *
 * @param {object} ctx The popup context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {(type: string, payload?: object) => Promise<object>} ctx.send Message the worker.
 * @param {(id: string, message: string) => void} ctx.showError Show or clear an error.
 * @param {Document} [ctx.doc] The popup document.
 * @return {{open: () => Promise<void>, generate: () => string|null}}
 */
export function initGenerator({ $, send, showError, doc = document }) {
	let policy = null

	/**
	 * Generate into the output box.
	 *
	 * @return {string|null} The value, or null on an error.
	 */
	function generate() {
		const result = generateFrom(readGeneratorForm($, doc), policy)
		if (result.error) {
			showError('gen-error', result.error)
			$('gen-output').textContent = ''
			return null
		}
		showError('gen-error', '')
		$('gen-output').textContent = result.value
		return result.value
	}

	for (const radio of doc.querySelectorAll('input[name="gen-mode"]')) {
		radio.addEventListener('change', () => {
			const passphrase = radio.value === 'passphrase' && radio.checked
			$('gen-passphrase-options').hidden = !passphrase
			$('gen-password-options').hidden = passphrase
			generate()
		})
	}
	for (const id of [
		'gen-length',
		'gen-symbols',
		'gen-exclude',
		'gen-words',
		'gen-separator',
		'gen-capitalise',
		'gen-number',
	]) {
		$(id).addEventListener('change', generate)
	}
	$('gen-regenerate').addEventListener('click', generate)
	$('gen-copy').addEventListener('click', async () => {
		const value = $('gen-output').textContent
		if (value) await copyText(value)
	})

	return {
		async open() {
			policy = (await send('generator-policy')).policy ?? null
			$('gen-mode-passphrase').hidden = !passphraseAllowed(policy)
			const hint = policyHint(policy)
			$('gen-policy').textContent = hint
			$('gen-policy').hidden = hint === ''
			generate()
		},
		generate: () =>
			generateFrom({ mode: 'password', options: { length: 20 } }, policy).value
			?? null,
	}
}
