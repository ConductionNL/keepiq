/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Both team folder fan-outs, the owner's run and the automatic confirmation,
 * carry a login's `totp` seed and `passkeys` into each member's copy as
 * `encryptedTotp` and `encryptedPasskeys`, and null for a login without them
 * (keepiq#1326).
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const SEED = 'JBSWY3DPEHPK3PXP'
const PASSKEYS = '[{"credentialId":"c1"}]'
const WITH = { key: 'pw', totp: SEED, passkeys: PASSKEYS }
const WITHOUT = { key: 'pw', totp: '', passkeys: '' }

/**
 * The posted copy rows of the first `/shares` call.
 *
 * @param {object} post The axios.post spy.
 * @return {Array<object>}
 */
function postedRows(post) {
	return post.mock.calls.find(([url]) => url.endsWith('/shares'))[1].shares
}

describe('team folder fan-outs and a login seed', () => {
	let post

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(useShareStore(), 'encryptForRecipient').mockImplementation(
			async (snapshot, cert) =>
				Object.fromEntries(
					Object.entries(snapshot)
						.filter(([, value]) => value !== '')
						.map(([field, value]) => [field, `enc(${value},${cert})`]),
				),
		)
		vi.spyOn(useTeamFolderStore(), 'regrantAttachments').mockResolvedValue()
		post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { created: 1, rows: [] } })
	})

	afterEach(() => {
		useTeamFolderStore().stopAutoConfirm()
	})

	describe("the owner's run", () => {
		/**
		 * Run the fan-out for one missing pair.
		 *
		 * @param {object} plain The decrypted source secret.
		 * @return {Promise<object>} The posted row.
		 */
		async function run(plain) {
			const store = useTeamFolderStore()
			vi.spyOn(store, 'reconcile').mockResolvedValue({
				missing: [{ secretId: 's1', userId: 'kim' }],
				secrets: [{ id: 's1', name: 'Bank' }],
				recipients: [{ userId: 'kim', certificate: 'PEM-KIM' }],
			})
			vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue(plain)
			await store.runFanOut('tf-ops')
			return postedRows(post)[0]
		}

		it('carries them into the copy', async () => {
			expect(await run(WITH)).toMatchObject({
				encryptedTotp: `enc(${SEED},PEM-KIM)`,
				encryptedPasskeys: `enc(${PASSKEYS},PEM-KIM)`,
			})
		})

		it('sends null for a login without them', async () => {
			expect(await run(WITHOUT)).toMatchObject({
				encryptedTotp: null,
				encryptedPasskeys: null,
			})
		})
	})

	describe('the automatic confirmation', () => {
		/**
		 * Confirm one waiting member.
		 *
		 * @param {object} plain The decrypted source secret.
		 * @return {Promise<object>} The posted row.
		 */
		async function confirm(plain) {
			vi.spyOn(axios, 'get').mockImplementation(async (url) => {
				if (url.endsWith('/pending-confirmations')) {
					return {
						data: {
							enabled: true,
							folders: [
								{
									teamFolderId: 'tf-ops',
									missing: [{ secretId: 's1', userId: 'kim' }],
									recipients: [
										{ userId: 'kim', certificate: 'PEM-KIM' },
									],
								},
							],
						},
					}
				}
				return { data: { id: 's1', key: 'CIPHER' } }
			})
			vi.spyOn(useSecretStore(), 'decryptSecret').mockResolvedValue(plain)
			await useTeamFolderStore().autoConfirm()
			return postedRows(post)[0]
		}

		it('carries them into the copy', async () => {
			expect(await confirm(WITH)).toMatchObject({
				encryptedTotp: `enc(${SEED},PEM-KIM)`,
				encryptedPasskeys: `enc(${PASSKEYS},PEM-KIM)`,
			})
		})

		it('sends null for a login without them', async () => {
			expect(await confirm(WITHOUT)).toMatchObject({
				encryptedTotp: null,
				encryptedPasskeys: null,
			})
		})
	})
})
