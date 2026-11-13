/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/ApplicationDetail.vue` as rendered: the back button returns to
 * the register, a load failure shows its reason, the secrets panel opens and
 * closes the write dialog, and deleting goes through the confirm dialog
 * (closing it deletes nothing; confirming deletes and returns to the
 * register, or shows why it failed).
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.2
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const settingsStore = { holdsArea: vi.fn(() => true) }
const applicationStore = {
	currentApplication: null,
	fetchApplication: vi.fn(),
	fetchCertificate: vi.fn(),
	deleteApplication: vi.fn(),
}

vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => settingsStore,
}))
vi.mock('../../src/store/modules/application.js', () => ({
	useApplicationStore: () => applicationStore,
}))

const { default: ApplicationDetail } =
	await import('../../src/views/ApplicationDetail.vue')

function named (name, props = []) {
  return {
	name,
	props,
	emits: ['close', 'confirm', 'written', 'writeSecret'],
	render() {
		return h('div', { class: name }, [
			this.$slots.icon?.(),
			this.$slots.default?.(),
		])
	},
}
}

/**
 * Mount the view for an application id.
 *
 * @return {Promise<{wrapper: object, push: Function}>} The wrapper and the router push spy.
 */
async function mountView() {
	const push = vi.fn()
	const wrapper = mount(ApplicationDetail, {
		props: { id: 'app-1' },
		global: {
			mocks: { t: (_app, text) => text, $router: { push } },
			stubs: {
				NcButton: {
					emits: ['click'],
					template:
						'<button @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
				},
				NcEmptyContent: {
					props: ['name', 'description'],
					template:
						'<div class="empty"><slot name="icon" />{{ name }}: {{ description }}</div>',
				},
				NcLoadingIcon: { template: '<i class="loading" />' },
				NcNoteCard: { template: '<div class="note"><slot /></div>' },
				ArrowLeft: { template: '<i class="arrow-left" />' },
				AlertCircleOutline: { template: '<i class="alert-icon" />' },
				ApplicationSecretsPanel: named('ApplicationSecretsPanel', [
					'applicationId',
				]),
				ApplicationLeasesPanel: named('ApplicationLeasesPanel'),
				SecretRequestList: named('SecretRequestList'),
				WriteSecretForAppDialog: named('WriteSecretForAppDialog', [
					'open',
					'applicationId',
					'applicationName',
				]),
				ApplicationDeleteDialog: named('ApplicationDeleteDialog', ['open']),
			},
		},
	})
	await flushPromises()
	return { wrapper, push }
}

describe('ApplicationDetail rendered', () => {
	beforeEach(() => {
		applicationStore.currentApplication = {
			id: 'app-1',
			name: 'Backup job',
			status: 'active',
		}
		applicationStore.fetchApplication.mockReset().mockResolvedValue()
		applicationStore.fetchCertificate
			.mockReset()
			.mockResolvedValue('-----BEGIN CERTIFICATE-----')
		applicationStore.deleteApplication.mockReset().mockResolvedValue()
	})

	it('goes back to the register from the back button', async () => {
		const { wrapper, push } = await mountView()
		const back = wrapper.find('.application-detail__back')
		expect(back.find('.arrow-left').exists()).toBe(true)
		expect(back.text()).toBe('Back to applications')
		await back.trigger('click')
		expect(push).toHaveBeenCalledWith({ name: 'ApplicationRegister' })
	})

	it('shows why the application could not be opened', async () => {
		applicationStore.fetchApplication.mockRejectedValueOnce({
			response: { data: { message: 'Not yours' } },
		})
		const { wrapper } = await mountView()
		const empty = wrapper.find('.empty')
		expect(empty.text()).toBe('Cannot open application: Not yours')
		expect(empty.find('.alert-icon').exists()).toBe(true)
	})

	it('opens the write dialog from the secrets panel and closes it again', async () => {
		const { wrapper } = await mountView()
		expect(
			wrapper.findComponent({ name: 'WriteSecretForAppDialog' }).exists(),
		).toBe(false)

		await wrapper
			.findComponent({ name: 'ApplicationSecretsPanel' })
			.vm.$emit('writeSecret')
		const dialog = wrapper.findComponent({ name: 'WriteSecretForAppDialog' })
		expect(dialog.props()).toMatchObject({
			open: true,
			applicationId: 'app-1',
			applicationName: 'Backup job',
		})

		await dialog.vm.$emit('close')
		expect(
			wrapper.findComponent({ name: 'WriteSecretForAppDialog' }).exists(),
		).toBe(false)
	})

	it('deletes only after the confirm dialog, then returns to the register', async () => {
		const { wrapper, push } = await mountView()
		await wrapper.find('[data-testid="delete-button"]').trigger('click')
		let dialog = wrapper.findComponent({ name: 'ApplicationDeleteDialog' })
		expect(dialog.props('open')).toBe(true)

		await dialog.vm.$emit('close')
		expect(
			wrapper.findComponent({ name: 'ApplicationDeleteDialog' }).exists(),
		).toBe(false)
		expect(applicationStore.deleteApplication).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="delete-button"]').trigger('click')
		dialog = wrapper.findComponent({ name: 'ApplicationDeleteDialog' })
		await dialog.vm.$emit('confirm')
		await flushPromises()
		expect(applicationStore.deleteApplication).toHaveBeenCalledWith('app-1')
		expect(push).toHaveBeenCalledWith({ name: 'ApplicationRegister' })
	})

	it('shows why a delete failed', async () => {
		applicationStore.deleteApplication.mockRejectedValueOnce(
			new Error('Has active leases'),
		)
		const { wrapper, push } = await mountView()
		await wrapper.find('[data-testid="delete-button"]').trigger('click')
		await wrapper
			.findComponent({ name: 'ApplicationDeleteDialog' })
			.vm.$emit('confirm')
		await flushPromises()
		expect(wrapper.find('.empty').text()).toBe(
			'Cannot open application: Has active leases',
		)
		expect(push).not.toHaveBeenCalled()
	})
})
