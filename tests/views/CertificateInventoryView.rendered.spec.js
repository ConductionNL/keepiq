/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/CertificateInventoryView.vue` as rendered: the error note and
 * the first-load spinner, the empty states of the stored and vault tables,
 * the CA section only when there are CA certificates, the locked hint with
 * parsing disabled, and the row buttons driving parse, renewal checklist and
 * re-issue on the store. The certificate store's actions, the session lock
 * and the toast helpers are faked; the certificate store state is real.
 *
 * @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useCertificateStore } from '../../src/store/modules/certificate.js'

const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toasts)
const session = vi.hoisted(() => ({ isLocked: false }))
vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: () => session,
}))

const { default: CertificateInventoryView } =
	await import('../../src/views/CertificateInventoryView.vue')

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: {
		props: ['name'],
		template: '<div class="empty">{{ name }}</div>',
	},
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	CertificateExpiry: { template: '<span class="expiry"><slot /></span>' },
	CertificateRenewalChecklistDialog: {
		name: 'CertificateRenewalChecklistDialog',
		props: ['checklist'],
		emits: ['close'],
		template: '<div class="checklist">{{ checklist ? "open" : "closed" }}</div>',
	},
}

let store

/**
 * Mount the view against the current store state.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountView() {
	const wrapper = mount(CertificateInventoryView, { global: { stubs } })
	await flushPromises()
	return wrapper
}

const STORED = { id: 's1', name: 'Web cert', metadata: null }
const SUITE = {
	id: 'v1',
	ownerType: 'user',
	ownerId: 'alice',
	metadata: { subject: 'CN=alice' },
}

describe('CertificateInventoryView rendered', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		store = useCertificateStore()
		session.isLocked = false
		store.fetchInventory = vi.fn()
		store.parseAndSubmit = vi.fn().mockResolvedValue(null)
		store.renewalChecklist = vi.fn().mockResolvedValue({ steps: ['a'] })
		store.reissueSuite = vi.fn().mockResolvedValue(null)
		store.error = null
		store.loading = false
		store.inventory = { stored: [STORED], suites: [SUITE], ca: [] }
	})

	afterEach(() => {
		vi.clearAllMocks()
	})

	it('loads the inventory on entry', async () => {
		await mountView()

		expect(store.fetchInventory).toHaveBeenCalledTimes(1)
	})

	it('shows the store error and the spinner before the first inventory arrives', async () => {
		store.inventory = null
		store.loading = true
		store.error = 'Could not load'

		const wrapper = await mountView()

		expect(wrapper.find('.note').text()).toBe('Could not load')
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="cert-stored-section"]').exists()).toBe(
			false,
		)
	})

	it('shows both empty states and no CA section for an empty inventory', async () => {
		store.inventory = { stored: [], suites: [], ca: [] }

		const wrapper = await mountView()

		expect(wrapper.findAll('.empty').map((e) => e.text())).toEqual([
			'No certificate secrets',
			'No active vault certificate',
		])
		expect(wrapper.find('[data-testid="cert-ca-section"]').exists()).toBe(false)
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('renders the CA rows when the inventory carries them', async () => {
		store.inventory.ca = [{ caRole: 'root', metadata: null }]

		const wrapper = await mountView()

		const row = wrapper.find('[data-testid="cert-ca-root"]')
		expect(row.exists()).toBe(true)
		expect(
			row
				.findAll('td')
				.map((td) => td.text())
				.slice(0, 2),
		).toEqual(['root', '—'])
		expect(wrapper.find('[data-testid="cert-suite-v1"]').text()).toContain(
			'user: alice',
		)
	})

	it('disables parsing and explains why while the vault is locked', async () => {
		session.isLocked = true

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="cert-locked-hint"]').exists()).toBe(true)
		expect(
			wrapper.find('[data-testid="cert-parse-s1"]').attributes('disabled'),
		).toBeDefined()
		expect(
			wrapper.find('[data-testid="cert-renew-s1"]').attributes('disabled'),
		).toBeUndefined()
	})

	it('labels an unparsed certificate "Parse" and a parsed one "Re-parse"', async () => {
		store.inventory.stored = [
			STORED,
			{ id: 's2', name: 'Parsed', metadata: { subject: 'CN=x' } },
		]

		const wrapper = await mountView()

		expect(wrapper.find('[data-testid="cert-parse-s1"]').text()).toBe(
			'Parse certificate',
		)
		expect(wrapper.find('[data-testid="cert-parse-s2"]').text()).toBe('Re-parse')
	})

	it('parses the row and confirms only when the store returned a result', async () => {
		const wrapper = await mountView()

		await wrapper.find('[data-testid="cert-parse-s1"]').trigger('click')
		await flushPromises()
		expect(store.parseAndSubmit).toHaveBeenCalledWith('s1')
		expect(toasts.showSuccess).not.toHaveBeenCalled()

		store.parseAndSubmit.mockResolvedValue({ notAfter: 'x' })
		await wrapper.find('[data-testid="cert-parse-s1"]').trigger('click')
		await flushPromises()
		expect(toasts.showSuccess).toHaveBeenCalledWith(
			'Certificate parsed — expiry reminder set.',
		)
	})

	it('opens the renewal checklist for the row and closes it again', async () => {
		const wrapper = await mountView()

		await wrapper.find('[data-testid="cert-renew-s1"]').trigger('click')
		await flushPromises()

		expect(store.renewalChecklist).toHaveBeenCalledWith('s1')
		expect(wrapper.find('.checklist').text()).toBe('open')

		wrapper
			.findComponent({ name: 'CertificateRenewalChecklistDialog' })
			.vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('.checklist').text()).toBe('closed')
	})

	it('re-issues a suite and reports success, or the store error on failure', async () => {
		const wrapper = await mountView()

		store.reissueSuite.mockResolvedValue({ id: 'v1' })
		await wrapper.find('[data-testid="cert-reissue-v1"]').trigger('click')
		await flushPromises()
		expect(store.reissueSuite).toHaveBeenCalledWith('v1')
		expect(toasts.showSuccess).toHaveBeenCalledWith(
			'Certificate re-issued with the same key pair.',
		)

		store.reissueSuite.mockImplementation(async () => {
			store.error = 'CA offline'
			return null
		})
		await wrapper.find('[data-testid="cert-reissue-v1"]').trigger('click')
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('CA offline')
	})
})
