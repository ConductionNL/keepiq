/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the requests that need a fresh Nextcloud password confirmation
 * first: saving the account recovery policy, retiring its recovery key, and
 * re-enrolling a vault key after a revocation. The confirmation completes
 * before the request; a cancelled confirmation sends nothing; a refusal is
 * shown (section) or passed on (store).
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md
 * @spec openspec/specs/encryption-suites/spec.md#requirement-re-enrolment-after-a-revocation-requires-a-fresh-password-confirmation
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AccountRecoverySection from '../../src/components/settings/AccountRecoverySection.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

vi.mock('../../src/crypto/keyProof.js', () => ({
	sessionKeyProofHeaders: vi.fn(async () => ({ headers: {} })),
	PROOF_PURPOSE: {},
}))

const API = '/apps/keepiq/api/v1/recovery/admin'
const REFUSED = { response: { status: 403, data: { message: 'Administrators only' } } }

let events

beforeEach(() => {
	setActivePinia(createPinia())
	events = []
	confirmPassword.mockReset().mockImplementation(async () => events.push('confirm'))
})

afterEach(() => {
	vi.restoreAllMocks()
})

/**
 * @return {Promise<object>} The section, loaded with a policy and an active key.
 */
async function section() {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: { policy: 'optional', officers: ['ada', 'bob'], threshold: 2, activeKey: { id: 'k1' } },
	})
	const wrapper = shallowMount(AccountRecoverySection)
	await flushPromises()
	wrapper.vm.error = 'stale'
	return wrapper
}

describe('AccountRecoverySection.save', () => {
	it('confirms the password, then puts the policy and applies the answer', async () => {
		const wrapper = await section()
		const put = vi.spyOn(axios, 'put').mockImplementation(async (url, body) => {
			events.push(['put', url, body, wrapper.vm.busy, wrapper.vm.error])
			return { data: { policy: 'required', officers: ['ada'], threshold: 1, removed: ['bob'] } }
		})
		await wrapper.vm.save()
		expect(put).toHaveBeenCalledTimes(1)
		expect(events).toEqual([
			'confirm',
			['put', API, { policy: 'optional', officers: ['ada', 'bob'], threshold: 2 }, true, null],
		])
		expect(wrapper.vm.removedOfficers).toEqual(['bob'])
		expect(wrapper.vm.policy).toBe('required')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('sends nothing when the confirmation is cancelled, and shows why', async () => {
		const wrapper = await section()
		const put = vi.spyOn(axios, 'put')
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		await wrapper.vm.save()
		expect(put).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Dialog closed')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('shows a refusal', async () => {
		const wrapper = await section()
		vi.spyOn(axios, 'put').mockRejectedValue(REFUSED)
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(wrapper.vm.policy).toBe('optional')
	})
})

describe('AccountRecoverySection.retire', () => {
	it('confirms the password, then retires the active key', async () => {
		const wrapper = await section()
		vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			events.push(['post', url])
			return { data: {} }
		})
		await wrapper.vm.retire()
		expect(events).toEqual(['confirm', ['post', `${API}/keys/k1/retire`]])
		expect(wrapper.vm.activeKey).toBe(null)
		expect(wrapper.vm.error).toBe(null)
	})

	it('keeps the key when the confirmation is cancelled or the server refuses', async () => {
		const wrapper = await section()
		const post = vi.spyOn(axios, 'post').mockRejectedValue(REFUSED)
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		await wrapper.vm.retire()
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Dialog closed')
		await wrapper.vm.retire()
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(wrapper.vm.activeKey).toEqual({ id: 'k1' })
	})
})

describe('encryptionSuite.postNewSuite', () => {
	const BODY = { publicKey: 'PK', encryptedPrivateKey: 'EPK' }

	it('creates without a confirmation when the server allows it', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 's1' } })
		const response = await useEncryptionSuiteStore().postNewSuite(BODY)
		expect(response).toEqual({ data: { id: 's1' } })
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/suites', BODY)
		expect(confirmPassword).not.toHaveBeenCalled()
	})

	it('after a revocation, confirms the password and re-enrols the same key', async () => {
		vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
			events.push(['post', url, body])
			if (url.endsWith('/suites')) {
				throw { response: { data: { error: 'reauthentication_required' } } }
			}
			return { data: { id: 's2' } }
		})
		const response = await useEncryptionSuiteStore().postNewSuite(BODY)
		expect(response).toEqual({ data: { id: 's2' } })
		expect(events).toEqual([
			['post', '/apps/keepiq/api/v1/suites', BODY],
			'confirm',
			['post', '/apps/keepiq/api/v1/suites/reenrol', BODY],
		])
	})

	it('re-enrols nothing when the confirmation is cancelled, and passes other refusals on', async () => {
		const post = vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { error: 'reauthentication_required' } } })
		confirmPassword.mockRejectedValueOnce(new Error('Dialog closed'))
		await expect(useEncryptionSuiteStore().postNewSuite(BODY)).rejects.toThrow('Dialog closed')
		expect(post).toHaveBeenCalledTimes(1)
		post.mockRejectedValue(REFUSED)
		await expect(useEncryptionSuiteStore().postNewSuite(BODY)).rejects.toBe(REFUSED)
		expect(confirmPassword).toHaveBeenCalledTimes(1)
	})
})
