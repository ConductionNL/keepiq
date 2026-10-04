/**
 * The popup's Generator tab: Password, Passphrase and Username sub-tabs,
 * colour-coded output, history, options kept per account, and a pick mode
 * that hands a value back to the item form. Values are made here, in the
 * popup, with the web app's generator; nothing needs the vault key, so the
 * tab also works while locked.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-generator-sub-tabs
 */

import {
	generateKey,
	generatePassphrase,
	generatorPolicy,
	passphraseAllowed,
} from '../../../src/generator/generator.js'
import { generateUsername } from '../../../src/generator/username.js'
import { relativeTime, sanitizeOptions } from '../lib/generator-state.js'
import { copyText } from './clipboard.js'

/**
 * Generate a value for a sub-tab, or return why it cannot.
 *
 * @param {string} kind password, passphrase or username.
 * @param {object} options The sanitised options.
 * @param {object|null} policy The org policy, raw.
 * @param {string} website The active site's host name, or ''.
 * @return {{value: string}|{error: string}}
 * @spec openspec/specs/extension-generator/spec.md#requirement-generator-sub-tabs
 */
export function generateFor(kind, options, policy, website) {
	try {
		if (kind === 'passphrase' && passphraseAllowed(policy)) {
			return { value: generatePassphrase(options.passphrase, policy) }
		}
		if (kind === 'username') {
			const u = options.username
			return {
				value: generateUsername({
					...u,
					mode: u.type === 'plus' ? u.emailMode : u.domainMode,
					website,
				}),
			}
		}
		return { value: generateKey(options.password, policy) }
	} catch (e) {
		return { error: e.message }
	}
}

/**
 * Render a value with digits and special characters in their own colours.
 *
 * @param {HTMLElement} target The element to fill.
 * @param {string} value The value.
 * @param {Document} doc The popup document.
 */
export function renderColoured(target, value, doc) {
	target.replaceChildren()
	for (const char of value) {
		const span = doc.createElement('span')
		span.textContent = char
		if (/[0-9]/.test(char)) span.className = 'gen-digit'
		else if (/[^A-Za-z0-9]/.test(char)) span.className = 'gen-special'
		target.appendChild(span)
	}
	target.dataset.value = value
}

/**
 * The line telling the user the policy shapes the result, or ''.
 *
 * @param {object|null} policy The org policy, raw.
 * @return {string}
 */
export function policyHint(policy) {
	const normalised = generatorPolicy(policy)
	return normalised === null
		? ''
		: `Set by your organisation: at least ${normalised.minLength} characters.`
}

/**
 * Wire the Generator tab.
 *
 * @param {object} ctx The popup context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {(type: string, payload?: object) => Promise<object>} ctx.send Message the worker.
 * @param {(id: string, message: string) => void} ctx.showError Show or clear an error.
 * @param {Document} [ctx.doc] The popup document.
 * @return {{open: (pick?: {kind: string, onPick: (value: string) => void}) => Promise<void>, current: () => string}}
 */
export function initGenerator({ $, send, showError, doc = document }) {
	let policy = null
	let options = sanitizeOptions(null)
	let website = ''
	let pick = null
	let saveTimer = null

	/** Read the form into the options. */
	function readForm() {
		options = sanitizeOptions(
			{
				tab: options.tab,
				password: {
					length: $('gen-length').value,
					includeUppercase: $('gen-upper').checked,
					includeLowercase: $('gen-lower').checked,
					includeDigits: $('gen-digits').checked,
					includeSpecialCharacters: $('gen-symbols').checked,
					minDigits: $('gen-min-digits').value,
					minSpecial: $('gen-min-special').value,
					avoidAmbiguous: $('gen-ambiguous').checked,
				},
				passphrase: {
					words: $('gen-words').value,
					separator: $('gen-separator').value,
					capitalise: $('gen-capitalise').checked,
					includeNumber: $('gen-number').checked,
				},
				username: {
					type: $('gen-username-type').value,
					capitalize: $('gen-username-capitalize').checked,
					includeNumber: $('gen-username-number').checked,
					email: $('gen-email').value,
					emailMode: doc.querySelector(
						'input[name="gen-email-mode"]:checked',
					)?.value,
					domain: $('gen-domain').value,
					domainMode: doc.querySelector(
						'input[name="gen-domain-mode"]:checked',
					)?.value,
				},
			},
			policy,
		)
	}

	/**
	 * Lock the controls the organisation's policy decides: a required kind
	 * stays on, its minimum starts at one, and the length cannot go below
	 * the floor. Each locked control says why.
	 *
	 * @spec openspec/specs/extension-generator-policy/spec.md#requirement-controls-the-policy-decides-are-shown-as-such
	 */
	function lockToPolicy() {
		const rule = generatorPolicy(policy)
		const required = {
			'gen-upper': rule?.requireUpper,
			'gen-lower': rule?.requireLower,
			'gen-digits': rule?.requireDigit,
			'gen-symbols': rule?.requireSymbol,
		}
		for (const [id, on] of Object.entries(required)) {
			const box = $(id)
			box.disabled = !!on
			const label = box.closest('label')
			let note = label.querySelector('.gen-required')
			if (on && !note) {
				note = doc.createElement('span')
				note.className = 'gen-required hint'
				note.textContent = ' (required by your organisation)'
				label.appendChild(note)
			}
			if (!on && note) note.remove()
		}
		$('gen-min-digits').min = rule?.requireDigit ? '1' : '0'
		$('gen-min-special').min = rule?.requireSymbol ? '1' : '0'
		const floor = String(rule ? rule.minLength : 8)
		$('gen-length').min = floor
		$('gen-length-range').min = floor
	}

	/** Put the options into the form, and show the right fields. */
	function writeForm() {
		const p = options.password
		$('gen-length').value = p.length
		$('gen-length-range').value = p.length
		$('gen-length-value').textContent = String(p.length)
		$('gen-upper').checked = p.includeUppercase
		$('gen-lower').checked = p.includeLowercase
		$('gen-digits').checked = p.includeDigits
		$('gen-symbols').checked = p.includeSpecialCharacters
		$('gen-min-digits').value = p.minDigits
		$('gen-min-special').value = p.minSpecial
		$('gen-ambiguous').checked = p.avoidAmbiguous
		lockToPolicy()
		const w = options.passphrase
		$('gen-words').value = w.words
		$('gen-separator').value = w.separator
		$('gen-capitalise').checked = w.capitalise
		$('gen-number').checked = w.includeNumber
		const u = options.username
		$('gen-username-type').value = u.type
		$('gen-username-capitalize').checked = u.capitalize
		$('gen-username-number').checked = u.includeNumber
		$('gen-email').value = u.email
		$('gen-domain').value = u.domain
		for (const [name, mode] of [
			['gen-email-mode', u.emailMode],
			['gen-domain-mode', u.domainMode],
		]) {
			const radio = doc.querySelector(
				`input[name="${name}"][value="${website ? mode : 'random'}"]`,
			)
			if (radio) radio.checked = true
			const site = doc.querySelector(`input[name="${name}"][value="website"]`)
			if (site) site.disabled = website === ''
		}
		$('gen-website-hint').hidden = website !== ''
		$('gen-username-word').hidden = u.type !== 'word'
		$('gen-username-plus').hidden = u.type !== 'plus'
		$('gen-username-catchall').hidden = u.type !== 'catchall'

		const allowPassphrase = passphraseAllowed(policy)
		$('gen-tab-passphrase').hidden = !allowPassphrase
		for (const kind of ['password', 'passphrase', 'username']) {
			$('gen-tab-' + kind).setAttribute(
				'aria-selected',
				options.tab === kind ? 'true' : 'false',
			)
			$(`gen-${kind}-options`).hidden = options.tab !== kind
		}
		const hint = policyHint(policy)
		$('gen-policy').textContent = hint
		$('gen-policy').hidden = hint === ''
	}

	/** Generate a value with the current options, show it, keep it in the history. */
	async function generate() {
		const result = generateFor(options.tab, options, policy, website)
		if (result.error) {
			showError('gen-error', result.error)
			renderColoured($('gen-output'), '', doc)
			return
		}
		showError('gen-error', '')
		renderColoured($('gen-output'), result.value, doc)
		await send('generator-history-add', {
			value: result.value,
			kind: options.tab,
		})
	}

	/** Save the options a moment after the last change. */
	function saveSoon() {
		clearTimeout(saveTimer)
		saveTimer = setTimeout(
			() => send('generator-options-save', { options }),
			200,
		)
	}

	/** An option changed: read, write back (clamped), regenerate, save. */
	async function changed() {
		readForm()
		writeForm()
		saveSoon()
		await generate()
	}

	/** Render the history list. */
	async function showHistory() {
		const { history = [] } = await send('generator-context')
		const list = $('gen-history-list')
		list.replaceChildren()
		$('gen-history-empty').hidden = history.length > 0
		for (const entry of history) {
			const li = doc.createElement('li')
			li.className = 'candidate row'
			const value = doc.createElement('output')
			value.className = 'gen-output small'
			renderColoured(value, entry.value, doc)
			const when = doc.createElement('span')
			when.className = 'hint'
			when.textContent = relativeTime(entry.at)
			const copy = doc.createElement('button')
			copy.className = 'link'
			copy.textContent = 'Copy'
			copy.setAttribute(
				'aria-label',
				`Copy the value from ${relativeTime(entry.at)}`,
			)
			copy.addEventListener('click', () => copyText(entry.value))
			li.append(value, when, copy)
			list.appendChild(li)
		}
		$('gen-main').hidden = true
		$('gen-history').hidden = false
	}

	for (const kind of ['password', 'passphrase', 'username']) {
		$('gen-tab-' + kind).addEventListener('click', async () => {
			options.tab = kind
			writeForm()
			saveSoon()
			await generate()
		})
	}
	$('gen-length-range').addEventListener('input', () => {
		$('gen-length').value = $('gen-length-range').value
		changed()
	})
	for (const el of doc.querySelectorAll(
		'#gen-password-options input:not([type=range]), #gen-passphrase-options input, #gen-username-options input, #gen-username-options select',
	)) {
		el.addEventListener('change', changed)
	}
	$('gen-regenerate').addEventListener('click', generate)
	$('gen-copy').addEventListener('click', () =>
		copyText($('gen-output').dataset.value || ''),
	)
	$('gen-use').addEventListener('click', () => {
		const value = $('gen-output').dataset.value || ''
		if (pick && value) pick.onPick(value)
	})
	$('gen-history-open').addEventListener('click', showHistory)
	$('gen-history-back').addEventListener('click', () => {
		$('gen-history').hidden = true
		$('gen-main').hidden = false
	})
	$('gen-history-clear').addEventListener('click', async () => {
		await send('generator-history-clear')
		await showHistory()
	})

	return {
		/**
		 * Open the tab, optionally in pick mode for the item form.
		 *
		 * @param {{kind: string, onPick: (value: string) => void}} [pickMode] The field asking.
		 * @return {Promise<void>}
		 */
		async open(pickMode) {
			pick = pickMode || null
			const context = await send('generator-context')
			policy = context.policy ?? null
			website = context.website || ''
			options = sanitizeOptions(context.options, policy)
			if (pick) {
				options.tab =
					pick.kind === 'username'
						? 'username'
						: options.tab === 'username'
							? 'password'
							: options.tab
			}
			$('gen-use').hidden = !pick
			$('gen-history').hidden = true
			$('gen-main').hidden = false
			writeForm()
			await generate()
		},
		current: () => $('gen-output').dataset.value || '',
	}
}
