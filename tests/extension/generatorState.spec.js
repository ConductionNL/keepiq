/**
 * The Generator tab's state in the worker: options per account, the history
 * and its lifetime, and the cached policy for offline use.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-generator-history
 * @spec openspec/specs/extension-generator/spec.md#requirement-options-remembered-per-account
 * @spec openspec/specs/extension-generator/spec.md#requirement-works-while-locked-and-offline
 */
import { describe, expect, it, vi } from 'vitest'
import {
	areaOrMemory,
	buildGeneratorHandlers,
} from '../../browser-extension/src/background/generator-handlers.js'
import {
	addToHistory,
	MAX_HISTORY,
	relativeTime,
	sanitizeOptions,
} from '../../browser-extension/src/lib/generator-state.js'

const ACCOUNT = { id: 'acc-1' }

/**
 * Handlers on memory storage, with a fake API.
 *
 * @param {object} [api] Replace parts of the fake API.
 * @return {object}
 */
function setup(api = {}) {
	const local = areaOrMemory(null)
	const session = areaOrMemory(null)
	const built = buildGeneratorHandlers({
		api: {
			fetchPolicy: vi.fn(async () => ({
				policy_enabled: true,
				generator_min_length: 16,
			})),
			fetchAccountEmail: vi.fn(async () => 'ann@example.org'),
			...api,
		},
		activeAccount: async () => ACCOUNT,
		activeHost: async () => 'login.example.org',
		local,
		session,
	})
	return { ...built, local, session }
}

describe('options', () => {
	it('clamps stored values to the supported ranges and the policy', () => {
		const options = sanitizeOptions(
			{
				tab: 'nonsense',
				password: { length: 999, minDigits: 40 },
				passphrase: { words: 1 },
			},
			{
				policy_enabled: true,
				generator_min_length: 20,
				generator_require_symbol: true,
			},
		)
		expect(options.tab).toBe('password')
		expect(options.password.length).toBe(128)
		expect(options.password.minDigits).toBe(9)
		expect(options.password.includeSpecialCharacters).toBe(true)
		expect(options.passphrase.words).toBe(4)
		expect(
			sanitizeOptions(
				{ password: { length: 10 } },
				{ policy_enabled: true, generator_min_length: 16 },
			).password.length,
		).toBe(16)
	})

	it('never leaves every character class off', () => {
		const options = sanitizeOptions({
			password: {
				includeUppercase: false,
				includeLowercase: false,
				includeDigits: false,
				includeSpecialCharacters: false,
			},
		})
		expect(options.password.includeLowercase).toBe(true)
	})

	it('falls back to Password when the policy switched passphrases off', () => {
		expect(
			sanitizeOptions(
				{ tab: 'passphrase' },
				{ policy_enabled: true, generator_allow_passphrase: false },
			).tab,
		).toBe('password')
	})

	it('are saved per account and come back with the context', async () => {
		const { handlers } = setup()
		await handlers['generator-context']({})
		await handlers['generator-options-save']({
			options: { tab: 'username', password: { length: 30 } },
		})
		const context = await handlers['generator-context']({})
		expect(context.options.tab).toBe('username')
		expect(context.options.password.length).toBe(30)
		expect(context.options.username.email).toBe('ann@example.org')
		expect(context.website).toBe('login.example.org')
	})
})

describe('history', () => {
	it('keeps the newest 50, newest first', () => {
		let history = []
		for (let i = 0; i < 55; i++)
			history = addToHistory(history, { value: 'v' + i, kind: 'password' }, i)
		expect(history).toHaveLength(MAX_HISTORY)
		expect(history[0].value).toBe('v54')
		expect(history.at(-1).value).toBe('v5')
	})

	it('loses no value when several are added at once', async () => {
		const { handlers } = setup()
		await Promise.all(
			['a', 'b', 'c', 'd'].map((value) =>
				handlers['generator-history-add']({ value, kind: 'password' }),
			),
		)
		const { history } = await handlers['generator-context']({})
		expect(history.map((e) => e.value).sort()).toEqual(['a', 'b', 'c', 'd'])
	})

	it('is gone after a lock, and everything is gone after removing the account', async () => {
		const { handlers, clearHistory, forget, local } = setup()
		await handlers['generator-history-add']({ value: 'x', kind: 'password' })
		await clearHistory(ACCOUNT.id)
		expect((await handlers['generator-context']({})).history).toEqual([])

		await handlers['generator-options-save']({ options: { tab: 'passphrase' } })
		await forget(ACCOUNT.id)
		expect(await local.get('generator-options:acc-1')).toEqual({})
		expect(await local.get('generator-policy:acc-1')).toEqual({})
	})

	it('says when a value was made', () => {
		expect(relativeTime(0, 30_000)).toBe('just now')
		expect(relativeTime(0, 5 * 60_000)).toBe('5 minutes ago')
		expect(relativeTime(0, 60 * 60_000)).toBe('1 hour ago')
	})
})

describe('offline', () => {
	it('uses the last policy it saw when the server does not answer', async () => {
		const fetchPolicy = vi.fn(async () => ({
			policy_enabled: true,
			generator_min_length: 16,
		}))
		const { handlers } = setup({ fetchPolicy })
		await handlers['generator-context']({})
		fetchPolicy.mockRejectedValue(new Error('offline'))
		const context = await handlers['generator-context']({})
		expect(context.policy).toEqual({
			policy_enabled: true,
			generator_min_length: 16,
		})
		expect(context.options.password.length).toBe(16)
	})
})

describe('in the worker router', () => {
	it('clears the history when the vault locks', async () => {
		vi.resetModules()
		const { installChrome, installServer, makeVault, POPUP } =
			await import('./fixtures/fakeBrowser.js')
		installChrome()
		installServer({ 'https://one.example': await makeVault('m', 'x') })
		const router =
			await import('../../browser-extension/src/background/router.js')
		const send = (type, payload = {}) =>
			router.handleMessage({ type, payload }, POPUP)
		await send('pair', {
			url: 'https://one.example',
			user: 'ann',
			appPassword: 'p',
		})
		await send('unlock', { masterPassword: 'm' })

		await send('generator-history-add', { value: 'secret-1', kind: 'password' })
		expect((await send('generator-context')).history).toHaveLength(1)
		await send('lock')
		expect((await send('generator-context')).history).toEqual([])
	})
})
