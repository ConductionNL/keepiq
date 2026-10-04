/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Vault backups admin section (admin-scheduled-vault-backups §4.1):
 * shows schedule, last result and archives, saves the settings, asks for a
 * backup now, and never offers a download.
 *
 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import VaultBackupSection from '../../src/components/settings/VaultBackupSection.vue'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

const ARCHIVES = [
	{
		name: 'keepiq-backup-20261002-030000.zip.enc',
		size: 2097152,
		createdAt: 1790000000,
		encrypted: true,
	},
	{
		name: 'keepiq-backup-20261001-030000.zip.enc',
		size: 2000000,
		createdAt: 1789900000,
		encrypted: true,
	},
	{
		name: 'keepiq-backup-20260930-030000.zip',
		size: 4096,
		createdAt: 1789800000,
		encrypted: false,
	},
]

/**
 * Mount with an enabled schedule and three archives.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountSection() {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: {
			settings: {
				backup_enabled: true,
				backup_interval_hours: 24,
				backup_retention_count: 7,
				backup_recipient_public_key: '',
			},
			status: {
				lastRunAt: 1790000000,
				lastStatus: 'ok',
				lastError: '',
				runRequested: false,
			},
			archives: ARCHIVES,
		},
	})
	const wrapper = mount(VaultBackupSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('VaultBackupSection', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('lists the archives with name, size, time and encryption, and no download', async () => {
		const wrapper = await mountSection()

		const rows = wrapper.findAll('[data-testid="vault-backup-list"] tbody tr')
		expect(rows).toHaveLength(3)
		expect(rows[0].text()).toContain('keepiq-backup-20261002-030000.zip.enc')
		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.html()).not.toMatch(/download/i)
		expect(wrapper.find('[data-testid="vault-backup-last"]').exists()).toBe(true)
	})

	it('saves the schedule keys', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="vault-backup-interval"]').setValue(6)
		await wrapper.find('[data-testid="vault-backup-interval"]').trigger('change')
		await flushPromises()

		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/settings/admin/backups',
			expect.objectContaining({
				backup_interval_hours: 6,
				backup_enabled: true,
			}),
		)
	})

	it('asks for a backup now and says so', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { requested: true } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="vault-backup-run"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/settings/admin/backups/run',
		)
		expect(
			wrapper.find('[data-testid="vault-backup-run"]').attributes('disabled'),
		).toBeDefined()
	})

	it('shows a server refusal', async () => {
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: {
				data: {
					message: 'backup_interval_hours must be between 1 and 8760',
				},
			},
		})
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="vault-backup-interval"]').trigger('change')
		await flushPromises()

		expect(wrapper.find('[data-testid="vault-backup-error"]').text()).toContain(
			'backup_interval_hours',
		)
	})
})
