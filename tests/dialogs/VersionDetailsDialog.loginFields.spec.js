/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A version of a login in the version details dialog: that a seed was set
 * shows, the seed never does; its passkeys list by their metadata only.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VersionDetailsDialog from '../../src/dialogs/VersionDetailsDialog.vue'

const PASSKEY = {
	credentialId: 'Y3JlZC1iYW5r',
	rpId: 'bank.example',
	userName: 'alice',
	privateKey: 'PRIVATE-KEY-BANK',
	createdAt: '2026-09-01T10:00:00Z',
}

const stubs = {
	NcDialog: {
		props: ['name', 'open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
}

/**
 * Mount the dialog over a version.
 *
 * @param {object} fields The version's fields beyond the defaults.
 * @return {object} The wrapper.
 */
function mountVersion(fields) {
	return mount(VersionDetailsDialog, {
		props: {
			version: { name: 'Bank', key: 'password', login: 'alice', ...fields },
		},
		global: { stubs },
	})
}

describe('VersionDetailsDialog of a login', () => {
	it('says a seed was set and never shows it, even revealed', async () => {
		const wrapper = mountVersion({
			totp: 'otpauth://totp/Bank?secret=GEZDGNBVGY3TQOJQ',
		})
		expect(wrapper.find('[data-testid="version-totp"]').text()).toBe(
			'Authenticator key set',
		)
		await wrapper.find('button').trigger('click')
		expect(wrapper.html()).not.toContain('GEZDGNBVGY3TQOJQ')
	})

	it('lists the passkeys by metadata, without a private key or Delete', () => {
		const wrapper = mountVersion({ passkeys: JSON.stringify([PASSKEY]) })
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(1)
		expect(wrapper.find('[data-testid="passkey-site"]').text()).toBe(
			'bank.example',
		)
		expect(wrapper.html()).not.toContain('PRIVATE-KEY')
		expect(wrapper.find('[data-testid="passkey-list-delete"]').exists()).toBe(
			false,
		)
	})

	it('shows neither for a version without them', () => {
		const wrapper = mountVersion({ totp: '', passkeys: '' })
		expect(wrapper.find('[data-testid="version-totp"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="version-passkeys"]').exists()).toBe(false)
	})
})
