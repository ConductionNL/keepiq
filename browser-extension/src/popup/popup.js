/**
 * Popup UI — an untrusted view over the background worker. It never holds key
 * material; it renders state and relays user intent (pair / unlock / fill /
 * save / lock) as messages. All decryption happens in the worker.
 */

function send(type, payload) {
	return new Promise((resolve) => {
		chrome.runtime.sendMessage({ type, payload }, (res) => resolve(res || {}))
	})
}

function $(id) {
	return document.getElementById(id)
}

function show(view) {
	for (const id of ['view-pair', 'view-locked', 'view-unlocked']) {
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

async function activeHost() {
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	try {
		return tab ? new URL(tab.url).hostname : ''
	} catch {
		return ''
	}
}

async function renderUnlocked() {
	const host = await activeHost()
	$('active-host').textContent = host
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
				const res = await send('fill', { id: c.id })
				if (res.error) {
					showError('unlock-error', res.error)
					return
				}
				// Auto-copy a matched TOTP code so it is one paste away, then
				// clear it after a short delay (extension-totp-autofill §3).
				if (res.totpCode) {
					await copyWithAutoClear(res.totpCode)
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
		$('save-text').textContent = `Save login for ${capture.host}?`
		$('save-yes').onclick = async () => {
			const res = await send('save-capture', capture)
			if (res.error) showError('unlock-error', res.error)
			$('save-prompt').hidden = true
		}
		$('save-no').onclick = () => {
			$('save-prompt').hidden = true
		}
	}
}

// Clipboard TTL for a copied TOTP code (ms).
const TOTP_CLIPBOARD_TTL = 30000
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
 * Copy a code to the clipboard and clear it after the TTL (no later than the
 * code window would expire).
 *
 * @param {string} code
 * @return {Promise<void>}
 */
async function copyWithAutoClear(code) {
	try {
		await navigator.clipboard.writeText(code)
		setTimeout(() => {
			navigator.clipboard.writeText('').catch(() => {})
		}, TOTP_CLIPBOARD_TTL)
	} catch {
		// Clipboard may be unavailable (no focus); the code is still shown.
	}
}

async function refresh() {
	const state = await send('get-state')
	if (!state.paired) {
		show('view-pair')
	} else if (!state.unlocked) {
		show('view-locked')
	} else {
		show('view-unlocked')
		await renderUnlocked()
	}
}

function wire() {
	$('pair-submit').addEventListener('click', async () => {
		showError('pair-error', '')
		const res = await send('pair', {
			url: $('pair-url').value.trim(),
			user: $('pair-user').value.trim(),
			appPassword: $('pair-app-password').value,
		})
		if (res.error) showError('pair-error', res.error)
		else await refresh()
	})

	$('unlock-submit').addEventListener('click', async () => {
		showError('unlock-error', '')
		const res = await send('unlock', {
			masterPassword: $('unlock-master').value,
		})
		$('unlock-master').value = ''
		if (res.error) showError('unlock-error', res.error)
		else await refresh()
	})

	$('unlock-unpair').addEventListener('click', async () => {
		await send('unpair')
		await refresh()
	})

	$('lock-btn').addEventListener('click', async () => {
		await send('lock')
		await refresh()
	})
}

wire()
refresh()
