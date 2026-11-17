/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The parts of `src/App.vue` the other shell specs leave open: its initial
 * data, the prop defaults, the URL-decided public layout and support-note
 * switch, the AI-companion gate on Hermiq being enabled, and the timeout
 * picker swallowing a failed save.
 *
 * Like App.shellOptions.spec.js, the options are invoked directly with a
 * stubbed `this`: mounting the shell would drag in CnAppRoot and every store.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
 * @spec openspec/specs/app-shell/spec.md#requirement-optional-integrations-appear-only-when-their-app-is-present
 * @spec openspec/specs/vault-session-lock/spec.md#requirement-saved-session-timeout
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'

const loadState = vi.fn()

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (...args) => loadState(...args),
}))

/**
 * Point jsdom at a URL without navigating.
 *
 * @param {string} url Path, optionally with a hash.
 * @return {void}
 */
function visit(url) {
	window.history.replaceState(null, '', url)
}

describe('App.vue computed state', () => {
	beforeEach(() => {
		loadState.mockReset()
	})

	afterEach(() => {
		visit('/')
		delete window.OC
		vi.restoreAllMocks()
	})

	describe('data', () => {
		it('starts unlocked-idle with the version from the initial state', () => {
			loadState.mockReturnValue('3.4.5')

			const data = App.data.call({})

			expect(loadState).toHaveBeenCalledWith('keepiq', 'appVersion', '')
			expect(data.appVersion).toBe('3.4.5')
			expect(data).toMatchObject({
				unloading: false,
				storesReady: false,
				timeoutInterval: null,
				showRecovery: false,
				revokeConfirm: false,
				revokeReason: '',
				revokePassword: '',
				revoking: false,
				revokeSuccess: false,
				revokeError: null,
				revokeEmergencyCount: 0,
			})
		})

		it('offers the three session-timeout choices in order', () => {
			const { timeoutOptions } = App.data.call({})

			expect(timeoutOptions.map((option) => option.value)).toEqual([
				'session',
				'10min',
				'30min',
			])
			expect(timeoutOptions.map((option) => option.label)).toEqual([
				'Nextcloud session',
				'10 minutes',
				'30 minutes',
			])
		})
	})

	describe('prop defaults', () => {
		it('defaults the component maps to fresh empty objects', () => {
			const first = App.props.customComponents.default()
			const second = App.props.customComponents.default()

			expect(first).toEqual({})
			expect(first).not.toBe(second)
			expect(App.props.registry.default()).toEqual({})
			expect(App.props.pageTypes.default).toBeNull()
			expect(App.props.manifest.required).toBe(true)
		})
	})

	describe('isPublicPage', () => {
		it('is true on the anonymous shell', () => {
			visit('/index.php/apps/keepiq/public/share/link/abc')

			expect(App.computed.isPublicPage.call({})).toBe(true)
		})

		it('is true on a recipient hash route of the authenticated shell', () => {
			visit('/apps/keepiq/#/send/token-1')

			expect(App.computed.isPublicPage.call({})).toBe(true)
		})

		it('is false on an ordinary app page and outside the app', () => {
			visit('/apps/keepiq/secrets')
			expect(App.computed.isPublicPage.call({})).toBe(false)

			visit('/apps/files/')
			expect(App.computed.isPublicPage.call({})).toBe(false)
		})
	})

	describe('showSupportDialog', () => {
		it('is shown on an authenticated, non-public route', () => {
			visit('/apps/keepiq/secrets')

			expect(
				App.computed.showSupportDialog.call({ $route: { name: 'Secrets' } }),
			).toBe(true)
		})

		it('is hidden when the URL is a public surface, whatever the route says', () => {
			visit('/apps/keepiq/public')

			expect(
				App.computed.showSupportDialog.call({ $route: { name: 'Secrets' } }),
			).toBe(false)
		})

		it('is hidden when the resolved route is public', () => {
			visit('/apps/keepiq/secrets')

			expect(
				App.computed.showSupportDialog.call({
					$route: { name: 'Secrets', meta: { public: true } },
				}),
			).toBe(false)
		})
	})

	describe('hermiqEnabled', () => {
		it('is true only when hermiq has a web root for this user', () => {
			window.OC = {
				appswebroots: { keepiq: '/apps/keepiq', hermiq: '/apps/hermiq' },
			}
			expect(App.computed.hermiqEnabled.call({})).toBe(true)

			window.OC = { appswebroots: { keepiq: '/apps/keepiq' } }
			expect(App.computed.hermiqEnabled.call({})).toBe(false)
		})

		it('is false outside a Nextcloud page', () => {
			expect(App.computed.hermiqEnabled.call({})).toBe(false)
		})
	})

	describe('onTimeoutChange', () => {
		it('saves the choice and attaches a handler that swallows a failed save', () => {
			const pending = { catch: vi.fn() }
			const saveTimeoutPreference = vi.fn(() => pending)
			const context = { sessionStore: { saveTimeoutPreference } }

			expect(
				App.methods.onTimeoutChange.call(context, '10min'),
			).toBeUndefined()
			expect(saveTimeoutPreference).toHaveBeenCalledWith('10min')
			expect(pending.catch).toHaveBeenCalledTimes(1)
			const handler = pending.catch.mock.calls[0][0]
			expect(() => handler(new Error('offline'))).not.toThrow()
			expect(handler(new Error('offline'))).toBeUndefined()
		})
	})
})
