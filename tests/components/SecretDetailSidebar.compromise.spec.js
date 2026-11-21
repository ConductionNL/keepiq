/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The possibly-compromised warning in the secret sidebar: a login that also
 * holds a one-time code or passkeys is told to replace each of them, and is
 * not told that saving a new value clears the warning, because a password-only
 * save leaves it standing.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcAppSidebar: { template: '<aside><slot name="description" /><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: true,
	GroupShareList: true,
	DelegationManager: true,
	ShareRequestForm: true,
	SecretRequestList: true,
	SecretRequestCreateDialog: true,
	AttachmentPanel: true,
}

/**
 * Mount the sidebar over a flagged secret.
 *
 * @param {object} extra Fields on top of the flagged login.
 * @return {Promise<object>} The wrapper.
 */
async function mountFlagged(extra) {
	window.OC = { currentUser: 'alice' }
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's-1',
		name: 'Supplier portal',
		key: 'K',
		ownerId: 'alice',
		possiblyCompromisedAt: '2026-10-01T00:00:00+00:00',
		...extra,
	})
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: { stubs },
	})
	await flush()
	await flush()
	return wrapper
}

describe('the possibly-compromised warning', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	})

	it('says a new value clears it for a login with a password only', async () => {
		const wrapper = await mountFlagged({})
		const card = wrapper.find(
			'[data-testid="secret-detail-possibly-compromised"]',
		)

		expect(card.text()).toContain('Saving a new value clears this warning')
		expect(card.text()).not.toContain('one-time code or passkeys')
	})

	it('tells a login with a one-time code or passkeys to replace each of them instead', async () => {
		for (const held of [{ totp: 'T' }, { passkeys: 'P' }]) {
			const wrapper = await mountFlagged(held)
			const card = wrapper.find(
				'[data-testid="secret-detail-possibly-compromised"]',
			)

			expect(card.text()).toContain('one-time code or passkeys')
			expect(card.text()).not.toContain(
				'Saving a new value clears this warning',
			)
		}
	})
})
