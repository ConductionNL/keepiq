/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A write-grade member's contribution is encrypted in the browser for the
 * folder owner and for every member, and only ciphertext is posted
 * (admin-vault-policies §4.3).
 *
 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useShareStore } from '../../src/store/modules/share.js'

const PLAINTEXT = 'db-root-plaintext'

describe('secret.contributeSecret', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('encrypts per owner and member and posts only ciphertext', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				ownerCertificate: 'CERT-IRIS',
				recipients: [
					{ userId: 'hank', certificate: 'CERT-HANK' },
					{ userId: 'jack', certificate: 'CERT-JACK' },
				],
			},
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { secret: { id: 's1' }, copies: 2 } })
		const encrypt = vi
			.spyOn(useShareStore(), 'encryptForRecipient')
			.mockImplementation(async (fields, certificate) => ({
				key: `RSA(${certificate})`,
				login: `RSA-LOGIN(${certificate})`,
			}))

		await useSecretStore().contributeSecret('tf-ops', {
			name: 'db-root',
			key: PLAINTEXT,
			login: 'root',
			typeId: 't',
		})

		expect(axios.get).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/team-folders/tf-ops/contribution-context',
		)
		expect(encrypt).toHaveBeenCalledTimes(3)
		const body = post.mock.calls[0][1]
		expect(post.mock.calls[0][0]).toBe(
			'/apps/keepiq/api/v1/team-folders/tf-ops/secrets',
		)
		expect(body.key).toBe('RSA(CERT-IRIS)')
		expect(
			body.copies.map((copy) => [copy.targetUserId, copy.encryptedKey]),
		).toEqual([
			['hank', 'RSA(CERT-HANK)'],
			['jack', 'RSA(CERT-JACK)'],
		])
		expect(JSON.stringify(post.mock.calls)).not.toContain(PLAINTEXT)
	})
})
