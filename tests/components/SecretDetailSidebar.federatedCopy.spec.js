/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A read-only copy from another organisation in the secret sidebar
 * (sharing-federated-recipients tasks 3.5 and 4.4): Bob may file it in a
 * folder and delete it, and still cannot edit, archive or share it.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-files-his-copy-in-a-folder
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const button = {
	props: ['disabled'],
	emits: ['click'],
	template:
		'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}
const sidebarStubs = {
	NcAppSidebar: { template: '<aside><slot name="description" /><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: button,
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: true,
	GroupShareList: true,
	DelegationManager: true,
	ShareRequestForm: true,
	SecretRequestList: true,
	SecretRequestCreateDialog: true,
	AttachmentPanel: {
		props: ['canManage'],
		template:
			'<div data-testid="attachments" :data-manage="String(canManage)" />',
	},
}

async function mountCopy(readOnly) {
	window.OC = { currentUser: 'bob' }
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 'copy-1',
		name: 'Supplier portal',
		key: 'C',
		ownerId: 'bob',
		readOnly,
		federatedSource: 'alice@cloud.city.example',
	})
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 'copy-1' },
		global: { stubs: sidebarStubs },
	})
	await flush()
	await flush()
	return wrapper
}

const has = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`).exists()

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
})

describe('a read-only copy from another organisation', () => {
	it('can be moved to a folder and deleted, not edited or archived', async () => {
		const wrapper = await mountCopy(true)

		expect(has(wrapper, 'secret-detail-federated')).toBe(true)
		expect(has(wrapper, 'secret-detail-move')).toBe(true)
		expect(has(wrapper, 'secret-detail-delete')).toBe(true)
		expect(has(wrapper, 'secret-detail-edit')).toBe(false)
		expect(has(wrapper, 'secret-detail-archive')).toBe(false)
	})

	it('leaves an ordinary secret with every action', async () => {
		const wrapper = await mountCopy(false)

		expect(has(wrapper, 'secret-detail-federated')).toBe(false)
		expect(has(wrapper, 'secret-detail-move')).toBe(true)
		expect(has(wrapper, 'secret-detail-archive')).toBe(true)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
	})
})
