/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A copy whose write-context gives no write grade in the secret sidebar
 * (keepiq#1287): its holder cannot edit or restore it, and is told why, but
 * still moves, stars and archives it.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
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
	SecretActivityTab: true,
	AttachmentPanel: true,
	RotationPanel: true,
	HoneyPanel: true,
	VersionHistoryPanel: {
		props: ['canManage'],
		template: '<div data-testid="versions" :data-manage="String(canManage)" />',
	},
}

/**
 * Answer the write-context of each secret from `contexts`, and every other GET
 * with an empty list.
 *
 * @param {object} contexts Secret id to its write-context, or to a promise of it.
 * @return {void}
 */
function serveWriteContexts(contexts) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		const match = url.match(/secrets\/([^/]+)\/write-context$/)
		return { data: match ? await contexts[match[1]] : [] }
	})
}

/**
 * Mount the sidebar over a secret Bob holds.
 *
 * @param {string} id The secret.
 * @param {object} fields Extra fields of every secret served.
 * @return {Promise<object>} The wrapper.
 */
async function mountHeld(id, fields = {}) {
	window.OC = { currentUser: 'bob' }
	useSecretStore().fetchSecret = vi.fn(async (secretId) => ({
		id: secretId,
		name: 'Supplier portal',
		key: 'C',
		ownerId: 'bob',
		...fields,
	}))
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: id },
		global: { stubs: sidebarStubs },
	})
	await flush()
	await flush()
	return wrapper
}

const has = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`).exists()

/**
 * The write-context of a copy with this grade.
 *
 * @param {string} effectiveGrade The grade.
 * @return {object}
 */
function copyWith(effectiveGrade) {
	return {
		sourceSecretId: 'src',
		effectiveGrade,
		ownerCertificate: null,
		sourceUpdatedAt: null,
	}
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('a copy without a write grade', () => {
	it.each(['read', 'none'])(
		'with grade %s shows the note and no edit, restore or new request',
		async (grade) => {
			serveWriteContexts({ 'copy-1': copyWith(grade) })
			const wrapper = await mountHeld('copy-1')

			expect(
				wrapper.find('[data-testid="secret-detail-view-only"]').text(),
			).toBe(
				'Shared with you to view only. Ask its owner for edit rights to change it.',
			)
			expect(has(wrapper, 'secret-detail-edit')).toBe(false)
			expect(has(wrapper, 'secret-detail-request-create')).toBe(false)
			expect(
				wrapper.find('[data-testid="versions"]').attributes('data-manage'),
			).toBe('false')
		},
	)

	it("keeps the holder's own actions", async () => {
		serveWriteContexts({ 'copy-1': copyWith('read') })
		const wrapper = await mountHeld('copy-1')

		expect(has(wrapper, 'secret-detail-move')).toBe(true)
		expect(has(wrapper, 'secret-detail-star')).toBe(true)
		expect(has(wrapper, 'secret-detail-archive')).toBe(true)
		expect(has(wrapper, 'secret-detail-share')).toBe(true)
		expect(has(wrapper, 'secret-detail-delete')).toBe(true)
	})

	it('does not carry its view-only state over to the next secret', async () => {
		let answer
		serveWriteContexts({
			'copy-1': copyWith('read'),
			'own-1': new Promise((resolve) => (answer = resolve)),
		})
		const wrapper = await mountHeld('copy-1')
		expect(has(wrapper, 'secret-detail-view-only')).toBe(true)

		await wrapper.setProps({ secretId: 'own-1' })
		await flush()

		// The copy stays on show, still view only, until the next secret
		// and its write-context are both in: Edit never shows on the copy.
		expect(wrapper.vm.secret.id).toBe('copy-1')
		expect(has(wrapper, 'secret-detail-edit')).toBe(false)
		answer({ sourceSecretId: 'own-1', effectiveGrade: 'owner' })
		await flush()
		expect(wrapper.vm.secret.id).toBe('own-1')
		expect(has(wrapper, 'secret-detail-view-only')).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
	})

	it('drops a late answer for a secret the sidebar has left', async () => {
		let answer
		serveWriteContexts({
			'copy-1': new Promise((resolve) => (answer = resolve)),
			'own-1': { sourceSecretId: 'own-1', effectiveGrade: 'owner' },
		})
		const wrapper = await mountHeld('copy-1')

		await wrapper.setProps({ secretId: 'own-1' })
		await flush()
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)

		answer(copyWith('read'))
		await flush()
		expect(wrapper.vm.secret.id).toBe('own-1')
		expect(has(wrapper, 'secret-detail-view-only')).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
	})

	it('drops a late failure for a secret the sidebar has left', async () => {
		serveWriteContexts({
			'copy-1': copyWith('read'),
			'copy-2': copyWith('read'),
			'own-1': { sourceSecretId: 'own-1', effectiveGrade: 'owner' },
		})
		const wrapper = await mountHeld('copy-1')
		let fail
		let answer
		useSecretStore().fetchSecret = vi.fn(
			(secretId) =>
				new Promise((resolve, reject) => {
					if (secretId === 'copy-2') {
						fail = reject
					} else {
						answer = () =>
							resolve({
								id: secretId,
								name: 'Own',
								key: 'C',
								ownerId: 'bob',
							})
					}
				}),
		)

		await wrapper.setProps({ secretId: 'copy-2' })
		await wrapper.setProps({ secretId: 'own-1' })
		fail(new Error('offline'))
		await flush()
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.loading).toBe(true)

		answer()
		await flush()
		expect(wrapper.vm.secret.id).toBe('own-1')
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.loading).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
	})

	it('loads without view-only state when its write-context fails', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/write-context')) {
				throw new Error('offline')
			}
			return { data: [] }
		})
		const wrapper = await mountHeld('copy-1')

		expect(wrapper.vm.secret.id).toBe('copy-1')
		expect(has(wrapper, 'secret-detail-view-only')).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
	})

	it('shows only the use-only note on a use-only copy', async () => {
		serveWriteContexts({ 'copy-1': copyWith('none') })
		const wrapper = await mountHeld('copy-1', { useOnly: true })

		expect(has(wrapper, 'secret-detail-use-only')).toBe(true)
		expect(has(wrapper, 'secret-detail-view-only')).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(false)
	})
})

describe('a secret its holder may write', () => {
	it.each([
		['a copy with grade write', 'copy-1', copyWith('write')],
		['a copy with grade manage', 'copy-1', copyWith('manage')],
		['a copy with grade owner', 'copy-1', copyWith('owner')],
		[
			'their own secret',
			'own-1',
			{ sourceSecretId: 'own-1', effectiveGrade: 'owner' },
		],
	])('%s keeps edit and restore', async (_, id, context) => {
		serveWriteContexts({ [id]: context })
		const wrapper = await mountHeld(id)

		expect(has(wrapper, 'secret-detail-view-only')).toBe(false)
		expect(has(wrapper, 'secret-detail-edit')).toBe(true)
		expect(has(wrapper, 'secret-detail-request-create')).toBe(true)
		expect(
			wrapper.find('[data-testid="versions"]').attributes('data-manage'),
		).toBe('true')
	})
})
