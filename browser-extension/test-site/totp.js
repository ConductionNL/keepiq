// The test site's authenticator key, as totp-setup.html shows it. The code pages check what was filled against it.
export const SECRET = 'JBSWY3DPEHPK3PXP'

const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'

function secretBytes() {
	let bits = ''
	for (const char of SECRET) bits += BASE32.indexOf(char).toString(2).padStart(5, '0')
	return new Uint8Array(bits.match(/.{8}/g).map((byte) => Number.parseInt(byte, 2)))
}

async function codeAt(counter) {
	const key = await crypto.subtle.importKey('raw', secretBytes(), { name: 'HMAC', hash: 'SHA-1' }, false, ['sign'])
	const message = new DataView(new ArrayBuffer(8))
	message.setUint32(4, counter)
	const hmac = new Uint8Array(await crypto.subtle.sign('HMAC', key, message))
	const offset = hmac[19] & 0x0f
	const binary = ((hmac[offset] & 0x7f) << 24) | (hmac[offset + 1] << 16) | (hmac[offset + 2] << 8) | hmac[offset + 3]
	return String(binary % 1_000_000).padStart(6, '0')
}

/** The current code, or the one before or after it, as most sites accept for clock drift. */
async function isValid(code) {
	const now = Math.floor(Date.now() / 30_000)
	for (const counter of [now - 1, now, now + 1]) if (await codeAt(counter) === code) return true
	return false
}

/**
 * Checks the code in `form`'s `[data-code]` inputs on submit and says whether it was right. The inputs behave as on
 * real sites: a full input moves focus to the next, Backspace in an empty one goes back, a paste spreads over them all.
 */
export function checkCodeForm(form) {
	const inputs = [...form.querySelectorAll('[data-code]')]
	const result = form.querySelector('.status')
	inputs.forEach((input, i) => {
		input.addEventListener('input', () => {
			if (input.value.length >= input.maxLength && inputs[i + 1]) inputs[i + 1].focus()
		})
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Backspace' && input.value === '' && inputs[i - 1]) inputs[i - 1].focus()
		})
		input.addEventListener('paste', (event) => {
			if (inputs.length === 1) return
			event.preventDefault()
			let digits = event.clipboardData.getData('text').replace(/\D/g, '')
			for (const target of inputs.slice(i)) {
				target.value = digits.slice(0, target.maxLength)
				digits = digits.slice(target.maxLength)
			}
		})
	})
	form.addEventListener('submit', async (event) => {
		event.preventDefault()
		const code = inputs.map((input) => input.value).join('')
		const valid = await isValid(code)
		result.textContent = valid ? `Code ${code} accepted. You are signed in.` : `Code "${code}" is wrong for this site's key.`
		result.dataset.result = valid ? 'accepted' : 'wrong'
	})
}
