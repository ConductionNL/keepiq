/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the team folder fan-out: which pairs get a copy, how the copies
 * are posted in chunks of 20 (full chunks while encrypting, the rest at the
 * end), what each posted chunk triggers (attachment regrant, progress), and
 * what is reported back — created, skipped (no readable copy), refused (the
 * server would not copy) and cancelled, where a cancel posts nothing more.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showWarning: vi.fn(),
}))

const URL_SHARES = '/apps/keepiq/api/v1/team-folders/tf-1/shares'

let events

/**
 * @param {number} count How many secrets.
 * @param {Array<string>} users The recipients.
 * @return {object} The reconcile state: every secret missing for every user.
 */
function state(count, users) {
	const secrets = Array.from({ length: count }, (_, i) => ({
		id: `s${i}`,
		name: `Secret ${i}`,
	}))
	return {
		secrets,
		recipients: users.map((u) => ({ userId: u, certificate: `cert-${u}` })),
		missing: secrets.flatMap((s) =>
			users.map((u) => ({ secretId: s.id, userId: u })),
		),
	}
}

/**
 * @param {object} store The team folder store.
 * @param {object} reconciled The reconcile state.
 * @param {Function} answer The server answer for one posted chunk.
 */
function wire(store, reconciled, answer) {
	store.reconcile = vi.fn().mockResolvedValue(reconciled)
	store.regrantAttachments = vi.fn(async (rows) => {
		events.push(['regrant', rows.length, store.fanOut.done])
	})
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		events.push(['post', url, body.shares.length, store.fanOut.done])
		return { data: answer(body.shares) }
	})
	useSecretStore().fetchSecret = vi.fn(async (id) => {
		events.push(['decrypt', id])
		return { id, key: `plain-${id}`, login: '' }
	})
	useShareStore().encryptForRecipient = vi.fn(async (fields, certificate) => ({
		key: `${fields.key}@${certificate}`,
	}))
}

/**
 * @param {Array<object>} shares The posted chunk.
 * @return {object} A server answer creating every copy.
 */
function createAll(shares) {
	return {
		created: shares.length,
		rows: shares.map((s) => ({ id: `${s.sourceSecretId}-${s.targetUserId}` })),
	}
}

beforeEach(() => {
	setActivePinia(createPinia())
	events = []
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('team folder fan-out (golden)', () => {
	it('posts full chunks while encrypting and the rest at the end', async () => {
		const store = useTeamFolderStore()
		wire(store, state(15, ['bob', 'eve']), createAll)
		const result = await store.runFanOut('tf-1')

		expect(result).toEqual({ created: 30, cancelled: false, skipped: [], refused: [] })
		expect(events.filter((e) => e[0] !== 'decrypt')).toEqual([
			['post', URL_SHARES, 20, 0],
			['regrant', 20, 0],
			['post', URL_SHARES, 10, 20],
			['regrant', 10, 20],
		])
		expect(events.filter((e) => e[0] === 'decrypt')).toHaveLength(15)
		expect(store.fanOut).toEqual({ total: 30, done: 30, running: false })
		const first = axios.post.mock.calls[0][1].shares[0]
		expect(first).toEqual({
			sourceSecretId: 's0',
			targetUserId: 'bob',
			encryptedKey: 'plain-s0@cert-bob',
			encryptedLogin: null,
			encryptedAdditionalFields: null,
			encryptedTotp: null,
			encryptedPasskeys: null,
		})
	})

	it('posts an exact multiple of the chunk size with no empty tail post', async () => {
		const store = useTeamFolderStore()
		wire(store, state(10, ['bob', 'eve']), createAll)
		const result = await store.runFanOut('tf-1')

		expect(result.created).toBe(20)
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(store.fanOut.done).toBe(20)
	})

	it('counts the server answer, not the chunk, and names the refused copies', async () => {
		const store = useTeamFolderStore()
		wire(store, state(23, ['bob']), (shares) => ({
			created: shares.length - 1,
			rows: [],
			skipped: [
				{ sourceSecretId: shares[1].sourceSecretId, reason: 'copy_fields_mismatch' },
				{ sourceSecretId: shares[0].sourceSecretId, reason: 'other' },
			],
		}))
		const result = await store.runFanOut('tf-1')

		expect(result).toEqual({
			created: 21,
			cancelled: false,
			skipped: [],
			refused: ['Secret 1', 'Secret 21'],
		})
		expect(store.regrantAttachments).toHaveBeenCalledWith([], {
			bob: 'cert-bob',
		})
	})

	it('skips a secret this user holds no copy of and a member without a certificate', async () => {
		const store = useTeamFolderStore()
		const reconciled = state(2, ['bob'])
		reconciled.secrets[1].copyId = null
		reconciled.missing.push({ secretId: 's0', userId: 'nocert' })
		wire(store, reconciled, createAll)
		const result = await store.runFanOut('tf-1')

		expect(result).toEqual({
			created: 1,
			cancelled: false,
			skipped: ['Secret 1'],
			refused: [],
		})
		expect(axios.post.mock.calls[0][1].shares).toHaveLength(1)
		expect(store.fanOut).toEqual({ total: 3, done: 3, running: false })
	})

	it("reads a manager's own copy once per source and names an unknown refused source by its id", async () => {
		const store = useTeamFolderStore()
		const reconciled = state(1, ['bob', 'eve'])
		reconciled.secrets[0].copyId = 'copy-s0'
		reconciled.missing.push({ secretId: 's-unknown', userId: 'bob' })
		wire(store, reconciled, (shares) => ({
			created: shares.length,
			rows: [],
			skipped: [{ sourceSecretId: 's-gone', reason: 'copy_fields_mismatch' }],
		}))
		const result = await store.runFanOut('tf-1')

		expect(events.filter((e) => e[0] === 'decrypt')).toEqual([
			['decrypt', 'copy-s0'],
			['decrypt', 's-unknown'],
		])
		expect(result).toEqual({
			created: 3,
			cancelled: false,
			skipped: [],
			refused: ['s-gone'],
		})
		expect(store.fanOut).toEqual({ total: 3, done: 3, running: false })
	})

	it('fans out to one member only when asked', async () => {
		const store = useTeamFolderStore()
		wire(store, state(2, ['bob', 'eve']), createAll)
		await store.runFanOut('tf-1', { onlyUserId: 'eve' })

		const shares = axios.post.mock.calls[0][1].shares
		expect(shares.map((s) => s.targetUserId)).toEqual(['eve', 'eve'])
	})

	it('posts nothing more once cancelled', async () => {
		const store = useTeamFolderStore()
		wire(store, state(15, ['bob', 'eve']), createAll)
		let encrypted = 0
		useShareStore().encryptForRecipient = vi.fn(async () => {
			encrypted++
			if (encrypted === 25) {
				store.fanOutCancelled = true
			}
			return { key: 'blob' }
		})
		const result = await store.runFanOut('tf-1')

		expect(result).toEqual({ created: 20, cancelled: true, skipped: [], refused: [] })
		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(store.fanOut.done).toBe(20)
		expect(store.fanOut.running).toBe(false)
	})

	it('reports a failed post and rethrows it', async () => {
		const store = useTeamFolderStore()
		wire(store, state(1, ['bob']), createAll)
		const failure = { response: { data: { message: 'Not allowed' } } }
		axios.post.mockRejectedValue(failure)

		await expect(store.runFanOut('tf-1')).rejects.toBe(failure)
		expect(store.error).toBe('Not allowed')
		expect(store.regrantAttachments).not.toHaveBeenCalled()
		expect(store.fanOut.running).toBe(false)
	})
})
