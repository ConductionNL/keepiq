/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Two-factor available and Passkey available categories: shown with the
 * attribution when the site directory loaded, replaced by an unavailable line
 * saying why when it is off or could not be loaded, and each finding's action
 * opening the directory's page for the site.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-unused-two-factor-login-and-available-passkeys
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const state = { breachCheckEnabled: false }
let healthStore
let sessionStore
let rotationStore

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (_app, key, fallback) => (key in state ? state[key] : fallback),
}))
vi.mock('../../src/store/modules/health.js', () => ({
	useHealthStore: () => healthStore,
}))
vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: () => sessionStore,
}))
vi.mock('../../src/store/modules/rotation.js', () => ({
	useRotationStore: () => rotationStore,
}))
vi.mock('../../src/store/modules/secret.js', () => ({
	useSecretStore: () => ({ updateSecret: vi.fn() }),
}))

const { default: HealthReportView } =
	await import('../../src/views/HealthReportView.vue')

const HealthCategoryStub = {
	name: 'HealthCategory',
	props: ['title', 'description', 'findings', 'testid', 'actionLabel'],
	emits: ['open', 'action'],
	render() {
		return h(
			'section',
			{ 'data-testid': this.testid },
			(this.findings || []).map((f) =>
				h(
					'button',
					{ class: 'finding', onClick: () => this.$emit('action', f) },
					f.name,
				),
			),
		)
	},
}

/**
 * Mount the view against the current fake stores.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountView() {
	const wrapper = mount(HealthReportView, {
		global: {
			mocks: { $router: { push: vi.fn() } },
			stubs: {
				NcSelect: { template: '<div class="select" />' },
				NcButton: {
					emits: ['click'],
					template: '<button @click="$emit(\'click\')"><slot /></button>',
				},
				HealthCategory: HealthCategoryStub,
				OwnershipFindings: { template: '<div class="ownership" />' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('HealthReportView site directory categories', () => {
	beforeEach(() => {
		healthStore = {
			status: 'ready',
			score: 90,
			error: '',
			breachStatus: 'off',
			siteDirectoryStatus: 'ready',
			summary: {
				weakCount: 0,
				reusedCount: 0,
				staleCount: 0,
				breachedCount: 0,
				compromisedCount: 0,
				insecureAddressCount: 0,
				twoFactorAvailableCount: 1,
				passkeyAvailableCount: 2,
			},
			weakFindings: [],
			reusedFindings: [],
			staleFindings: [],
			breachedFindings: [],
			compromisedFindings: [],
			insecureAddressFindings: [],
			twoFactorAvailableFindings: [
				{
					id: 'gh',
					name: 'GitHub',
					siteDocs: {
						twoFactor: 'https://docs.github.com/2fa',
						passkey: null,
					},
				},
			],
			passkeyAvailableFindings: [
				{
					id: 'ex',
					name: 'Example',
					siteDocs: {
						twoFactor: null,
						passkey: 'https://example.com/passkeys',
					},
				},
				{
					id: 'nolink',
					name: 'No link',
					siteDocs: { twoFactor: null, passkey: null },
				},
			],
			analyseVault: vi.fn().mockResolvedValue(),
			registerLockReset: vi.fn(),
		}
		sessionStore = { isLocked: false }
		rotationStore = {
			flags: [],
			fetchFlags: vi.fn().mockResolvedValue(),
			flagSecrets: vi.fn(),
		}
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows both categories, their counts and the attribution when the directory loaded', async () => {
		const wrapper = await mountView()

		expect(
			wrapper.find('[data-testid="category-two-factor-available"]').text(),
		).toContain('GitHub')
		expect(
			wrapper.find('[data-testid="category-passkey-available"]').text(),
		).toContain('Example')
		expect(wrapper.text()).toContain('Two-factor available: 1')
		expect(wrapper.text()).toContain('Passkey available: 2')
		expect(
			wrapper.find('[data-testid="site-directory-attribution"]').text(),
		).toContain('2FA Directory (MIT)')
		expect(
			wrapper.find('[data-testid="site-directory-unavailable"]').exists(),
		).toBe(false)
	})

	it.each([
		['off', 'your administrator has not switched on the site directory'],
		['unavailable', 'the site directory could not be loaded'],
	])(
		'says both categories are unavailable while the directory is %s',
		async (status, why) => {
			healthStore.siteDirectoryStatus = status

			const wrapper = await mountView()

			expect(
				wrapper
					.find('[data-testid="category-two-factor-available"]')
					.exists(),
			).toBe(false)
			expect(
				wrapper.find('[data-testid="category-passkey-available"]').exists(),
			).toBe(false)
			expect(wrapper.text()).not.toContain('Two-factor available: 1')
			expect(
				wrapper.find('[data-testid="site-directory-unavailable"]').text(),
			).toContain(why)
		},
	)

	it('opens the directory page of the site, or the directory itself without one', async () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const wrapper = await mountView()

		await wrapper
			.find('[data-testid="category-two-factor-available"] .finding')
			.trigger('click')
		const passkeys = wrapper.findAll(
			'[data-testid="category-passkey-available"] .finding',
		)
		await passkeys[0].trigger('click')
		await passkeys[1].trigger('click')

		expect(open.mock.calls).toEqual([
			['https://docs.github.com/2fa', '_blank', 'noopener,noreferrer'],
			['https://example.com/passkeys', '_blank', 'noopener,noreferrer'],
			['https://passkeys.2fa.directory/', '_blank', 'noopener,noreferrer'],
		])
	})
})
