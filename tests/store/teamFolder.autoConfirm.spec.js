/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Automatic confirmation of new team folder members in the browser
 * (admin-auto-confirm-members §3.1, §3.2): decrypt once, encrypt per
 * recipient, post only ciphertext, run on unlock and every 15 minutes,
 * stop on lock.
 *
 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-an-unlocked-confirmers-browser-confirms-without-a-click
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

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showWarning: vi.fn(),
}))

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
						encryptedTotp: null,
						encryptedPasskeys: null,
					},
				],
			},
		)
		for (const call of [...post.mock.calls, ...get.mock.calls]) {
			expect(JSON.stringify(call)).not.toContain(PLAINTEXT)
		}
		expect(result).toEqual({
			enabled: true,
			created: 1,
			members: 1,
			refused: 0,
		})
	})

	it('counts the pairs the server refuses for a one-time code or passkeys it would lose', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) =>
			url.endsWith('/pending-confirmations')
				? pending({ secretId: 'db-root', userId: 'kim' })
				: { data: { key: 'CIPHER' } },
		)
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				created: 0,
				rows: [],
				skipped: [
					{
						sourceSecretId: 'db-root',
						targetUserId: 'kim',
						reason: 'copy_fields_mismatch',
					},
					{
						sourceSecretId: 'other',
						targetUserId: 'kim',
						reason: 'exists',
					},
				],
			},
		})

		const result = await useTeamFolderStore().autoConfirm()

		expect(result.refused).toBe(1)
		expect(useTeamFolderStore().lastAutoConfirm.refused).toBe(1)
	})

	it('tells the user once per unlock that a refused pair was left without a copy', async () => {
		vi.useFakeTimers()
		const { showWarning } = await import('@nextcloud/dialogs')
		showWarning.mockClear()
		const store = useTeamFolderStore()
		const autoConfirm = vi
			.spyOn(store, 'autoConfirm')
			.mockResolvedValue({ enabled: true, created: 0, members: 0, refused: 1 })

		await store.startAutoConfirm()
		await vi.advanceTimersByTimeAsync(AUTO_CONFIRM_INTERVAL_MS * 2)

		expect(autoConfirm.mock.calls.length).toBeGreaterThan(1)
		expect(showWarning).toHaveBeenCalledTimes(1)
		expect(showWarning.mock.calls[0][0]).toContain('one-time code or passkeys')
	})

	it('chunks per folder at 20, skips recipients without a certificate and counts distinct members', async () => {
		const missing = Array.from({ length: 21 }, (_, i) => ({
			secretId: 'src-' + (i % 2),
			userId: 'u' + i,
		}))
		missing.push({ secretId: 'src-0', userId: 'nocert' })
		const recipients = Array.from({ length: 21 }, (_, i) => ({
			userId: 'u' + i,
			certificate: 'CERT-' + i,
		}))
		const get = vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/pending-confirmations')) {
				return {
					data: {
						enabled: false,
						folders: [
							{ teamFolderId: 'tf-a', missing, recipients },
							{
								teamFolderId: 'tf-b',
								missing: [{ secretId: 'src-9', userId: 'u0', ownCopyId: 'own-9' }],
								recipients: [{ userId: 'u0', certificate: 'CERT-0' }],
							},
						],
					},
				}
			}
			return { data: { key: 'CIPHER' } }
		})
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url, body) => ({
			data: {
				created: body.shares.length,
				rows: body.shares.map((share) => ({ targetUserId: share.targetUserId })),
				skipped: url.includes('tf-b')
					? [{ reason: 'copy_fields_mismatch' }, { reason: 'exists' }]
					: [],
			},
		}))
		const regrant = useTeamFolderStore().regrantAttachments

		const result = await useTeamFolderStore().autoConfirm()

		expect(post.mock.calls.map(([url, body]) => [url, body.shares.length])).toEqual([
			['/apps/keepiq/api/v1/team-folders/tf-a/shares', 20],
			['/apps/keepiq/api/v1/team-folders/tf-a/shares', 1],
			['/apps/keepiq/api/v1/team-folders/tf-b/shares', 1],
		])
		expect(get.mock.calls.map(([url]) => url)).toEqual([
			'/apps/keepiq/api/v1/team-folders/pending-confirmations',
			'/apps/keepiq/api/v1/secrets/src-0',
			'/apps/keepiq/api/v1/secrets/src-1',
			'/apps/keepiq/api/v1/secrets/own-9',
		])
		expect(decrypt).toHaveBeenCalledTimes(3)
		expect(encrypt).toHaveBeenCalledTimes(22)
		expect(regrant).toHaveBeenCalledTimes(3)
		expect(regrant.mock.calls[2]).toEqual([[{ targetUserId: 'u0' }], { u0: 'CERT-0' }])
		expect(result).toEqual({ enabled: false, created: 22, members: 21, refused: 1 })
		expect(useTeamFolderStore().lastAutoConfirm).toEqual(
			expect.objectContaining({ created: 22, members: 21, refused: 1 }),
		)
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
