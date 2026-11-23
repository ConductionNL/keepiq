/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the §12.6 sharing integration on the secret-detail
 * sidebar (the successor of the SecretDetail view). The
 * component is mounted with the RecipientList / DelegationManager /
 * ShareRequestForm components stubbed, so the test asserts the role-driven
 * visibility branches rather than re-asserting each child's behaviour
 * (those are covered by their own .spec.js files). NcAppSidebar and its
 * tabs are stubbed to plain slot-renderers, so every tab's content is in
 * the DOM at once.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-12.6
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	Delete: { template: '<span />' },
	Lock: { template: '<span />' },
	Pencil: { template: '<span />' },
	FolderMove: { template: '<span />' },
	ShareVariant: { template: '<span />' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div data-testid="stub-share-list" />' },
	GroupShareList: { template: '<div data-testid="stub-group-share-list" />' },
	DelegationManager: { template: '<div data-testid="stub-delegation-manager" />' },
	ShareRequestForm: { template: '<div data-testid="stub-share-request-form" />' },
	AdminHandoverPanel: { template: '<div />' },
	SecretRequestList: { template: '<div data-testid="stub-request-list" />' },
	SecretRequestCreateDialog: {
		template: '<div data-testid="stub-request-dialog" />',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Answer the write-context with `context` (or fail with it when it is an
 * Error), and every other GET with an empty list.
 *
 * @param {object|Error} context The write-context.
 * @return {void}
 */
function serveWriteContext(context) {
	axios.get.mockImplementation(async (url) => {
		if (!url.endsWith('/write-context')) {
			return { data: [] }
		}
		if (context instanceof Error) {
			throw context
		}
		return { data: context }
	})
}

const has = (wrapper, testId) => wrapper.find(`[data-testid="${testId}"]`).exists()

async function mountDetail({ secret, currentUser }) {
	window.OC = { currentUser }
	// Pre-seed the secret store so the component's mounted hook resolves
	// `fetchSecret` to our fixture rather than going through the live
	// RSA decryption path.
	const secretStore = useSecretStore()
	secretStore.fetchSecret = vi.fn().mockResolvedValue(secret)
	const typeStore = useSecretTypeStore()
	typeStore.fetchTypes = vi.fn().mockResolvedValue([])

	// VTU v2 moved `stubs` and `mocks` under `global`. At the top level they
	// are SILENTLY IGNORED — the component would render its real children, so
	// this must stay nested.
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: secret?.id ?? 's-1' },
		global: {
			stubs: stubAll,
		},
	})
	await flush()
	await flush()
	return wrapper
}

describe('SecretDetailSidebar sharing tab (§12.6)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// Stub @nextcloud/axios so the secretType store + child component
		// mounted hooks resolve to empty payloads instead of blowing up.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		window.OC = { currentUser: null }
	})

	it('renders the recipient list + delegation manager for the secret owner', async () => {
		serveWriteContext({ sourceSecretId: 's-1', effectiveGrade: 'owner' })
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-share-list"]').exists(),
		).toBe(true)
		// sharing-02: the owner is offered Share with group.
		expect(
			wrapper.find('[data-testid="secret-detail-group-share-list"]').exists(),
		).toBe(true)
		expect(
			wrapper
				.find('[data-testid="secret-detail-delegation-manager"]')
				.exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="secret-detail-share-request"]').exists(),
		).toBe(false)
	})

	it('renders the share-request form for a non-owner recipient', async () => {
		serveWriteContext({ sourceSecretId: 's-1', effectiveGrade: 'read' })
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'bob',
		})

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-share-list"]').exists(),
		).toBe(false)
		// sharing-02: a recipient is not offered Share with group.
		expect(
			wrapper.find('[data-testid="secret-detail-group-share-list"]').exists(),
		).toBe(false)
		expect(
			wrapper
				.find('[data-testid="secret-detail-delegation-manager"]')
				.exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="secret-detail-share-request"]').exists(),
		).toBe(true)
	})

	it('treats the recipient of a received copy as a recipient, not its owner (#1298)', async () => {
		// The copy is owned by its recipient; the write-context names its source.
		serveWriteContext({ sourceSecretId: 'src-1', effectiveGrade: 'read' })
		const wrapper = await mountDetail({
			secret: { id: 'copy-1', name: 'GitHub', key: 'CIPHER', ownerId: 'bob' },
			currentUser: 'bob',
		})

		for (const testId of [
			'secret-detail-share-list',
			'secret-detail-group-share-list',
			'secret-detail-delegation-manager',
		]) {
			expect(has(wrapper, testId), testId).toBe(false)
		}
		// The request and the admin handover go to the source.
		const form = wrapper.find('[data-testid="secret-detail-share-request"]')
		expect(form.attributes('secretid')).toBe('src-1')
		const handover = wrapper.find('[data-testid="secret-detail-admin-handover"]')
		expect(handover.attributes('secretid')).toBe('src-1')
	})

	it('shows neither the owner controls nor the request form while the write-context is unknown', async () => {
		// 409 while the copy's row waits for the quarantine scan.
		serveWriteContext(
			Object.assign(new Error('scan'), { response: { status: 409 } }),
		)
		const wrapper = await mountDetail({
			secret: { id: 'copy-1', name: 'GitHub', key: 'CIPHER', ownerId: 'bob' },
			currentUser: 'bob',
		})

		for (const testId of [
			'secret-detail-share-list',
			'secret-detail-group-share-list',
			'secret-detail-delegation-manager',
			'secret-detail-share-request',
			'secret-detail-admin-handover',
		]) {
			expect(has(wrapper, testId), testId).toBe(false)
		}
	})

	it("says a copy's sharing waits for the scan, and shows the owner controls once a retry answers", async () => {
		serveWriteContext(
			Object.assign(new Error('scan'), {
				response: {
					status: 409,
					data: {
						error: 'share_scan_pending',
						message: 'Waits for the scan',
					},
				},
			}),
		)
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		const note = wrapper.find(
			'[data-testid="secret-detail-sharing-unavailable"]',
		)
		expect(note.text()).toContain('Waits for the scan')
		// A status message, so a retry's outcome is announced (WCAG 4.1.3).
		expect(note.attributes('role')).toBe('status')
		expect(has(wrapper, 'secret-detail-share-list')).toBe(false)

		serveWriteContext({ sourceSecretId: 's-1', effectiveGrade: 'owner' })
		await wrapper
			.find('[data-testid="secret-detail-sharing-retry"]')
			.trigger('click')
		await flush()

		expect(has(wrapper, 'secret-detail-sharing-unavailable')).toBe(false)
		expect(has(wrapper, 'secret-detail-share-list')).toBe(true)
	})

	it('says sharing could not be loaded after a network error', async () => {
		serveWriteContext(new Error('offline'))
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		expect(
			wrapper.find('[data-testid="secret-detail-sharing-unavailable"]').text(),
		).toContain('Sharing options could not be loaded.')
	})

	it('changes the status text on every retry that fails again', async () => {
		serveWriteContext(new Error('offline'))
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})
		const note = () =>
			wrapper.find('[data-testid="secret-detail-sharing-unavailable"]')
		const retry = () =>
			wrapper.find('[data-testid="secret-detail-sharing-retry"]')

		await retry().trigger('click')
		await flush()
		expect(note().text()).toBe('Sharing options still could not be loaded.')

		// A second failure reads the same, so the text empties while it runs.
		let fail
		axios.get.mockImplementation((url) =>
			url.endsWith('/write-context')
				? new Promise((resolve, reject) => {
						fail = reject
					})
				: Promise.resolve({ data: [] }),
		)
		await retry().trigger('click')
		await flush()
		expect(note().text()).toBe('')

		fail(new Error('offline'))
		await flush()
		expect(note().text()).toBe('Sharing options still could not be loaded.')
	})

	it('offers no retry while offline, where it can only fail', async () => {
		useOfflineStore().servedFromCache = true
		serveWriteContext(new Error('offline'))
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		// The offline note at the top already says sharing needs a connection.
		expect(has(wrapper, 'secret-detail-sharing-unavailable')).toBe(true)
		expect(has(wrapper, 'secret-detail-sharing-retry')).toBe(false)
	})

	it('offers no share request when the write-context names no source', async () => {
		serveWriteContext({ sourceSecretId: null, effectiveGrade: 'none' })
		const wrapper = await mountDetail({
			secret: { id: 'copy-1', name: 'GitHub', key: 'CIPHER', ownerId: 'bob' },
			currentUser: 'bob',
		})

		expect(has(wrapper, 'secret-detail-share-request')).toBe(false)
		expect(has(wrapper, 'secret-detail-share-list')).toBe(false)
		// The answer is final, so the note says so and offers no retry.
		expect(
			wrapper.find('[data-testid="secret-detail-sharing-unavailable"]').text(),
		).toBe('This copy offers no sharing options.')
		expect(has(wrapper, 'secret-detail-sharing-retry')).toBe(false)
	})

	it("lists the source's shares for a delegate on their copy, without delegating", async () => {
		serveWriteContext({ sourceSecretId: 'src-1', effectiveGrade: 'owner' })
		const wrapper = await mountDetail({
			secret: { id: 'copy-1', name: 'GitHub', key: 'CIPHER', ownerId: 'bob' },
			currentUser: 'bob',
		})

		expect(
			wrapper
				.find('[data-testid="secret-detail-share-list"]')
				.attributes('secretid'),
		).toBe('src-1')
		const groups = wrapper.find('[data-testid="secret-detail-group-share-list"]')
		// The group share form shares from the copy, which the delegate decrypts.
		expect(groups.attributes('secretid')).toBe('copy-1')
		expect(groups.attributes('sourcesecretid')).toBe('src-1')
		expect(has(wrapper, 'secret-detail-delegation-manager')).toBe(false)
		expect(has(wrapper, 'secret-detail-share-request')).toBe(false)
	})

	it('hides the entire sharing tab when no secret is loaded', async () => {
		// The component's mounted hook will set `secret` via fetchSecret;
		// when our store stub returns null, the v-if="!error && secret"
		// short-circuits every tab and the sharing section never renders.
		const wrapper = await mountDetail({ secret: null, currentUser: 'alice' })

		expect(wrapper.find('[data-testid="secret-detail-sharing"]').exists()).toBe(
			false,
		)
	})

	it('treats ownerId as the single canonical owner field (fail-closed)', async () => {
		// The Secret entity has serialized `ownerId` since its first version,
		// so no response or offline snapshot ever carried `owner_id`/`userId`.
		// A payload with only a legacy-style name therefore reads as
		// not-owned rather than re-introducing a fallback chain every
		// consumer would have to duplicate (PR #479 review).
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', owner_id: 'alice' },
			currentUser: 'alice',
		})

		expect(has(wrapper, 'secret-detail-requests')).toBe(false)
	})

	it('renders the Requests section + SecretRequestList for the owner', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'alice',
		})

		expect(wrapper.find('[data-testid="secret-detail-requests"]').exists()).toBe(
			true,
		)
		expect(
			wrapper.find('[data-testid="secret-detail-request-list"]').exists(),
		).toBe(true)
		// The dialog is mounted lazily; not visible until the create button fires.
		expect(
			wrapper.find('[data-testid="secret-detail-request-dialog"]').exists(),
		).toBe(false)
	})

	it('hides the Requests section from non-owner recipients', async () => {
		const wrapper = await mountDetail({
			secret: { id: 's-1', name: 'GitHub', key: 'CIPHER', ownerId: 'alice' },
			currentUser: 'bob',
		})

		expect(wrapper.find('[data-testid="secret-detail-requests"]').exists()).toBe(
			false,
		)
	})
	it('keeps the request dialog open after creation, so the fill link survives', async () => {
		// The dialog computes fillUrl in submit() and THEN emits `created`. This
		// handler used to close the dialog, which unmounts it (v-if) one tick later
		// and destroys the only copy of the link the requester is ever offered —
		// the URL the whole feature exists to produce.
		const wrapper = await mountDetail({
			secret: {
				id: 's-1',
				name: 'GitHub PAT',
				ownerId: 'alice',
				key: 'CIPHER',
			},
			currentUser: 'alice',
		})

		wrapper.vm.requestDialogOpen = true
		await flush()

		wrapper.vm.onRequestCreated()
		await flush()

		expect(wrapper.vm.requestDialogOpen).toBe(true)
	})
})
