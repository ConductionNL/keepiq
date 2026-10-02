/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Automatic confirmation of new team folder members in the browser
 * (admin-auto-confirm-members §3.1, §3.2): decrypt once, encrypt per
 * recipient, post only ciphertext, run on unlock and every 15 minutes,
 * stop on lock.
 *
 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#3.1
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import {
	AUTO_CONFIRM_INTERVAL_MS,
	useTeamFolderStore,
} from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const PLAINTEXT = 'hunter2-plaintext'

/**
 * The pending response for one folder.
 *
 * @param {object} pair The missing pair.
 * @param {boolean} enabled Whether the switch is on.
 * @return {object}
 */
function pending(pair, enabled = true) {
	return {
		data: {
			enabled,
			folders: enabled
				? [
						{
							teamFolderId: 'tf-ops',
							role: pair.ownCopyId ? 'member' : 'owner',
							missing: [pair],
							recipients: [{ userId: 'kim', certificate: 'CERT-KIM' }],
						},
					]
				: [],
		},
	}
}

describe('teamFolder.autoConfirm', () => {
	let decrypt
	let encrypt

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		decrypt = vi
			.spyOn(useSecretStore(), 'decryptSecret')
			.mockResolvedValue({ key: PLAINTEXT, login: 'root' })
		encrypt = vi
			.spyOn(useShareStore(), 'encryptForRecipient')
			.mockImplementation(async (fields, certificate) => ({
				key: `RSA(${certificate})`,
				login: `RSA-LOGIN(${certificate})`,
			}))
		vi.spyOn(useTeamFolderStore(), 'regrantAttachments').mockResolvedValue()
	})

	afterEach(() => {
		useTeamFolderStore().stopAutoConfirm()
		vi.useRealTimers()
	})

	it('a member decrypts their OWN copy once and posts only ciphertext', async () => {
		const get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/pending-confirmations')) {
				return pending({
					secretId: 'db-root',
					userId: 'kim',
					ownCopyId: 'copy-hank',
				})
			}
			return { data: { id: url.split('/').pop(), key: 'CIPHER' } }
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				created: 1,
				rows: [
					{
						sourceSecretId: 'db-root',
						targetUserId: 'kim',
						recipientSecretId: 'c',
					},
				],
			},
		})

		const result = await useTeamFolderStore().autoConfirm()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/copy-hank')
		expect(get).not.toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/db-root')
		expect(decrypt).toHaveBeenCalledTimes(1)
		expect(encrypt).toHaveBeenCalledWith(
			expect.objectContaining({ key: PLAINTEXT }),
			'CERT-KIM',
		)
		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/team-folders/tf-ops/shares',
			{
				shares: [
					{
						sourceSecretId: 'db-root',
						targetUserId: 'kim',
						encryptedKey: 'RSA(CERT-KIM)',
						encryptedLogin: 'RSA-LOGIN(CERT-KIM)',
						encryptedAdditionalFields: null,
					},
				],
			},
		)
		for (const call of [...post.mock.calls, ...get.mock.calls]) {
			expect(JSON.stringify(call)).not.toContain(PLAINTEXT)
		}
		expect(result).toEqual({ enabled: true, created: 1, members: 1 })
	})

	it('the owner decrypts the source itself', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockImplementation(async (url) =>
				url.endsWith('/pending-confirmations')
					? pending({ secretId: 'db-root', userId: 'kim' })
					: { data: { key: 'CIPHER' } },
			)
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { created: 1, rows: [] } })

		await useTeamFolderStore().autoConfirm()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/db-root')
	})

	it('runs on unlock, repeats every 15 minutes and stops on lock', async () => {
		vi.useFakeTimers()
		const get = vi.spyOn(axios, 'get').mockResolvedValue(pending({}, true))
		get.mockResolvedValue({ data: { enabled: true, folders: [] } })
		const session = useSessionStore()

		session.afterUnlock()
		await vi.advanceTimersByTimeAsync(0)
		expect(get).toHaveBeenCalledTimes(1)

		await vi.advanceTimersByTimeAsync(AUTO_CONFIRM_INTERVAL_MS)
		expect(get).toHaveBeenCalledTimes(2)

		session.lock()
		await vi.advanceTimersByTimeAsync(AUTO_CONFIRM_INTERVAL_MS * 3)
		expect(get).toHaveBeenCalledTimes(2)
	})

	it('does not repeat while the switch is off', async () => {
		vi.useFakeTimers()
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { enabled: false, folders: [] } })

		await useTeamFolderStore().startAutoConfirm()
		await vi.advanceTimersByTimeAsync(AUTO_CONFIRM_INTERVAL_MS * 2)

		expect(get).toHaveBeenCalledTimes(1)
	})

	it('a failed run never throws and is retried on the next tick', async () => {
		vi.useFakeTimers()
		const get = vi
			.spyOn(axios, 'get')
			.mockRejectedValueOnce(new Error('offline'))
			.mockResolvedValue({ data: { enabled: true, folders: [] } })

		await expect(
			useTeamFolderStore().startAutoConfirm(),
		).resolves.toBeUndefined()
		await vi.advanceTimersByTimeAsync(AUTO_CONFIRM_INTERVAL_MS)

		expect(get).toHaveBeenCalledTimes(2)
	})
})
