/**
 * @spec openspec/specs/clients-passkey-origin/spec.md
 *
 * A passkey request is pinned to the origin the browser reports, and its rpId
 * must be that host or a parent domain that is not a public suffix.
 */
import { describe, expect, it, vi } from 'vitest'
import { buildPasskeyOrchestrator } from '../../browser-extension/src/passkey/orchestrator.js'
import { rpIdAllowed, senderOrigin } from '../../browser-extension/src/passkey/rp.js'

describe('passkey rpId against the page origin', () => {
	it('allows the own host', () => {
		expect(rpIdAllowed('login.example.org', 'https://login.example.org')).toBe(
			true,
		)
	})

	it('allows a parent domain', () => {
		expect(rpIdAllowed('example.org', 'https://login.example.org')).toBe(true)
		expect(rpIdAllowed('gemeente.gov.nl', 'https://mijn.gemeente.gov.nl')).toBe(
			true,
		)
	})

	it('refuses a foreign rpId', () => {
		expect(rpIdAllowed('bank.example', 'https://evil.example.org')).toBe(false)
		expect(rpIdAllowed('example.org', 'https://notexample.org')).toBe(false)
	})

	it('refuses a public suffix', () => {
		expect(rpIdAllowed('org', 'https://login.example.org')).toBe(false)
		expect(rpIdAllowed('co.uk', 'https://shop.example.co.uk')).toBe(false)
		expect(rpIdAllowed('gov.nl', 'https://mijn.gemeente.gov.nl')).toBe(false)
	})

	it('refuses an insecure or missing origin', () => {
		expect(rpIdAllowed('example.org', 'http://example.org')).toBe(false)
		expect(rpIdAllowed('example.org', '')).toBe(false)
		expect(rpIdAllowed('localhost', 'http://localhost:8080')).toBe(true)
	})

	it('reads the origin from the browser sender, not from the page', () => {
		expect(
			senderOrigin({
				origin: 'https://a.example',
				url: 'https://b.example/x',
			}),
		).toBe('https://a.example')
		expect(senderOrigin({ url: 'https://b.example/login?x=1' })).toBe(
			'https://b.example',
		)
		expect(senderOrigin({})).toBe('')
	})
})

describe('the orchestrator refuses a foreign rpId before reading the vault', () => {
	/**
	 * An orchestrator over fakes that record whether the vault was searched.
	 *
	 * @return {{passkey: object, api: object}} The orchestrator and its api fake.
	 */
	function build() {
		const api = {
			match: vi.fn().mockResolvedValue([]),
			passkeyTypeId: vi.fn(),
			createSecret: vi.fn(),
			updateSecret: vi.fn(),
		}
		const vault = {
			isUnlocked: () => true,
			decryptField: vi.fn(),
			encryptField: vi.fn(),
			activeSuiteId: () => 's1',
		}
		const passkey = buildPasskeyOrchestrator({
			api,
			vault,
			loadConfig: async () => ({}),
		})
		return { passkey, api }
	}

	it('refuses get for another site', async () => {
		const { passkey, api } = build()
		await expect(
			passkey.handleGet({ rpId: 'bank.example' }, 'https://evil.example'),
		).rejects.toThrow('rp-origin-mismatch')
		expect(api.match).not.toHaveBeenCalled()
	})

	it('refuses create for another site', async () => {
		const { passkey, api } = build()
		await expect(
			passkey.handleCreate(
				{ rp: { id: 'bank.example' } },
				'https://evil.example',
			),
		).rejects.toThrow('rp-origin-mismatch')
		expect(api.createSecret).not.toHaveBeenCalled()
	})

	it('searches the vault for its own parent rpId', async () => {
		const { passkey, api } = build()
		await expect(
			passkey.handleGet(
				{ rpId: 'bank.example' },
				'https://login.bank.example',
			),
		).rejects.toThrow('no-credential')
		expect(api.match).toHaveBeenCalledWith({}, 'bank.example')
	})
})
