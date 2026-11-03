// @vitest-environment happy-dom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { generate, type GenerateResult } from '@/src/generator/generate'
import { defaultOptions, type GeneratorKind, type GeneratorOptions, type GeneratorPolicy } from '@/src/generator/options'
import { setLocale } from '@/src/testing/i18n'
import { SAVE_DELAY_MS } from '../hooks/useGeneratorOptions'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, fakeClipboard, sentKinds, sentOf } from '../testing'
import { Generator, type GeneratorPick } from './Generator'

vi.mock('@/src/generator/generate', async (original) => ({ ...await original<typeof import('@/src/generator/generate')>(), generate: vi.fn() }))

let counter = 0
beforeEach(() => {
	counter = 0
	vi.mocked(generate).mockImplementation(async (kind) => ({ ok: true, value: `${kind}-${++counter}` }))
})
afterEach(() => {
	cleanup()
	document.body.replaceChildren()
})

const policy = (overrides: Partial<GeneratorPolicy> = {}): GeneratorPolicy => ({
	minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true, ...overrides,
})

function options(change: (options: GeneratorOptions) => void = () => {}): GeneratorOptions {
	const value = defaultOptions()
	change(value)
	return value
}

/** Captures the popup's runtime listeners so a test can play the background's broadcasts. */
function broadcasts() {
	const listeners = new Set<(message: unknown) => unknown>()
	vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.add(listener as never))
	vi.spyOn(browser.runtime.onMessage, 'removeListener').mockImplementation((listener) => void listeners.delete(listener as never))
	return (message: unknown) => act(() => listeners.forEach((listener) => listener(message)))
}

function renderGenerator(background: Parameters<typeof fakeBackground>[0] = {}, props: { pick?: GeneratorPick; onOpenHistory?: () => void } = {}) {
	const sent = fakeBackground(background)
	const toast = vi.fn()
	const user = userEvent.setup()
	// Where Shell puts a page's actions, as in pick mode.
	const footer = document.body.appendChild(document.createElement('div'))
	render(<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast, footer }}><Generator {...props} /></ShellContext.Provider>)
	return { sent, toast, user, footer }
}

/** Waits until the output box shows `text`; the value is split into a span per character. */
const shows = (text: string | RegExp) => waitFor(() => {
	const value = document.querySelector('.generated__value')?.textContent
	if (typeof text === 'string') expect(value).toBe(text)
	else expect(value).toMatch(text)
})
const tab = (name: string) => screen.getByRole('tab', { name })
const toggle = (name: string) => screen.getByRole<HTMLInputElement>('checkbox', { name })
const slider = () => screen.getByRole<HTMLInputElement>('slider', { name: 'Length' })
const status = () => document.querySelector('.generator__announcer[aria-live="polite"]')!.textContent
const lastCall = () => vi.mocked(generate).mock.lastCall!
const added = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'generator.history.add').map((message) => message.entry)
const lastSaved = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'generator.saveOptions').at(-1)

/** Answers `generate` calls only when the test resolves them, in order. */
function heldGenerate() {
	const pending: Array<(result: GenerateResult) => void> = []
	vi.mocked(generate).mockImplementation(() => new Promise((resolve) => pending.push(resolve)))
	return async (value: string) => {
		await waitFor(() => expect(pending.length).toBeGreaterThan(0))
		await act(() => pending.shift()!({ ok: true, value }))
	}
}

describe('Generator', () => {
	it('opens on Password and generates without a click', async () => {
		renderGenerator()
		await shows('password-1')
		expect(screen.getAllByRole('tab').map((t) => t.textContent)).toEqual(['Password', 'Passphrase', 'Username'])
		expect(tab('Password').getAttribute('aria-selected')).toBe('true')
		expect(screen.getByRole('tabpanel').getAttribute('aria-labelledby')).toBe(tab('Password').id)
		expect(lastCall()).toEqual(['password', expect.objectContaining({ tab: 'password' }), null, { website: 'github.com', accountEmail: 'alice@example.org' }])
	})

	it('regenerates on an option change and saves it, without recording or announcing it', async () => {
		const { sent, user } = renderGenerator()
		await shows('password-1')
		const length = screen.getByRole('spinbutton', { name: 'Length in characters' })
		await user.clear(length)
		await user.type(length, '20')
		await waitFor(() => expect(lastCall()[1].password.length).toBe(20))
		await shows(`password-${counter}`)
		expect(slider().value).toBe('20')
		await waitFor(() => expect(lastSaved(sent)).toMatchObject({ accountId: 'a1', options: { password: { length: 20 } } }))
		expect(added(sent)).toEqual([])
		expect(status()).toBe('')
	})

	it('saves a toggle at once, and the range steps once they settle', async () => {
		const { sent, user } = renderGenerator()
		await shows('password-1')
		await user.click(toggle('Avoid ambiguous characters'))
		expect(lastSaved(sent)).toMatchObject({ options: { password: { avoidAmbiguous: false } } })
		const saves = sentOf(sent, 'generator.saveOptions').length
		for (const value of ['15', '16', '17']) fireEvent.change(slider(), { target: { value } })
		await waitFor(() => expect(lastCall()[1].password.length).toBe(17))
		expect(sentOf(sent, 'generator.saveOptions')).toHaveLength(saves)
		await waitFor(() => expect(sentOf(sent, 'generator.saveOptions')).toHaveLength(saves + 1), { timeout: SAVE_DELAY_MS * 4 })
		expect(lastSaved(sent)).toMatchObject({ options: { password: { length: 17 } } })
		expect(added(sent)).toEqual([])
	})

	it('saves a pending range step when the page is hidden', async () => {
		const { sent } = renderGenerator()
		await shows('password-1')
		fireEvent.change(slider(), { target: { value: '30' } })
		window.dispatchEvent(new Event('pagehide'))
		expect(lastSaved(sent)).toMatchObject({ options: { password: { length: 30 } } })
	})

	it('opens on the sub-tab the account last used', async () => {
		renderGenerator({ generator: { options: options((o) => (o.tab = 'username')) } })
		await shows('username-1')
		expect(tab('Username').getAttribute('aria-selected')).toBe('true')
	})

	it('moves between sub-tabs with the arrow keys, saving the tab, recording the value it leaves and announcing the new one', async () => {
		const { sent, user } = renderGenerator()
		await shows('password-1')
		tab('Password').focus()
		await user.keyboard('{ArrowRight}')
		expect(tab('Passphrase').getAttribute('aria-selected')).toBe('true')
		expect(document.activeElement).toBe(tab('Passphrase'))
		await shows('passphrase-2')
		expect(status()).toBe('passphrase-2')
		await user.keyboard('{End}')
		expect(tab('Username').getAttribute('aria-selected')).toBe('true')
		await waitFor(() => expect(added(sent)).toEqual([{ value: 'password-1', kind: 'password' }, { value: 'passphrase-2', kind: 'passphrase' }]))
		await waitFor(() => expect(lastSaved(sent)).toMatchObject({ options: { tab: 'username' } }))
	})

	it('never shows, copies or records the previous sub-tab\'s value while the new one generates', async () => {
		const answer = heldGenerate()
		const { sent, user } = renderGenerator()
		await answer('password-1')
		await shows('password-1')
		await user.click(tab('Passphrase'))
		expect(document.querySelector('.generated__value')!.textContent).not.toContain('password-1')
		expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Copy passphrase' }).disabled).toBe(true)
		await answer('passphrase-2')
		await shows('passphrase-2')
		expect(added(sent)).toEqual([{ value: 'password-1', kind: 'password' }])
	})

	it('records and announces the new value on Regenerate, and a copied one once', async () => {
		const { sent, toast, user } = renderGenerator()
		// After `userEvent.setup`, which installs a clipboard of its own.
		const writeText = fakeClipboard()
		await shows('password-1')
		await user.click(screen.getByRole('button', { name: 'Regenerate' }))
		await shows('password-2')
		expect(status()).toBe('password-2')
		await user.click(screen.getByRole('button', { name: 'Copy password' }))
		expect(writeText).toHaveBeenCalledWith('password-2')
		expect(toast).toHaveBeenCalledWith('Password copied')
		await waitFor(() => expect(sentOf(sent, 'clipboard.copied')).toHaveLength(1))
		expect(added(sent)).toEqual([{ value: 'password-2', kind: 'password' }])
	})

	it('records nothing for a Regenerate that an option change overtook', async () => {
		const answer = heldGenerate()
		const { sent, user } = renderGenerator()
		await answer('password-1')
		await user.click(screen.getByRole('button', { name: 'Regenerate' }))
		await user.click(toggle('Avoid ambiguous characters'))
		await answer('regenerated')
		await answer('after-option')
		await shows('after-option')
		expect(added(sent)).toEqual([])
		expect(status()).toBe('')
	})

	it('colours digits and symbols apart from letters', async () => {
		vi.mocked(generate).mockResolvedValue({ ok: true, value: 'aB3$' })
		renderGenerator()
		await shows('aB3$')
		const value = document.querySelector('.gen-value')!
		expect([...value.children].map((char) => [char.textContent, char.className])).toEqual([['a', ''], ['B', ''], ['3', 'gen-digit'], ['$', 'gen-symbol']])
	})

	it('keeps the last enabled class on, focusable, and the others available', async () => {
		const { sent, user } = renderGenerator({ generator: { options: options((o) => Object.assign(o.password, { includeUppercase: false, includeDigits: false })) } })
		await shows('password-1')
		const saves = sentOf(sent, 'generator.saveOptions').length
		expect(toggle('Lowercase (a-z)').getAttribute('aria-disabled')).toBe('true')
		expect(document.getElementById(toggle('Lowercase (a-z)').getAttribute('aria-describedby')!)!.textContent).toBe('At least one type stays on')
		await user.click(toggle('Lowercase (a-z)'))
		expect(toggle('Lowercase (a-z)').checked).toBe(true)
		expect(sentOf(sent, 'generator.saveOptions')).toHaveLength(saves)
		expect(toggle('Uppercase (A-Z)').getAttribute('aria-disabled')).toBeNull()
		expect(toggle('Numbers (0-9)').getAttribute('aria-disabled')).toBeNull()
	})

	it('shows and generates the length raised to the sum of the minimums', async () => {
		renderGenerator({ generator: { options: options((o) => Object.assign(o.password, { length: 8, includeSpecialCharacters: true, minDigits: 6, minSpecial: 6 })) } })
		await shows('password-1')
		expect(slider().value).toBe('14')
		expect(screen.getByRole<HTMLInputElement>('spinbutton', { name: 'Length in characters' }).min).toBe('14')
		expect(lastCall()[1].password.length).toBe(14)
	})

	it('locks what the policy sets, keeping the stored values', async () => {
		const { sent, user } = renderGenerator({ generator: { options: options((o) => (o.password.minSpecial = 0)), policy: policy({ minLength: 20, requireSymbol: true }) } })
		await shows('password-1')
		expect([slider().value, slider().min]).toEqual(['20', '20'])
		const number = screen.getByRole<HTMLInputElement>('spinbutton', { name: 'Length in characters' })
		for (const input of [slider(), number]) {
			expect(document.getElementById(input.getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
		}
		const special = toggle('Special characters, such as !@#$%^&*')
		expect([special.checked, special.getAttribute('aria-disabled')]).toEqual([true, 'true'])
		expect(document.getElementById(special.getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
		const minSpecial = screen.getByRole<HTMLInputElement>('spinbutton', { name: 'Minimum special' })
		expect([minSpecial.value, minSpecial.min]).toEqual(['1', '1'])
		expect(lastCall()[1].password).toMatchObject({ length: 20, includeSpecialCharacters: true, minSpecial: 1 })
		expect(lastCall()[2]).toEqual(policy({ minLength: 20, requireSymbol: true }))
		await user.click(toggle('Avoid ambiguous characters'))
		expect(lastSaved(sent)).toMatchObject({ options: { password: { length: 14, includeSpecialCharacters: false, minSpecial: 0, avoidAmbiguous: false } } })
	})

	it('locks nothing without a policy', async () => {
		renderGenerator()
		await shows('password-1')
		expect(screen.queryByText('Set by your organisation')).toBeNull()
		expect(screen.getAllByRole('checkbox').every((input) => input.getAttribute('aria-disabled') === null)).toBe(true)
	})

	it('shows the lock reason once for two locked classes, both pointing at it', async () => {
		renderGenerator({ generator: { policy: policy({ requireUpper: true, requireSymbol: true }) } })
		await shows('password-1')
		const upper = toggle('Uppercase (A-Z)')
		const special = toggle('Special characters, such as !@#$%^&*')
		expect([upper.getAttribute('aria-disabled'), special.getAttribute('aria-disabled')]).toEqual(['true', 'true'])
		expect(upper.getAttribute('aria-describedby')).toBe(special.getAttribute('aria-describedby'))
		const group = screen.getByRole('group', { name: 'Character types' }).parentElement!
		expect(within(group).getAllByText('Set by your organisation')).toHaveLength(1)
		expect(document.getElementById(upper.getAttribute('aria-describedby')!)!.textContent).toBe('Set by your organisation')
	})

	it('names the character type checkboxes as a group, showing their short labels', async () => {
		renderGenerator()
		await shows('password-1')
		const group = screen.getByRole('group', { name: 'Character types' })
		const names = within(group).getAllByRole('checkbox').map((input) => input.getAttribute('aria-label'))
		expect(names).toEqual(['Uppercase (A-Z)', 'Lowercase (a-z)', 'Numbers (0-9)', 'Special characters, such as !@#$%^&*'])
		expect(group.textContent).toBe('A-Za-z0-9!@#$%^&*')
	})

	it('brings back the user\'s own value when a sync relaxes the policy', async () => {
		const send = broadcasts()
		const background = { generator: { options: options((o) => (o.password.length = 12)), policy: policy({ minLength: 16 }) as GeneratorPolicy | null } }
		const { sent } = renderGenerator(background)
		await shows('password-1')
		expect(slider().value).toBe('16')
		background.generator.policy = policy({ minLength: 8 })
		send({ kind: 'vault.changed', sync: {} })
		await waitFor(() => expect(slider().value).toBe('12'))
		expect(screen.queryByText('Set by your organisation')).toBeNull()
		expect(sentOf(sent, 'generator.saveOptions')).toEqual([])
	})

	it('falls back to Password and disables Passphrase when the policy turns it off', async () => {
		renderGenerator({ generator: { options: options((o) => (o.tab = 'passphrase')), policy: policy({ allowPassphrase: false }) } })
		await shows('password-1')
		expect(tab('Password').getAttribute('aria-selected')).toBe('true')
		expect(tab('Passphrase').getAttribute('aria-disabled')).toBe('true')
		expect(document.getElementById(tab('Passphrase').getAttribute('aria-describedby')!)!.textContent).toBe('Turned off by your organisation')
	})

	it.each([
		['needs_email', 'Enter an email address'],
		['needs_domain', 'Enter a domain'],
		['wordlist_unavailable', 'Wordlist unavailable'],
	] as const)('words the %s failure and offers no copy', async (failure, text) => {
		vi.mocked(generate).mockResolvedValue({ ok: false, failure })
		renderGenerator()
		expect(await screen.findByText(text)).toBeTruthy()
		expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Copy password' }).disabled).toBe(true)
	})

	it('still generates passwords when the wordlist fails', async () => {
		vi.mocked(generate).mockImplementation(async (kind) => (kind === 'password' ? { ok: true, value: 'password-ok' } : { ok: false, failure: 'wordlist_unavailable' }))
		const { user } = renderGenerator({ generator: { options: options((o) => (o.tab = 'passphrase')) } })
		expect(await screen.findByText('Wordlist unavailable')).toBeTruthy()
		await user.click(tab('Password'))
		await shows('password-ok')
	})

	it('shows the passphrase defaults, saves a change and accepts an empty separator', async () => {
		const { sent, user } = renderGenerator({ generator: { options: options((o) => (o.tab = 'passphrase')) } })
		await shows('passphrase-1')
		const separator = screen.getByRole<HTMLInputElement>('textbox', { name: 'Word separator' })
		expect(screen.getByRole<HTMLInputElement>('spinbutton', { name: 'Number of words' }).value).toBe('5')
		expect(separator.value).toBe('-')
		expect([toggle('Capitalize').checked, toggle('Include number').checked]).toEqual([false, false])
		await user.click(toggle('Include number'))
		expect(lastSaved(sent)).toMatchObject({ options: { passphrase: { includeNumber: true } } })
		await user.type(separator, '_')
		expect(separator.value).toBe('_')
		await user.clear(separator)
		expect(separator.value).toBe('')
		expect(lastSaved(sent)).toMatchObject({ options: { passphrase: { separator: '' } } })
		await waitFor(() => expect(lastCall()[1].passphrase.separator).toBe(''))
	})

	it('shows the username defaults', async () => {
		renderGenerator({ generator: { options: options((o) => (o.tab = 'username')) } })
		await shows('username-1')
		expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Type' }).value).toBe('word')
		expect([toggle('Capitalize').checked, toggle('Include number').checked]).toEqual([true, true])
		expect(screen.queryByRole('textbox')).toBeNull()
	})

	it('seeds the plus-addressed email from the account without storing it', async () => {
		const { sent, user } = renderGenerator({ generator: { options: options((o) => Object.assign(o, { tab: 'username' as GeneratorKind })) } })
		await shows('username-1')
		await user.selectOptions(screen.getByRole('combobox', { name: 'Type' }), 'Plus addressed email')
		expect(screen.getByRole<HTMLInputElement>('textbox', { name: 'Email' }).value).toBe('alice@example.org')
		expect(lastCall()[1].username).toMatchObject({ type: 'plus', email: 'alice@example.org', mode: 'random' })
		expect(lastSaved(sent)).toMatchObject({ options: { username: { type: 'plus', email: '' } } })
	})

	it('leaves the email empty with a placeholder for an account without one, and keeps a cleared one empty', async () => {
		const { user } = renderGenerator({ generator: { accountEmail: null, options: options((o) => Object.assign(o, { tab: 'username' }, { username: { ...o.username, type: 'plus' } })) } })
		await shows('username-1')
		const email = screen.getByRole<HTMLInputElement>('textbox', { name: 'Email' })
		expect([email.value, email.placeholder]).toEqual(['', 'name@example.com'])
		await user.type(email, 'bob@example.com')
		await user.clear(email)
		expect(email.value).toBe('')
		expect(lastCall()[3]).toEqual({ website: 'github.com', accountEmail: null })
	})

	it('offers Website name with the bare hostname of the tab', async () => {
		const { user } = renderGenerator({ tabUrl: 'https://login.example.org:8443/', generator: { options: options((o) => Object.assign(o, { tab: 'username' }, { username: { ...o.username, type: 'catchall' } })) } })
		await shows('username-1')
		await user.click(screen.getByRole('radio', { name: 'Website name' }))
		await user.type(screen.getByRole('textbox', { name: 'Domain' }), 'example.com')
		await waitFor(() => expect(lastCall()[1].username).toMatchObject({ mode: 'website', domain: 'example.com' }))
		expect(lastCall()[3].website).toBe('login.example.org')
	})

	it('disables Website name with "No website detected" on a browser page, and uses Random', async () => {
		renderGenerator({ tabUrl: 'chrome://settings', generator: { options: options((o) => Object.assign(o, { tab: 'username' }, { username: { ...o.username, type: 'plus', mode: 'website' } })) } })
		await shows('username-1')
		const website = screen.getByRole<HTMLInputElement>('radio', { name: 'Website name' })
		expect([website.disabled, website.getAttribute('aria-disabled')]).toEqual([false, 'true'])
		expect(document.getElementById(website.getAttribute('aria-describedby')!)!.textContent).toBe('No website detected')
		expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Random' }).checked).toBe(true)
		expect(lastCall()[1].username.mode).toBe('random')
		expect(lastCall()[3].website).toBeNull()
	})

	it('keeps an unavailable Website name focusable but ignores choosing it', async () => {
		const { sent, user } = renderGenerator({ tabUrl: 'chrome://settings', generator: { options: options((o) => Object.assign(o, { tab: 'username' }, { username: { ...o.username, type: 'plus' } })) } })
		await shows('username-1')
		const saves = sentOf(sent, 'generator.saveOptions').length
		const website = screen.getByRole<HTMLInputElement>('radio', { name: 'Website name' })
		await user.click(website)
		expect(document.activeElement).toBe(website)
		expect(website.checked).toBe(false)
		expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Random' }).checked).toBe(true)
		expect(sentOf(sent, 'generator.saveOptions')).toHaveLength(saves)
	})

	it('offers Password and Passphrase in pick mode for a password, with Use in the page footer', async () => {
		const onUse = vi.fn()
		const { sent, user, footer } = renderGenerator({}, { pick: { target: 'password', onUse } })
		await shows('password-1')
		expect(screen.getAllByRole('tab').map((t) => t.textContent)).toEqual(['Password', 'Passphrase'])
		await user.click(within(footer).getByRole('button', { name: 'Use this password' }))
		expect(onUse).toHaveBeenCalledWith('password-1')
		expect(added(sent)).toEqual([{ value: 'password-1', kind: 'password' }])
	})

	it('records a value copied and then used once', async () => {
		const onUse = vi.fn()
		const { sent, user, footer } = renderGenerator({}, { pick: { target: 'password', onUse } })
		fakeClipboard()
		await shows('password-1')
		await user.click(screen.getByRole('button', { name: 'Copy password' }))
		await user.click(within(footer).getByRole('button', { name: 'Use this password' }))
		expect(onUse).toHaveBeenCalledWith('password-1')
		expect(added(sent)).toEqual([{ value: 'password-1', kind: 'password' }])
	})

	it('moves focus to the value in pick mode only', async () => {
		renderGenerator({}, { pick: { target: 'password', onUse: vi.fn() } })
		await shows('password-1')
		expect(document.activeElement).toBe(document.querySelector('.generated__value'))
		cleanup()
		renderGenerator()
		await shows(/^password-\d+$/)
		expect(document.activeElement).toBe(document.body)
	})

	it('offers only Password in pick mode when passphrases are turned off', async () => {
		renderGenerator({ generator: { policy: policy({ allowPassphrase: false }) } }, { pick: { target: 'password', onUse: vi.fn() } })
		await shows('password-1')
		expect(screen.queryByRole('tablist')).toBeNull()
	})

	it('offers only Username in pick mode for a username', async () => {
		const onUse = vi.fn()
		const { user, footer } = renderGenerator({}, { pick: { target: 'username', onUse } })
		await shows('username-1')
		expect(screen.queryByRole('tablist')).toBeNull()
		expect(screen.queryByRole('button', { name: 'Use this password' })).toBeNull()
		await user.click(within(footer).getByRole('button', { name: 'Use this username' }))
		expect(onUse).toHaveBeenCalledWith('username-1')
	})

	it('offers no Use outside pick mode', async () => {
		const { footer } = renderGenerator()
		await shows('password-1')
		expect(footer.childElementCount).toBe(0)
	})

	it('links to the history only when asked to', async () => {
		const onOpenHistory = vi.fn()
		const { user } = renderGenerator({}, { onOpenHistory })
		await shows('password-1')
		await user.click(screen.getByRole('button', { name: 'Generator history' }))
		expect(onOpenHistory).toHaveBeenCalledOnce()
		cleanup()
		renderGenerator()
		await shows(/^password-\d+$/)
		expect(screen.queryByRole('button', { name: 'Generator history' })).toBeNull()
	})

	it('sends nothing but generator requests and the copy notice while generating', async () => {
		const { sent, user } = renderGenerator()
		fakeClipboard()
		await shows('password-1')
		await user.click(screen.getByRole('button', { name: 'Regenerate' }))
		await user.click(screen.getByRole('button', { name: 'Copy password' }))
		await user.click(tab('Passphrase'))
		await user.click(tab('Username'))
		await shows(/^username-\d+$/)
		expect(sentKinds(sent).filter((kind) => !kind.startsWith('generator.') && kind !== 'clipboard.copied')).toEqual([])
	})

	it('says the background is not responding when it does not answer', async () => {
		renderGenerator({ unanswered: ['generator.context'] })
		expect(await screen.findByRole('alert')).toBeTruthy()
		expect(generate).not.toHaveBeenCalled()
	})

	it('shows no error when a save finds the account no longer active', async () => {
		const { user } = renderGenerator({ replies: { 'generator.saveOptions': null } })
		await shows('password-1')
		await user.click(toggle('Avoid ambiguous characters'))
		await act(() => new Promise((resolve) => setTimeout(resolve, 10)))
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('says so when a save gets no answer', async () => {
		const { user } = renderGenerator({ unanswered: ['generator.saveOptions'] })
		await shows('password-1')
		await user.click(toggle('Avoid ambiguous characters'))
		expect((await screen.findByRole('alert')).textContent).toContain('Keepiq is not responding')
	})

	it('speaks Dutch', async () => {
		setLocale('nl')
		renderGenerator()
		await shows('password-1')
		expect(within(screen.getByRole('tablist', { name: 'Wat wil je genereren?' })).getAllByRole('tab').map((t) => t.textContent)).toEqual(['Wachtwoord', 'Wachtwoordzin', 'Gebruikersnaam'])
		expect(screen.getByRole('button', { name: 'Wachtwoord kopiëren' })).toBeTruthy()
		expect(toggle('Tekens die op elkaar lijken vermijden')).toBeTruthy()
	})
})
