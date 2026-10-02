/**
 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
 *
 * keepiq#746: a login saved from the browser extension skipped the org
 * password policy that the web app applies. These tests drive the worker's
 * real message router (`save-capture`, `capture-credential`) with the vault
 * and the HTTP client faked, and assert that a value the policy refuses is
 * never encrypted or posted.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { promptCopy, showSavePrompt } from '../../browser-extension/src/content/save-prompt.js'
import { sha1Hex } from '../../src/health/hibpMatch.js'

vi.mock('../../browser-extension/src/lib/vault.js', () => ({
	isUnlocked: vi.fn(() => true),
	encryptField: vi.fn(async (v) => 'enc:' + v),
	activeSuiteId: vi.fn(() => 'suite-1'),
	decryptSecret: vi.fn(),
	decryptField: vi.fn(),
	armIdleLock: vi.fn(),
	lock: vi.fn(),
}))

vi.mock('../../browser-extension/src/lib/api.js', () => ({
	loadConfig: vi.fn(async () => ({ url: 'https://cloud.test', user: 'ann', appPassword: 'x' })),
	fetchPolicy: vi.fn(),
	breachRange: vi.fn(),
	createSecret: vi.fn(async () => ({})),
	updateSecret: vi.fn(async () => ({})),
	match: vi.fn(async () => []),
}))

const api = await import('../../browser-extension/src/lib/api.js')
const vault = await import('../../browser-extension/src/lib/vault.js')

let listener

beforeAll(async () => {
	globalThis.chrome = {
		runtime: { onMessage: { addListener: (fn) => { listener = fn } } },
	}
	await import('../../browser-extension/src/background/service-worker.js')
})

/**
 * Send one message through the worker's router and wait for the answer.
 *
 * @param {string} type The message type.
 * @param {object} payload The payload.
 * @return {Promise<object>} What the worker sent back.
 */
function send(type, payload) {
	return new Promise((resolve) => {
		listener({ type, payload }, {}, resolve)
	})
}

const FLOOR_THREE = {
	policy_enabled: true,
	min_zxcvbn_score: 3,
	block_on_hibp_hit: false,
	policy_exempt_types: ['note'],
}

const WEAK = 'password'
const STRONG = 'correct horse battery staple violin 7%Q'

describe('extension save path applies the org password policy', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('refuses a password below the strength floor and saves nothing', async () => {
		api.fetchPolicy.mockResolvedValue(FLOOR_THREE)

		const res = await send('save-capture', { host: 'example.org', login: 'ann', secret: WEAK })

		expect(res.error).toContain("below your organisation's minimum of 3")
		expect(vault.encryptField).not.toHaveBeenCalled()
		expect(api.createSecret).not.toHaveBeenCalled()
	})

	it('refuses an update the same way', async () => {
		api.fetchPolicy.mockResolvedValue(FLOOR_THREE)

		const res = await send('save-capture', { id: 's1', host: 'example.org', login: 'ann', secret: WEAK })

		expect(res.error).toBeTruthy()
		expect(api.updateSecret).not.toHaveBeenCalled()
	})

	it('saves a password that meets the floor', async () => {
		api.fetchPolicy.mockResolvedValue(FLOOR_THREE)

		const res = await send('save-capture', { host: 'example.org', login: 'ann', secret: STRONG })

		expect(res).toEqual({ ok: true })
		expect(api.createSecret).toHaveBeenCalledTimes(1)
	})

	it('saves when the policy cannot be read, as the web app does', async () => {
		api.fetchPolicy.mockResolvedValue(null)

		const res = await send('save-capture', { host: 'example.org', login: 'ann', secret: WEAK })

		expect(res).toEqual({ ok: true })
		expect(api.createSecret).toHaveBeenCalledTimes(1)
	})

	it('refuses a breached password when the policy blocks those, sending only the prefix', async () => {
		api.fetchPolicy.mockResolvedValue({ ...FLOOR_THREE, min_zxcvbn_score: 0, block_on_hibp_hit: true })
		const hash = await sha1Hex(STRONG)
		api.breachRange.mockResolvedValue(`${hash.slice(5)}:42\r\nABC:1`)

		const res = await send('save-capture', { host: 'example.org', login: 'ann', secret: STRONG })

		expect(res.error).toContain('known breaches 42 times')
		expect(api.breachRange).toHaveBeenCalledTimes(1)
		expect(api.breachRange.mock.calls[0][1]).toBe(hash.slice(0, 5))
		expect(api.createSecret).not.toHaveBeenCalled()
	})

	it('a breach service that does not answer never blocks', async () => {
		api.fetchPolicy.mockResolvedValue({ ...FLOOR_THREE, min_zxcvbn_score: 0, block_on_hibp_hit: true })
		api.breachRange.mockRejectedValue(new Error('503'))

		const res = await send('save-capture', { host: 'example.org', login: 'ann', secret: STRONG })

		expect(res).toEqual({ ok: true })
	})

	it('answers a submitted weak password with a refusal instead of a save offer', async () => {
		api.fetchPolicy.mockResolvedValue(FLOOR_THREE)

		const offer = await send('capture-credential', { host: 'example.org', login: 'ann', secret: WEAK })

		expect(offer.action).toBe('refused')
		expect(offer.reason).toContain('minimum of 3')
		// Nothing is held for the popup to save later.
		expect((await send('pending-capture', {})).capture).toBeNull()
	})
})

describe('the in-page refusal', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('explains the refusal and offers no save button', () => {
		const copy = promptCopy({ action: 'refused', reason: 'Too weak.' }, 'example.org')

		expect(copy.primary).toBeNull()
		expect(copy.text).toContain('did not save')
		expect(copy.text).toContain('Too weak.')
	})

	it('renders only a close button', () => {
		showSavePrompt({ action: 'refused', reason: 'Too weak.' }, 'example.org', document, {
			mode: 'open',
		})
		const root = document.getElementById('keepiq-save-prompt').shadowRoot
		const buttons = root.querySelectorAll('button')

		expect(buttons).toHaveLength(1)
		expect(buttons[0].textContent).toBe('Close')
	})
})

describe('the extension breach lookup', () => {
	it('posts the prefix in the body, never in the URL (keepiq#866)', async () => {
		const { breachRange } = await vi.importActual('../../browser-extension/src/lib/api.js')
		const fetchMock = vi.fn(async () => ({
			ok: true,
			status: 200,
			json: async () => ({ suffixes: 'ABC:1' }),
		}))
		vi.stubGlobal('fetch', fetchMock)

		const body = await breachRange({ url: 'https://cloud.test/', user: 'ann', appPassword: 'x' }, '5BAA6')

		expect(body).toBe('ABC:1')
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('https://cloud.test/index.php/apps/keepiq/api/v1/breach-check/range')
		expect(url).not.toContain('5BAA6')
		expect(init.method).toBe('POST')
		expect(JSON.parse(init.body)).toEqual({ prefix: '5BAA6' })
		vi.unstubAllGlobals()
	})
})
