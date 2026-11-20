/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Both team folder fan-outs name the version of the source they decrypted,
 * and a row the server skips as `stale_source` is decrypted and sent again
 * (#1255). A member or manager reading their own copy names no version.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#scenario-a-share-made-during-a-save-is-sent-again
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { MAX_STALE_SOURCE_ROUNDS, useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

const OLD = '2026-10-01T10:00:00+00:00'
const NEW = '2026-10-01T10:00:05+00:00'

/**
 * A team folder `/shares` endpoint holding `s1` at `current`: a row from
 * another version is skipped as stale.
 *
 * @param {{current: string}} state The source's version.
 * @return {Array<Array<object>>} The rows of each `/shares` post.
 */
function server(state) {
	const posts = []
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		posts.push(body.shares)
		const fresh = body.shares.filter((r) => !r.sourceUpdatedAt || r.sourceUpdatedAt === state.current)
		const stale = body.shares.filter((r) => r.sourceUpdatedAt && r.sourceUpdatedAt !== state.current)
		return {
			data: {
				created: fresh.length,
				rows: [],
				skipped: stale.map((r) => ({
					sourceSecretId: r.sourceSecretId,
					targetUserId: r.targetUserId,
					reason: 'stale_source',
					sourceUpdatedAt: state.current,
				})),
			},
		}
	})
	return posts
}

describe('team folder fan-outs and a source a save replaced', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(useShareStore(), 'encryptForRecipient').mockImplementation(
			async (snapshot, cert) => ({ key: `enc(${snapshot.key},${cert})` }),
		)
		vi.spyOn(useTeamFolderStore(), 'regrantAttachments').mockResolvedValue()
	})

	afterEach(() => {
		useTeamFolderStore().stopAutoConfirm()
	})

	/**
	 * Reconcile to one missing pair of `s1` for kim.
	 *
	 * @param {object} [ref] The source ref, with `copyId` for a manager.
	 * @return {object} The store.
	 */
	function ownerRun(ref = { id: 's1', name: 'Bank' }) {
		const store = useTeamFolderStore()
		vi.spyOn(store, 'reconcile').mockResolvedValue({
			missing: [{ secretId: 's1', userId: 'kim' }],
			secrets: [ref],
			recipients: [{ userId: 'kim', certificate: 'PEM-KIM' }],
		})
		return store
	}

	it("decrypts the source again and resends the owner's stale row", async () => {
		const posts = server({ current: NEW })
		const fetch = vi.spyOn(useSecretStore(), 'fetchSecret')
		fetch.mockResolvedValueOnce({ id: 's1', key: 'old', updatedAt: OLD })
		fetch.mockResolvedValueOnce({ id: 's1', key: 'new', updatedAt: NEW })

		const result = await ownerRun().runFanOut('tf-ops')

		expect(posts.map((rows) => rows.map((r) => [r.encryptedKey, r.sourceUpdatedAt]))).toEqual([
			[['enc(old,PEM-KIM)', OLD]],
			[['enc(new,PEM-KIM)', NEW]],
		])
		expect(result.created).toBe(1)
	})

	it('stops resending after three more rounds', async () => {
		const posts = server({ current: NEW })
		vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({ id: 's1', key: 'old', updatedAt: OLD })

		const result = await ownerRun().runFanOut('tf-ops')

		expect(posts).toHaveLength(MAX_STALE_SOURCE_ROUNDS + 1)
		expect(result.created).toBe(0)
	})

	it("names no version for a manager's own copy", async () => {
		const posts = server({ current: NEW })
		vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({ id: 'm-copy', key: 'pw', updatedAt: OLD })

		await ownerRun({ id: 's1', name: 'Bank', copyId: 'm-copy' }).runFanOut('tf-ops')

		expect(posts).toHaveLength(1)
		expect(posts[0][0]).not.toHaveProperty('sourceUpdatedAt')
	})

	it('resends a stale row of the automatic confirmation', async () => {
		const posts = server({ current: NEW })
		let reads = 0
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/pending-confirmations')) {
				return {
					data: {
						enabled: true,
						folders: [
							{
								teamFolderId: 'tf-ops',
								missing: [{ secretId: 's1', userId: 'kim' }],
								recipients: [{ userId: 'kim', certificate: 'PEM-KIM' }],
							},
						],
					},
				}
			}
			reads++
			return { data: { id: 's1', key: 'CIPHER', updatedAt: reads === 1 ? OLD : NEW } }
		})
		vi.spyOn(useSecretStore(), 'decryptSecret').mockImplementation(async (raw) => ({
			key: raw.updatedAt === OLD ? 'old' : 'new',
		}))

		await useTeamFolderStore().autoConfirm()

		expect(posts.map((rows) => rows.map((r) => [r.encryptedKey, r.sourceUpdatedAt]))).toEqual([
			[['enc(old,PEM-KIM)', OLD]],
			[['enc(new,PEM-KIM)', NEW]],
		])
	})
})
