/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PasskeyDisplay's field presentation: the invalid state for anything that is
 * not a credential (with and without the private key row), the site and
 * account labels, the truncated credential id, optional rows left out, the
 * creation date as given when it does not parse, and the reveal toggle label.
 *
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-listing-filtering-and-site-associated-presentation
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PasskeyDisplay from '../../src/components/PasskeyDisplay.vue'

const BASE = {
	credentialId: 'Y3JlZC1iYW5rLTAwMDE',
	rpId: 'bank.example',
	privateKey: 'PRIVATE-KEY-BANK',
	createdAt: '2026-09-01T10:00:00Z',
}

const stubs = {
	CopyButton: {
		props: ['value', 'label'],
		template: '<button :data-value="value" />',
	},
}

/**
 * Mount the display over this credential JSON.
 *
 * @param {string} credentialJson The decrypted credential JSON.
 * @param {object} [props] Other props.
 * @return {object} The wrapper.
 */
function mountDisplay(credentialJson, props = {}) {
	return mount(PasskeyDisplay, {
		props: { credentialJson, ...props },
		global: { stubs },
	})
}

describe('PasskeyDisplay invalid state', () => {
	it.each([
		['JSON that does not parse', 'not json'],
		[
			'a credential without its private key',
			JSON.stringify({ ...BASE, privateKey: '' }),
		],
		['an empty value', ''],
	])('shows the invalid note for %s', (_label, json) => {
		const wrapper = mountDisplay(json)
		expect(wrapper.find('[data-testid="passkey-invalid"]').text()).toBe(
			'Not a valid passkey credential',
		)
		expect(wrapper.find('[data-testid="passkey-site"]').exists()).toBe(false)
	})

	it.each([
		['JSON that does not parse', 'not json'],
		['a JSON null', 'null'],
		['a JSON number', '42'],
		['a credential without its site', JSON.stringify({ credentialId: 'abc' })],
	])(
		'shows the invalid note for %s without the private key row',
		(_label, json) => {
			const wrapper = mountDisplay(json, { hidePrivateKey: true })
			expect(wrapper.find('[data-testid="passkey-invalid"]').exists()).toBe(
				true,
			)
			expect(wrapper.find('dl').exists()).toBe(false)
		},
	)

	it('accepts a credential without a private key when the row is hidden', () => {
		const { privateKey: _privateKey, ...metadata } = BASE
		const wrapper = mountDisplay(JSON.stringify(metadata), {
			hidePrivateKey: true,
		})
		expect(wrapper.find('[data-testid="passkey-invalid"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="passkey-site"]').text()).toBe(
			'bank.example',
		)
	})
})

describe('PasskeyDisplay fields', () => {
	it('shows the bare rp id when the site has no name', () => {
		const wrapper = mountDisplay(JSON.stringify(BASE))
		expect(wrapper.find('[data-testid="passkey-site"]').text()).toBe(
			'bank.example',
		)
	})

	it('shows the display name with the user name after it', () => {
		const wrapper = mountDisplay(
			JSON.stringify({
				...BASE,
				userName: 'alice@example.org',
				userDisplayName: 'Alice',
			}),
		)
		const account = wrapper.find('[data-testid="passkey-account"]')
		expect(account.text()).toContain('Alice')
		expect(account.find('.passkey-display__muted').text()).toBe(
			'(alice@example.org)',
		)
	})

	it('shows the display name alone when there is no user name', () => {
		const wrapper = mountDisplay(
			JSON.stringify({ ...BASE, userDisplayName: 'Alice' }),
		)
		const account = wrapper.find('[data-testid="passkey-account"]')
		expect(account.text()).toBe('Alice')
		expect(account.find('.passkey-display__muted').exists()).toBe(false)
	})

	it('leaves the account and transports rows out when they are empty', () => {
		const wrapper = mountDisplay(JSON.stringify(BASE))
		expect(wrapper.find('[data-testid="passkey-account"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="passkey-transports"]').exists()).toBe(
			false,
		)
	})

	it('joins the transports with commas', () => {
		const wrapper = mountDisplay(
			JSON.stringify({ ...BASE, transports: ['usb', 'nfc'] }),
		)
		expect(wrapper.find('[data-testid="passkey-transports"]').text()).toBe(
			'usb, nfc',
		)
	})

	it('truncates a long credential id to twelve characters and an ellipsis', () => {
		const wrapper = mountDisplay(JSON.stringify(BASE))
		expect(wrapper.find('[data-testid="passkey-credential-id"]').text()).toBe(
			'Y3JlZC1iYW5r…',
		)
	})

	it('keeps a short credential id whole', () => {
		const wrapper = mountDisplay(
			JSON.stringify({ ...BASE, credentialId: 'short-id' }),
		)
		expect(wrapper.find('[data-testid="passkey-credential-id"]').text()).toBe(
			'short-id',
		)
	})

	it('shows a creation date that does not parse exactly as stored', () => {
		const wrapper = mountDisplay(
			JSON.stringify({ ...BASE, createdAt: 'sometime last year' }),
		)
		expect(wrapper.find('[data-testid="passkey-created"]').text()).toBe(
			'sometime last year',
		)
	})

	it('formats a parseable creation date in the locale', () => {
		const wrapper = mountDisplay(JSON.stringify(BASE))
		expect(wrapper.find('[data-testid="passkey-created"]').text()).toBe(
			new Date(Date.parse(BASE.createdAt)).toLocaleDateString(),
		)
	})
})

describe('PasskeyDisplay private key row', () => {
	it('masks the key and flips the reveal label both ways', async () => {
		const wrapper = mountDisplay(JSON.stringify(BASE))
		const reveal = wrapper.find('[data-testid="passkey-reveal"]')
		const key = wrapper.find('[data-testid="passkey-private-key"]')

		expect(key.text()).toBe('••••••••••••')
		expect(reveal.attributes('aria-label')).toBe('Show private key')

		await reveal.trigger('click')
		expect(key.text()).toBe('PRIVATE-KEY-BANK')
		expect(reveal.attributes('aria-label')).toBe('Hide private key')

		await reveal.trigger('click')
		expect(key.text()).toBe('••••••••••••')
		expect(reveal.attributes('aria-label')).toBe('Show private key')
	})
})
