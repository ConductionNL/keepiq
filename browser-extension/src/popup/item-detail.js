/**
 * The item detail view: sections for every kind of item. Values arrive
 * decrypted from the worker when the item opens and live in this view only;
 * `clearDetail` drops them when the view closes. A passkey's private key
 * never reaches this view: the worker sends only the site and account.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-detail-sections-for-every-kind-of-item
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-a-passkeys-private-key-stays-in-the-worker
 */

import {
	cardBrand,
	cardLast4,
	parsePayload,
} from '../../../src/cardIdentity/cardIdentity.js'
import {
	generateTotp,
	parseOtpauth,
	secondsRemaining,
} from '../../../src/totp/totp.js'
import {
	COMPOSITE_LABELS,
	compositeFields,
	folderPath,
	formKind,
	MASKED_COMPOSITE,
	NOTES_FIELD,
} from '../lib/item-form.js'
import { copyText } from './clipboard.js'

const MASK = '••••••••'
let totpTimer = null

/**
 * A titled section.
 *
 * @param {Document} doc The popup document.
 * @param {string} title The heading.
 * @return {HTMLElement}
 */
function section(doc, title) {
	const el = doc.createElement('section')
	el.className = 'detail-section'
	const h = doc.createElement('h3')
	h.textContent = title
	el.appendChild(h)
	return el
}

/**
 * One labelled value, optionally masked with Show, and with Copy.
 *
 * @param {Document} doc The popup document.
 * @param {string} label The label.
 * @param {string} value The value.
 * @param {object} [options] The options.
 * @param {boolean} [options.masked] Hidden until Show.
 * @param {boolean} [options.copy] Offer Copy (default true).
 * @param {string} [options.id] Id prefix for the value and its buttons.
 * @return {HTMLElement}
 */
function fieldRow(doc, label, value, { masked = false, copy = true, id = '' } = {}) {
	const row = doc.createElement('div')
	row.className = 'field-row'
	const name = doc.createElement('span')
	name.className = 'field-label'
	name.textContent = label
	const shown = doc.createElement('span')
	shown.className = 'field-value'
	if (id) shown.id = id
	shown.textContent = masked ? MASK : value || '—'
	row.append(name, shown)
	if (masked) {
		const reveal = doc.createElement('button')
		reveal.className = 'link'
		reveal.textContent = 'Show'
		reveal.setAttribute('aria-pressed', 'false')
		reveal.setAttribute('aria-label', `Show ${label}`)
		if (id)
			reveal.id = id
				.replace(/^detail-/, 'detail-reveal-')
				.replace('detail-reveal-secret', 'detail-reveal')
		reveal.addEventListener('click', () => {
			const open = reveal.getAttribute('aria-pressed') === 'true'
			shown.textContent = open ? MASK : value
			reveal.textContent = open ? 'Show' : 'Hide'
			reveal.setAttribute('aria-pressed', open ? 'false' : 'true')
		})
		row.appendChild(reveal)
	}
	if (copy && value) {
		const button = doc.createElement('button')
		button.className = 'link'
		button.textContent = 'Copy'
		button.setAttribute('aria-label', `Copy ${label}`)
		if (id) button.id = id.replace(/^detail-/, 'detail-copy-')
		button.addEventListener('click', () => copyText(value))
		row.appendChild(button)
	}
	return row
}

/**
 * The TOTP section: the current code, grouped, with a countdown.
 *
 * @param {Document} doc The popup document.
 * @param {string} seed The decrypted otpauth URI or base32 secret.
 * @return {HTMLElement}
 */
function totpSection(doc, seed) {
	const el = section(doc, 'Authenticator code')
	let params
	try {
		params = parseOtpauth(seed)
	} catch {
		params = null
	}
	if (!params) {
		const p = doc.createElement('p')
		p.className = 'error'
		p.textContent = 'This is not a valid authenticator secret.'
		el.appendChild(p)
		return el
	}
	const row = doc.createElement('div')
	row.className = 'field-row'
	const code = doc.createElement('span')
	code.className = 'totp-code'
	code.id = 'detail-totp-code'
	const count = doc.createElement('span')
	count.className = 'totp-count'
	count.id = 'detail-totp-count'
	const copy = doc.createElement('button')
	copy.className = 'link'
	copy.textContent = 'Copy'
	copy.setAttribute('aria-label', 'Copy the code')
	row.append(code, count, copy)
	el.appendChild(row)
	let current = ''
	const tick = async () => {
		current = await generateTotp(params)
		const half = Math.ceil(current.length / 2)
		code.textContent = `${current.slice(0, half)} ${current.slice(half)}`
		count.textContent = `${secondsRemaining(params.period)}s`
	}
	copy.addEventListener('click', () => copyText(current))
	tick()
	clearInterval(totpTimer)
	totpTimer = setInterval(tick, 1000)
	return el
}

/**
 * Render an item into the detail view.
 *
 * @param {object} ctx The view context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {Document} ctx.doc The popup document.
 * @param {object} item The item from the worker.
 * @param {Array<object>} folders The folders, for the path.
 * @return {void}
 */
export function renderDetail({ $, doc }, item, folders) {
	clearDetail({ $ })
	$('detail-name').textContent = item.name
	$('detail-meta').textContent =
		`${item.typeName} · ${folderPath(folders, item.folderId)}`
	const blocked = item.blocked === true
	$('detail-blocked').hidden = !blocked
	for (const id of ['detail-edit', 'detail-clone', 'detail-move', 'detail-send']) {
		$(id).dataset.blocked = blocked ? 'true' : 'false'
		$(id).disabled = blocked || $('vault-offline').hidden === false
	}
	// A clone would copy a passkey's key and a Send would carry it.
	const isPasskey = formKind(item.typeName) === 'passkey'
	$('detail-clone').hidden = isPasskey
	// A send carries a username and password: logins only.
	$('detail-send').hidden = formKind(item.typeName) !== 'login'
	if (blocked) {
		$('detail-blocked-reason').textContent = item.blockedReason
		$('detail-migration').textContent = item.migrationError || ''
		$('detail-migration').hidden = !item.migrationError
		return
	}
	const sections = $('detail-sections')
	const kind = formKind(item.typeName)
	const fields = item.additionalFields || {}
	const notesKey = Object.keys(fields).find((k) => k.toLowerCase() === NOTES_FIELD)

	if (kind === 'login' || kind === 'generic') {
		const el = section(doc, 'Login credentials')
		if (item.login)
			el.appendChild(
				fieldRow(doc, 'Username', item.login, { id: 'detail-login' }),
			)
		el.appendChild(
			fieldRow(doc, kind === 'login' ? 'Password' : 'Value', item.secret, {
				masked: true,
				id: 'detail-secret',
			}),
		)
		sections.appendChild(el)
	}
	if (kind === 'totp') sections.appendChild(totpSection(doc, item.secret))
	if (kind === 'card' || kind === 'identity') {
		const data = parsePayload(item.secret)
		const el = section(doc, kind === 'card' ? 'Card' : 'Identity')
		if (!data) {
			const p = doc.createElement('p')
			p.className = 'error'
			p.textContent = 'Could not read this item'
			el.appendChild(p)
		} else {
			if (kind === 'card' && data.number) {
				const p = doc.createElement('p')
				p.className = 'hint'
				p.textContent = `${cardBrand(data.number) || 'Card'} ending in ${cardLast4(data.number)}`
				el.appendChild(p)
			}
			for (const field of compositeFields(kind)) {
				if (data[field]) {
					el.appendChild(
						fieldRow(doc, COMPOSITE_LABELS[field], String(data[field]), {
							masked: MASKED_COMPOSITE.includes(field),
						}),
					)
				}
			}
		}
		sections.appendChild(el)
	}
	if (kind === 'passkey') {
		const credential = item.passkey
		const el = section(doc, 'Passkey')
		if (!credential) {
			const p = doc.createElement('p')
			p.className = 'error'
			p.textContent = 'Could not read this passkey'
			el.appendChild(p)
		} else {
			const site = credential.rpName
				? `${credential.rpName} (${credential.rpId})`
				: credential.rpId
			el.appendChild(fieldRow(doc, 'Site', site, { copy: false }))
			el.appendChild(
				fieldRow(
					doc,
					'Account',
					credential.userName || credential.userDisplayName || '',
					{ copy: false },
				),
			)
			if (credential.createdAt) {
				el.appendChild(
					fieldRow(
						doc,
						'Created',
						new Date(credential.createdAt).toLocaleString(),
						{ copy: false },
					),
				)
			}
		}
		sections.appendChild(el)
	}
	if (item.url) {
		const el = section(doc, 'Website')
		const row = fieldRow(doc, 'Address', item.url)
		const launch = doc.createElement('button')
		launch.className = 'link'
		launch.textContent = 'Open'
		launch.setAttribute('aria-label', `Open ${item.url}`)
		launch.addEventListener('click', () => {
			try {
				const url = new URL(
					/^https?:\/\//i.test(item.url)
						? item.url
						: 'https://' + item.url,
				)
				chrome.tabs.create({ url: url.href })
			} catch {
				// Not an address a tab can open.
			}
		})
		row.appendChild(launch)
		el.appendChild(row)
		sections.appendChild(el)
	}
	const extra = Object.entries(fields).filter(([name]) => name !== notesKey)
	if (item.additionalFieldsError || extra.length > 0) {
		const el = section(doc, 'Additional fields')
		if (item.additionalFieldsError) {
			const p = doc.createElement('p')
			p.className = 'error'
			p.textContent = 'Could not read additional fields'
			el.appendChild(p)
		}
		for (const [name, value] of extra) {
			el.appendChild(fieldRow(doc, name, String(value), { masked: true }))
		}
		sections.appendChild(el)
	}
	const notes =
		kind === 'note' ? item.secret : notesKey ? String(fields[notesKey]) : ''
	if (notes) {
		const el = section(doc, 'Notes')
		const pre = doc.createElement('p')
		pre.className = 'detail-notes'
		pre.id = 'detail-notes'
		pre.textContent = notes
		el.appendChild(pre)
		sections.appendChild(el)
	}
	const meta = section(doc, 'About this item')
	for (const [label, value] of [
		['Created', item.createdAt],
		['Updated', item.updatedAt],
		['Expires', item.expiresAt],
	]) {
		if (value)
			meta.appendChild(
				fieldRow(doc, label, new Date(value).toLocaleString(), {
					copy: false,
				}),
			)
	}
	sections.appendChild(meta)
}

/**
 * Drop every decrypted value and timer of the detail view.
 *
 * @param {{$: (id: string) => HTMLElement}} ctx The view context.
 * @return {void}
 */
export function clearDetail({ $ }) {
	clearInterval(totpTimer)
	totpTimer = null
	$('detail-sections').replaceChildren()
}
