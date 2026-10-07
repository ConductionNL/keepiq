/**
 * Popup UI — an untrusted view over the background worker. It never holds key
 * material; it renders state and relays user intent (pair / unlock / fill /
 * save / lock / switch account / settings) as messages. All decryption happens
 * in the worker.
 */

import { platformAuthenticatorAvailable } from '../unlock/ceremony.js'
import { initGenerator } from './generator-view.js'
import { initSend } from './send-view.js'
import { initVault } from './vault-view.js'
import { copyText } from './clipboard.js'
import { folderChoices } from '../lib/vault-index.js'
import {
	canAddAccount,
	DEVICE_STATUS_TEXT,
	renderAccountSwitcher,
	renderIdleChoices,
} from './views.js'

// The last state the worker reported (accounts, active account, settings).
let state = {}
// The pairing form is open to add another account.
let adding = false

// A popped-out popup (its own window) acts on the tab it was opened over.
const params = new URLSearchParams(location.search)
const POPPED_OUT = params.get('popout') === '1'
const PINNED_TAB = Number.parseInt(params.get('tabId') || '', 10)
const PINNED = Number.isInteger(PINNED_TAB) ? PINNED_TAB : undefined
// The messages that act on the page tab carry the pinned tab.
const TAB_MESSAGES = new Set([
	'fill',
	'generator-context',
	'pending-capture',
	'save-capture',
])

function send(type, payload) {
	const body =
		PINNED !== undefined && TAB_MESSAGES.has(type)
			? { ...(payload || {}), tabId: PINNED }
			: payload
	return new Promise((resolve) => {
		chrome.runtime.sendMessage({ type, payload: body }, (res) =>
			resolve(res || {}),
		)
	})
}

function $(id) {
	return document.getElementById(id)
}

function show(view) {
	for (const id of [
		'view-pair',
		'view-locked',
		'view-unlocked',
		'view-settings',
		'view-update',
		'view-signed-out',
		'view-device-approval',
		'view-locked-generator',
	]) {
		$(id).hidden = id !== view
	}
}

function showError(id, message) {
	const el = $(id)
	if (!message) {
		el.hidden = true
		return
	}
	el.textContent = message
	el.hidden = false
}

/**
 * The page tab the popup is about: the pinned one when popped out, else the
 * active tab.
 *
 * @return {Promise<object|null>}
 */
async function activeTab() {
	if (PINNED !== undefined) {
		return chrome.tabs.get(PINNED).catch(() => null)
	}
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	return tab || null
}

/**
 * The host of the page tab, for http and https pages only.
 *
 * @return {Promise<string>}
 */
async function activeHost() {
	const tab = await activeTab()
	try {
		const url = new URL(tab?.url || '')
		return url.protocol === 'http:' || url.protocol === 'https:'
			? url.hostname
			: ''
	} catch {
		return ''
	}
}

/**
 * Fill the save prompt's folder picker from the vault.
 *
 * @spec openspec/specs/extension-autofill-extras/spec.md#requirement-save-a-new-login-into-a-folder
 */
async function fillSaveFolders() {
	const select = $('save-folder')
	select.replaceChildren(select.options[0] || new Option('No folder', ''))
	const { folders = [] } = await send('vault-list')
	for (const { id, label } of folderChoices(folders)) {
		select.appendChild(new Option(label, id))
	}
}

async function renderUnlocked() {
	const host = await activeHost()
	$('active-host').textContent = host
	if (!host) {
		// No website in this tab: nothing to suggest or fill.
		$('candidates').replaceChildren()
		$('no-candidates').textContent = 'Open a website to see its logins.'
		$('no-candidates').hidden = false
		$('totp-block').hidden = true
		return
	}
	$('no-candidates').textContent = 'No matching secrets for this site.'
	const candidates = await send('match', { host })
	const list = $('candidates')
	list.innerHTML = ''
	if (!Array.isArray(candidates) || candidates.length === 0) {
		$('no-candidates').hidden = false
	} else {
		$('no-candidates').hidden = true
		for (const c of candidates) {
			const li = document.createElement('li')
			li.className = 'candidate'
			const btn = document.createElement('button')
			btn.className = 'candidate-fill'
			btn.textContent = c.name + (c.url ? ' — ' + c.url : '')
			btn.addEventListener('click', async () => {
				let res = await send('fill', { id: c.id, accountId: c.accountId })
				if (res.confirm === 'http-page') {
					// The login was saved for https; this page is plain http.
					const yes = window.confirm(
						`${c.name} was saved for a secure (https) site, but this page is not secure. Anyone on the network could read what is filled in. Fill it anyway?`,
					)
					if (!yes) return
					res = await send('fill', {
						id: c.id,
						accountId: c.accountId,
						allowHttp: true,
					})
				}
				if (res.error) {
					showError('unlock-error', res.error)
					return
				}
				// Auto-copy a matched TOTP code so it is one paste away, then
				// clear it after a short delay (extension-totp-autofill §3).
				if (res.totpCode) {
					await copyText(res.totpCode)
				}
				if (!res.filled) {
					// Say so instead of closing as if it worked.
					showError(
						'unlock-error',
						'Keepiq found no login form on this page to fill.',
					)
					return
				}
				window.close()
			})
			li.appendChild(btn)
			list.appendChild(li)
		}
	}

	await renderTotp(host)

	// Surface any pending submit-capture as a save prompt.
	const { capture } = await send('pending-capture')
	if (capture) {
		$('save-prompt').hidden = false
		$('save-text').textContent = capture.account
			? `Save login for ${capture.host} to ${capture.account}?`
			: `Save login for ${capture.host}?`
		// A new login can go into a folder; an update stays where it is.
		$('save-folder-label').hidden = !!capture.update
		$('save-never').hidden = !!capture.update
		if (!capture.update) await fillSaveFolders()
		$('save-yes').onclick = async () => {
			// The worker saves what it holds for this tab; nothing is sent back.
			const res = await send('save-capture', {
				folderId: $('save-folder').value || null,
			})
			if (res.error) showError('unlock-error', res.error)
			$('save-prompt').hidden = true
		}
		$('save-no').onclick = () => {
			$('save-prompt').hidden = true
		}
		$('save-never').onclick = async () => {
			await send('capture-never', {})
			$('save-prompt').hidden = true
		}
	}
}

let totpTimer = null

/**
 * Render the current TOTP code + live countdown for a matched login, or the
 * honest invalid-seed state — never a fabricated code (extension-totp-autofill
 * §2.2/§2.3).
 *
 * @param {string} host
 * @return {Promise<void>}
 */
async function renderTotp(host) {
	if (totpTimer) {
		clearInterval(totpTimer)
		totpTimer = null
	}
	const block = $('totp-block')
	const res = await send('totp-for-host', { host })
	if (res.none || (!res.valid && res.code === undefined && !res.error)) {
		block.hidden = true
		return
	}
	block.hidden = false
	if (!res.valid) {
		$('totp-code').textContent = '—'
		$('totp-count').textContent = 'not a valid authenticator secret'
		return
	}
	let remaining = res.secondsRemaining
	$('totp-code').textContent = res.code
	$('totp-count').textContent = remaining + 's'
	totpTimer = setInterval(async () => {
		remaining -= 1
		if (remaining <= 0) {
			// Window rolled over — recompute the code.
			const next = await send('totp-for-host', { host })
			if (next.valid) {
				$('totp-code').textContent = next.code
				remaining = next.secondsRemaining
			}
		}
		$('totp-count').textContent = Math.max(remaining, 0) + 's'
	}, 1000)
}

/**
 * Open the unlock window for the active account (the OS prompt would close
 * the popup), in unlock or enrol mode.
 *
 * @param {string} mode unlock or enrol.
 * @return {void}
 */
function openUnlockWindow(mode) {
	const url = chrome.runtime.getURL(
		'unlock.html?mode='
			+ mode
			+ '&account='
			+ encodeURIComponent(state.activeAccountId || ''),
	)
	chrome.windows.create({ url, type: 'popup', width: 380, height: 360 })
	window.close()
}

/**
 * Show the fingerprint or face unlock button when this browser has a platform
 * authenticator and the account has an extension passkey enrolled.
 *
 * @return {Promise<void>}
 */
async function renderBiometricUnlock() {
	$('unlock-biometric').hidden = true
	if (!(await platformAuthenticatorAvailable(window))) return
	const options = await send('biometric-options', {
		accountId: state.activeAccountId,
	})
	$('unlock-biometric').hidden = !(options.credentials || []).length
}

// Device approval: poll every three seconds while the popup is open.
const DEVICE_POLL_MS = 3000
let devicePoll = null

function stopDevicePoll() {
	if (devicePoll) {
		clearInterval(devicePoll)
		devicePoll = null
	}
}

/**
 * Show the waiting view for a request and poll until it ends. The worker
 * holds the one-time key; the popup only shows the phrase and the status.
 *
 * @param {{phrase: string}} request The request as the worker reports it.
 * @return {void}
 */
function showDeviceApproval(request) {
	show('view-device-approval')
	showError('device-error', '')
	$('device-phrase').textContent = request.phrase
	$('device-status').textContent = DEVICE_STATUS_TEXT.pending
	stopDevicePoll()
	devicePoll = setInterval(async () => {
		const res = await send('device-approval-poll')
		if (res.error) {
			stopDevicePoll()
			showError('device-error', res.error)
			return
		}
		if (res.status === 'unlocked') {
			stopDevicePoll()
			await refresh()
			return
		}
		$('device-status').textContent =
			DEVICE_STATUS_TEXT[res.status] ?? DEVICE_STATUS_TEXT.pending
		if (res.status !== 'pending') stopDevicePoll()
	}, DEVICE_POLL_MS)
}

/**
 * Offer "Approve from another device" on the locked view when the
 * organisation allows it, or go straight back to an open request.
 *
 * @return {Promise<boolean>} True when an open request took over the view.
 */
async function renderDeviceApprovalOption() {
	$('unlock-device').hidden = true
	const res = await send('device-approval-state')
	if (res.request) {
		showDeviceApproval(res.request)
		return true
	}
	$('unlock-device').hidden = !res.enabled
	return false
}

async function renderSettings() {
	show('view-settings')
	showError('settings-error', '')
	renderIdleChoices($('idle-choices'), state, async (minutes) => {
		const res = await send('set-idle', {
			accountId: state.activeAccountId,
			idleMinutes: minutes,
		})
		if (res.error) showError('settings-error', res.error)
	})
	$('biometric-enrol').hidden = !(await platformAuthenticatorAvailable(window))
	$('pin-set-form').hidden = !!state.pinSet
	$('pin-remove').hidden = !state.pinSet
	await renderClipboardSetting()
	await renderNeverSites()
	await renderShortcut()
	await renderExtensionSettings()
}

/**
 * Show a theme: the system's, or light or dark whatever the system says.
 *
 * @param {string} theme system, light or dark.
 * @spec openspec/specs/extension-list-and-settings/spec.md#requirement-settings-for-autofill-new-items-and-appearance
 */
function applyTheme(theme) {
	if (theme === 'light' || theme === 'dark') {
		document.documentElement.dataset.theme = theme
	} else {
		delete document.documentElement.dataset.theme
	}
}

/**
 * The browser-wide settings: autofill offers, the type of a new item, the
 * theme, the web app and the About text.
 *
 * @spec openspec/specs/extension-list-and-settings/spec.md#requirement-settings-for-autofill-new-items-and-appearance
 */
async function renderExtensionSettings() {
	const settings = await send('extension-settings')
	const list = state.unlocked ? await send('vault-list') : {}
	$('setting-offer-save').checked = settings.offerSave !== false
	$('setting-offer-update').checked = settings.offerUpdate !== false
	$('setting-suggest').checked = settings.suggestPasswords !== false
	const typeSelect = $('setting-default-type')
	typeSelect.replaceChildren()
	const names = (list.types || [])
		.map((t) => t.name)
		.filter((n) => n && n !== 'passkey')
	for (const name of names.length ? names : ['login']) {
		typeSelect.appendChild(
			new Option(name, name, false, name === settings.defaultType),
		)
	}
	$('setting-theme').value = settings.theme || 'system'
	const save = async (patch) => {
		const res = await send('set-extension-settings', patch)
		if (res.error) showError('settings-error', res.error)
		return res
	}
	$('setting-offer-save').onchange = () =>
		save({ offerSave: $('setting-offer-save').checked })
	$('setting-offer-update').onchange = () =>
		save({ offerUpdate: $('setting-offer-update').checked })
	$('setting-suggest').onchange = () =>
		save({ suggestPasswords: $('setting-suggest').checked })
	typeSelect.onchange = () => save({ defaultType: typeSelect.value })
	$('setting-theme').onchange = async () => {
		const res = await save({ theme: $('setting-theme').value })
		applyTheme(res.theme)
	}
	$('settings-open-web').hidden = !list.webAppUrl
	$('settings-open-web').onclick = () =>
		chrome.tabs.create({ url: list.webAppUrl })
	$('settings-notices').onclick = () =>
		chrome.tabs.create({ url: chrome.runtime.getURL('THIRD-PARTY-NOTICES.txt') })
	const version = chrome.runtime.getManifest?.().version || ''
	$('about-text').textContent =
		`Keepiq extension ${version}`
		+ (state.serverVersion
			? `, Keepiq ${state.serverVersion} on your server`
			: '')
		+ '.'
}

/**
 * The sites with no save offer, each with Remove.
 *
 * @spec openspec/specs/extension-autofill-extras/spec.md#requirement-never-offer-to-save-on-a-site
 */
async function renderNeverSites() {
	const { sites = [] } = await send('never-sites')
	const list = $('never-list')
	list.replaceChildren()
	for (const host of sites) {
		const li = document.createElement('li')
		li.className = 'candidate row'
		const name = document.createElement('span')
		name.textContent = host
		const remove = document.createElement('button')
		remove.type = 'button'
		remove.className = 'link'
		remove.textContent = 'Remove'
		remove.setAttribute('aria-label', `Offer to save on ${host} again`)
		remove.addEventListener('click', async () => {
			await send('never-remove', { host })
			await renderNeverSites()
		})
		li.append(name, remove)
		list.appendChild(li)
	}
	$('never-empty').hidden = sites.length > 0
}

/**
 * The keyboard shortcut that fills a login, as the browser set it.
 *
 * @spec openspec/specs/extension-autofill-extras/spec.md#requirement-fill-from-the-context-menu-and-a-shortcut
 */
async function renderShortcut() {
	let shortcut = ''
	try {
		const commands = (await chrome.commands?.getAll?.()) || []
		shortcut = commands.find((c) => c.name === 'fill-login')?.shortcut || ''
	} catch {
		shortcut = ''
	}
	$('shortcut-text').textContent = shortcut
		? `Press ${shortcut} on a login page to fill its login. Change the shortcut in your browser's extension settings.`
		: "Set a keyboard shortcut for filling a login in your browser's extension settings."
}

/**
 * The clipboard delay picker: how long a copy stays on the clipboard. It
 * applies to every account in this browser.
 *
 * @spec openspec/specs/extension-clipboard/spec.md#requirement-every-copy-is-cleared-after-a-delay-the-user-sets
 */
async function renderClipboardSetting() {
	const { seconds, choices = [] } = await send('clipboard-settings')
	const select = $('clipboard-clear')
	select.replaceChildren()
	for (const value of choices) {
		const option = document.createElement('option')
		option.value = String(value)
		option.textContent =
			value === 0
				? 'Never'
				: value < 60
					? `${value} seconds`
					: value === 60
						? '1 minute'
						: `${value / 60} minutes`
		option.selected = value === seconds
		select.appendChild(option)
	}
	select.onchange = async () => {
		const res = await send('set-clipboard-clear', {
			seconds: Number(select.value),
		})
		if (res.error) showError('settings-error', res.error)
	}
}

async function refresh() {
	state = await send('get-state')
	const paired = !!state.paired
	$('account-bar').hidden = !paired || adding
	if (paired) {
		renderAccountSwitcher($('account-select'), state)
		$('account-initials').textContent = initialsOf(
			(state.accounts || []).find((a) => a.id === state.activeAccountId),
		)
		$('account-add').hidden = !canAddAccount(state)
	}
	$('pair-cancel').hidden = !paired
	if (!paired || adding) {
		show('view-pair')
	} else if (state.serverOutdated) {
		// Nothing else works against an older server: say so, ask nothing.
		show('view-update')
	} else if (state.loggedOut || state.insecure) {
		renderSignedOut()
	} else if (!state.unlocked) {
		show('view-locked')
		$('pin-block').hidden = !state.pinSet
		;(state.pinSet ? $('unlock-pin') : $('unlock-master')).focus()
		if (!(await renderDeviceApprovalOption())) await renderBiometricUnlock()
	} else {
		show('view-unlocked')
		await selectTab(await lastTab())
	}
}

/**
 * Up to two initials for an account, from its label or user name.
 *
 * @param {object|undefined} account The account.
 * @return {string}
 * @spec openspec/specs/extension-unlock-and-accounts/spec.md#requirement-lock-and-log-out-per-account-or-all
 */
export function initialsOf(account) {
	const name = String(account?.label || account?.user || '').trim()
	const parts = name.split(/[\s._@-]+/).filter(Boolean)
	const letters = parts.length > 1 ? parts[0][0] + parts[1][0] : name.slice(0, 2)
	return letters.toUpperCase()
}

/**
 * The signed-out view: the server refused the account's app password, or the
 * account was paired over http and cannot be used.
 *
 * @spec openspec/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 */
function renderSignedOut() {
	show('view-signed-out')
	showError('relogin-error', '')
	$('relogin-app-password').value = ''
	$('relogin-form').hidden = !state.loggedOut || state.insecure
	$('signed-out-text').textContent = state.insecure
		? 'This account was connected over http. Keepiq now needs https, so your app password is never sent in clear. Disconnect it and connect again over https.'
		: state.loggedOutReason === 'logout'
			? 'You logged out of this account. Create a new app password in Nextcloud and enter it here to sign in again.'
			: 'Keepiq refused the app password of this account. It was revoked or changed in Nextcloud. Create a new app password in Nextcloud and enter it here.'
}

// --- tabs: This site, Vault, Generator, Send ---

const TABS = ['site', 'vault', 'generator', 'send']
const LAST_TAB_KEY = 'popup:lastTab'

/**
 * The tab the popup was last on in this browser session, or This site.
 *
 * @return {Promise<string>}
 */
async function lastTab() {
	try {
		const area = tabArea()
		const saved = (await area.get(LAST_TAB_KEY))[LAST_TAB_KEY]
		return TABS.includes(saved) ? saved : 'site'
	} catch {
		return 'site'
	}
}

/**
 * Where the last tab is kept: session storage, or local storage in a
 * browser without it (the worker clears it on lock either way).
 *
 * @return {object}
 * @spec openspec/specs/extension-small-items/spec.md#requirement-the-popup-keeps-its-place
 */
function tabArea() {
	return chrome.storage.session || chrome.storage.local
}

/**
 * Remember the tab for this browser session only.
 *
 * @param {string} name The tab.
 */
function rememberTab(name) {
	try {
		tabArea()
			.set({ [LAST_TAB_KEY]: name })
			.catch(() => {})
	} catch {
		// No storage at all: the popup opens on This site.
	}
}
let generatorView = null
let vaultView = null
let sendView = null

/**
 * Show one tab and, unless returning to it, open its view.
 *
 * @param {string} name One of TABS.
 * @param {object} [arg] Passed to the view's open (Send prefill, Generator pick mode).
 * @param {{reopen?: boolean}} [how] reopen false: switch without reloading the view.
 * @return {Promise<void>}
 */
async function selectTab(name, arg, { reopen = true } = {}) {
	for (const tab of TABS) {
		const selected = tab === name
		$('tab-' + tab).setAttribute('aria-selected', selected ? 'true' : 'false')
		$('panel-' + tab).hidden = !selected
	}
	rememberTab(name)
	if (!reopen) return
	if (name === 'site') await renderUnlocked()
	if (name === 'vault') await vaultView.open()
	if (name === 'generator') await generatorView.open(arg)
	if (name === 'send') await sendView.open(arg)
}

/**
 * Open the Generator in pick mode for the item form, and come back to the
 * form (with the user's other input intact) when a value is picked.
 *
 * @param {string} kind password or username.
 * @param {(value: string) => void} onPick Puts the value in the form.
 * @return {Promise<void>}
 */
function pickGenerated(kind, onPick) {
	return selectTab('generator', {
		kind,
		onPick: (value) => {
			onPick(value)
			selectTab('vault', undefined, { reopen: false })
		},
	})
}

// The Generator while locked: its panel moves into the locked view and back.
function openLockedGenerator() {
	$('locked-generator-slot').appendChild($('panel-generator'))
	$('panel-generator').hidden = false
	show('view-locked-generator')
	return generatorView.open()
}

function closeLockedGenerator() {
	$('panel-send').before($('panel-generator'))
	$('panel-generator').hidden = true
	return refresh()
}

function wireTabs() {
	const ctx = { $, send, showError }
	generatorView = initGenerator(ctx)
	sendView = initSend(ctx)
	vaultView = initVault({
		...ctx,
		currentSite: async () => {
			const tab = await activeTab()
			try {
				const url = new URL(tab?.url || '')
				return /^https?:$/.test(url.protocol) ? url.origin : ''
			} catch {
				return ''
			}
		},
		pickGenerated,
		sendItem: (item) => selectTab('send', item),
	})
	for (const tab of TABS) {
		$('tab-' + tab).addEventListener('click', () => {
			// Leaving an item form with changes asks first.
			const onVault = $('tab-vault').getAttribute('aria-selected') === 'true'
			if (onVault && tab !== 'vault' && !vaultView.canLeave()) return
			selectTab(tab)
		})
	}
	$('locked-generate').addEventListener('click', openLockedGenerator)
	$('locked-generator-back').addEventListener('click', closeLockedGenerator)
}

function wire() {
	wireTabs()
	$('pair-submit').addEventListener('click', async () => {
		showError('pair-error', '')
		const res = await send('pair', {
			url: $('pair-url').value.trim(),
			user: $('pair-user').value.trim(),
			appPassword: $('pair-app-password').value,
		})
		if (res.error) {
			showError('pair-error', res.error)
			return
		}
		adding = false
		for (const id of ['pair-url', 'pair-user', 'pair-app-password']) {
			$(id).value = ''
		}
		await refresh()
	})

	$('pair-cancel').addEventListener('click', async () => {
		adding = false
		showError('pair-error', '')
		await refresh()
	})

	$('account-add').addEventListener('click', async () => {
		adding = true
		await refresh()
	})

	$('account-select').addEventListener('change', async (event) => {
		await send('switch-account', { accountId: event.target.value })
		await refresh()
	})

	$('unlock-biometric').addEventListener('click', () => openUnlockWindow('unlock'))
	$('biometric-enrol').addEventListener('click', () => openUnlockWindow('enrol'))
	$('settings-btn').addEventListener('click', () => renderSettings())
	$('tab-settings').addEventListener('click', () => renderSettings())
	$('popout-btn').hidden = POPPED_OUT
	if (POPPED_OUT) document.body.classList.add('popped-out')
	$('popout-btn').addEventListener('click', async () => {
		const tab = await activeTab()
		const query = 'popout=1' + (tab?.id !== undefined ? '&tabId=' + tab.id : '')
		chrome.windows.create({
			url: chrome.runtime.getURL('popup.html?' + query),
			type: 'popup',
			width: 380,
			height: 630,
		})
		window.close()
	})
	$('settings-back').addEventListener('click', () => refresh())
	$('relogin-form').addEventListener('submit', async (event) => {
		event.preventDefault()
		showError('relogin-error', '')
		const res = await send('relogin', {
			accountId: state.activeAccountId,
			appPassword: $('relogin-app-password').value,
		})
		$('relogin-app-password').value = ''
		if (res.error) {
			showError('relogin-error', res.error)
			return
		}
		await refresh()
	})
	$('signed-out-disconnect').addEventListener('click', async () => {
		if (!confirmDisconnect()) return
		await send('unpair', { accountId: state.activeAccountId })
		await refresh()
	})

	$('settings-unpair').addEventListener('click', async () => {
		if (!confirmDisconnect()) return
		await send('unpair', { accountId: state.activeAccountId })
		await refresh()
	})

	$('unlock-submit').addEventListener('click', async () => {
		showError('unlock-error', '')
		const res = await send('unlock', {
			masterPassword: $('unlock-master').value,
		})
		$('unlock-master').type = 'password'
		$('unlock-show').textContent = 'Show'
		$('unlock-show').setAttribute('aria-pressed', 'false')
		$('unlock-master').value = ''
		if (res.error) showError('unlock-error', res.error)
		else await refresh()
	})

	$('unlock-device').addEventListener('click', async () => {
		showError('unlock-error', '')
		const res = await send('device-approval-start')
		if (res.error) {
			showError('unlock-error', res.error)
			return
		}
		showDeviceApproval(res.request)
	})

	$('device-cancel').addEventListener('click', async () => {
		stopDevicePoll()
		await send('device-approval-cancel')
		await refresh()
	})

	$('unlock-unpair').addEventListener('click', async () => {
		if (!confirmDisconnect()) return
		await send('unpair', { accountId: state.activeAccountId })
		await refresh()
	})

	// Show or hide the master password while typing it.
	$('unlock-show').addEventListener('click', () => {
		const shown = $('unlock-master').type === 'text'
		$('unlock-master').type = shown ? 'password' : 'text'
		$('unlock-show').textContent = shown ? 'Show' : 'Hide'
		$('unlock-show').setAttribute('aria-pressed', shown ? 'false' : 'true')
		$('unlock-master').focus()
	})
	$('unlock-master').addEventListener('keydown', (event) => {
		if (event.key === 'Enter') $('unlock-submit').click()
	})
	$('settings-logout').addEventListener('click', async () => {
		if (
			!window.confirm(
				'Log out of this account? Its app password is deleted in Nextcloud, and you need a new one to sign in again.',
			)
		)
			return
		await send('logout', { accountId: state.activeAccountId })
		await refresh()
	})
	$('settings-logout-all').addEventListener('click', async () => {
		if (
			!window.confirm(
				'Log out of all accounts? Their app passwords are deleted in Nextcloud, and you need new ones to sign in again.',
			)
		)
			return
		await send('logout', { all: true })
		await refresh()
	})
	$('unlock-pin-submit').addEventListener('click', async () => {
		showError('unlock-error', '')
		const res = await send('pin-unlock', { pin: $('unlock-pin').value })
		$('unlock-pin').value = ''
		if (res.error) showError('unlock-error', res.error)
		await refresh()
	})
	$('unlock-pin').addEventListener('keydown', (event) => {
		if (event.key === 'Enter') $('unlock-pin-submit').click()
	})
	$('pin-set').addEventListener('click', async () => {
		showError('settings-error', '')
		const res = await send('pin-set', {
			masterPassword: $('pin-master').value,
			pin: $('pin-new').value,
		})
		$('pin-master').value = ''
		$('pin-new').value = ''
		if (res.error) {
			showError('settings-error', res.error)
			return
		}
		state = await send('get-state')
		await renderSettings()
	})
	$('pin-remove').addEventListener('click', async () => {
		await send('pin-remove')
		state = await send('get-state')
		await renderSettings()
	})
	$('settings-lock-all').addEventListener('click', async () => {
		await send('lock', {})
		await refresh()
	})

	$('lock-btn').addEventListener('click', async () => {
		// The account on screen; the others stay as they are.
		await send('lock', { accountId: state.activeAccountId })
		await refresh()
	})
}

/**
 * The worker locked an account. When it is the one on screen, drop what the
 * popup shows of the vault at once and show the lock screen.
 *
 * @param {object} msg The worker's message.
 * @spec openspec/specs/extension-lock/spec.md#requirement-the-popup-forgets-the-vault-when-it-locks
 */
function onWorkerMessage(msg) {
	if (msg?.type !== 'keepiq-locked') return
	if (msg.accountId && msg.accountId !== state.activeAccountId) return
	vaultView?.forget()
	$('candidates').replaceChildren()
	$('totp-code').textContent = ''
	$('totp-block').hidden = true
	$('gen-output').textContent = ''
	$('view-unlocked').hidden = true
	refresh()
}

/**
 * Ask before disconnecting: it deletes the account's app password in
 * Nextcloud and its data in this browser.
 *
 * @return {boolean} Whether the user confirmed.
 * @spec openspec/specs/extension-lock/spec.md#requirement-lock-locks-the-account-on-screen-and-disconnect-asks-first
 */
function confirmDisconnect() {
	const account = (state.accounts || []).find(
		(a) => a.id === state.activeAccountId,
	)
	const name = account
		? account.label || account.user + '@' + account.host
		: 'this account'
	return window.confirm(
		`Disconnect ${name}? Its app password is deleted in Nextcloud and its data is removed from this browser.`,
	)
}

chrome.runtime.onMessage?.addListener(onWorkerMessage)
send('extension-settings').then((settings) => applyTheme(settings?.theme))
wire()
refresh()
