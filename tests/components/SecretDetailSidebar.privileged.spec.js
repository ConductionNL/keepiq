/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A recipient's copy of a privileged login outside an approved window: the
 * server refuses the detail read with `privileged_access_required`, and the
 * sidebar says so and offers the request panel instead of the revoked-suite
 * message.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
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
	NcEmptyContent: {
		props: ['description'],
		template: '<div data-testid="empty">{{ description }}</div>',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
	PrivilegedAccessPanel: {
		props: ['secretId'],
		template: '<div data-testid="privileged-panel" :data-secret="secretId" />',
	},
}

async function mountRefused(response) {
	window.OC = { currentUser: 'bob' }
	vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
	useSecretStore().fetchSecret = vi.fn().mockRejectedValue({ response })
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 'c1' },
		global: { stubs },
	})
	await flush()
	await flush()
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('a privileged copy outside its window', () => {
	it('explains the refusal and offers to ask for access', async () => {
		const wrapper = await mountRefused({
			status: 403,
			data: { message: 'refused', code: 'privileged_access_required' },
		})

		expect(wrapper.find('[data-testid="empty"]').text()).toBe(
			'This login can only be used in a time window its owner approved.',
		)
		expect(
			wrapper
				.find(
					'[data-testid="secret-detail-privileged-refused"] [data-testid="privileged-panel"]',
				)
				.attributes('data-secret'),
		).toBe('c1')
	})

	it('keeps the suite message and no panel for any other refusal', async () => {
		const wrapper = await mountRefused({
			status: 403,
			data: { message: 'Encryption suite is revoked' },
		})

		expect(wrapper.find('[data-testid="empty"]').text()).toBe(
			'This secret is locked because its encryption suite was revoked.',
		)
		expect(
			wrapper
				.find('[data-testid="secret-detail-privileged-refused"]')
				.exists(),
		).toBe(false)
	})
})
