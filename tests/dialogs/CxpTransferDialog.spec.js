/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/CxpTransferDialog.vue`: starting the
 * receive flow, the direction switch, the type-name map handed to the
 * sealed export, and closing (which stops polling and forgets every
 * transfer secret).
 *
 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CxpTransferDialog from '../../src/dialogs/CxpTransferDialog.vue'
import * as cxp from '../../src/crypto/cxp.js'
import * as reauth from '../../src/crypto/reauth.js'
import { useExportStore } from '../../src/store/modules/export.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: async () => ({}),
	resetPolicyCache: () => {},
}))

const stub = { template: '<div><slot /><slot name="actions" /></div>' }
const stubs = {
	NcDialog: stub,
	NcNoteCard: { props: ['type'], template: '<div :class="`note--${type}`" v-bind="$attrs"><slot /></div>' },
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: { template: '<input v-bind="$attrs">' },
	NcPasswordField: { template: '<input v-bind="$attrs">' },
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label v-bind="$attrs" @click="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
	SkippedSecretsNotice: stub,
}

function mountCxp(props = {}) {
	return mount(CxpTransferDialog, {
		props: { open: true, secrets: [{ id: 's1' }], folders: [], ...props },
		global: { stubs, mocks: { t: (app, s) => s, n: (app, s, p, c) => (c === 1 ? s : p) } },
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('CxpTransferDialog.startReceive', () => {
	it('publishes the request, shows the pairing code and starts polling', async () => {
		vi.useFakeTimers()
		vi.spyOn(cxp, 'createImportRequest').mockResolvedValue({
			request: { pub: 'k' },
			session: { ephemeral: true },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { pairingId: 'PAIR-1' } })
		const get = vi.spyOn(axios, 'get').mockReturnValue(new Promise(() => {}))
		const wrapper = mountCxp()

		await wrapper.find('[data-testid="cxp-start-receive"]').trigger('click')
		await vi.waitFor(() => expect(wrapper.vm.pairingId).toBe('PAIR-1'))

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/cxp/relay', {
			slot: 'request',
			payload: '{"pub":"k"}',
		})
		expect(wrapper.vm.session).toEqual({ ephemeral: true })
		expect(wrapper.vm.waiting).toBe(true)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe(null)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="cxp-pairing-code"]').text()).toBe('PAIR-1')
		expect(wrapper.find('[data-testid="cxp-start-receive"]').exists()).toBe(false)

		expect(get).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(2000)
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/cxp/relay/PAIR-1/response')
		expect(wrapper.vm.pollCount).toBe(1)
	})

	it('shows why the request could not start, else the fallback', async () => {
		const create = vi.spyOn(cxp, 'createImportRequest')
		const post = vi.spyOn(axios, 'post')
		const wrapper = mountCxp()

		create.mockRejectedValueOnce(new Error('no WebCrypto'))
		await wrapper.vm.startReceive()
		expect(wrapper.vm.error).toBe('no WebCrypto')
		expect(post).not.toHaveBeenCalled()

		create.mockResolvedValueOnce({ request: {}, session: {} })
		post.mockRejectedValueOnce(new Error(''))
		await wrapper.vm.startReceive()
		expect(wrapper.vm.error).toBe('Could not start the transfer')
		expect(wrapper.vm.pairingId).toBe(null)
		expect(wrapper.vm.waiting).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('CxpTransferDialog direction', () => {
	it('starts on receive and switches to the send panel', async () => {
		const wrapper = mountCxp()
		expect(wrapper.find('[data-testid="cxp-start-receive"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="cxp-do-send"]').exists()).toBe(false)

		await wrapper.find('[data-testid="cxp-direction-send"]').trigger('click')

		expect(wrapper.vm.direction).toBe('send')
		expect(wrapper.find('[data-testid="cxp-start-receive"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="cxp-do-send"]').text()).toBe('Seal and send')

		await wrapper.find('[data-testid="cxp-direction-receive"]').trigger('click')
		expect(wrapper.vm.direction).toBe('receive')
	})
})

describe('CxpTransferDialog.typeNamesById', () => {
	it('maps every secret type id to its name for the sealed export', async () => {
		const types = useSecretTypeStore()
		types.types = [{ id: 't1', name: 'Login' }, { id: 't2', name: 'Card' }]
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { payload: '{}' } })
		const sealed = vi.spyOn(useExportStore(), 'exportCxpSealed').mockResolvedValue({ unmapped: [{ id: 'x' }] })
		const wrapper = mountCxp()
		wrapper.vm.sendPairingId = 'pair'
		wrapper.vm.masterPassword = 'pw'

		await wrapper.vm.doSend()

		expect(wrapper.vm.typeNamesById).toEqual({ t1: 'Login', t2: 'Card' })
		expect(sealed.mock.calls[0][4]).toEqual({ typeNamesById: { t1: 'Login', t2: 'Card' }, dryRun: true })
	})

	it('is empty without types', () => {
		const types = useSecretTypeStore()
		types.types = null

		expect(mountCxp().vm.typeNamesById).toEqual({})
	})
})

describe('CxpTransferDialog closing', () => {
	it('stops polling and forgets every transfer secret', async () => {
		vi.useFakeTimers()
		const get = vi.spyOn(axios, 'get')
		const wrapper = mountCxp()
		Object.assign(wrapper.vm, {
			waiting: true,
			pairingId: 'pair',
			session: { ephemeral: true },
			sendPairingId: 'peer',
			masterPassword: 'pw',
			fetchedRequest: { req: 1 },
			cxpReport: { unmapped: [] },
			sent: true,
			skippedAcknowledged: true,
			error: 'boom',
		})
		wrapper.vm.pollForEnvelope()

		await wrapper.findAll('button').find((b) => b.text() === 'Close').trigger('click')
		await vi.advanceTimersByTimeAsync(5000)

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.vm.pollTimer).toBe(null)
		expect({
			waiting: wrapper.vm.waiting,
			session: wrapper.vm.session,
			pairingId: wrapper.vm.pairingId,
			sendPairingId: wrapper.vm.sendPairingId,
			masterPassword: wrapper.vm.masterPassword,
			fetchedRequest: wrapper.vm.fetchedRequest,
			cxpReport: wrapper.vm.cxpReport,
			sent: wrapper.vm.sent,
			skippedAcknowledged: wrapper.vm.skippedAcknowledged,
			error: wrapper.vm.error,
		}).toEqual({
			waiting: false,
			session: null,
			pairingId: null,
			sendPairingId: '',
			masterPassword: '',
			fetchedRequest: null,
			cxpReport: null,
			sent: false,
			skippedAcknowledged: false,
			error: null,
		})
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('keeps the state when opened', () => {
		const wrapper = mountCxp()
		wrapper.vm.pairingId = 'pair'

		wrapper.vm.onUpdateOpen(true)

		expect(wrapper.vm.pairingId).toBe('pair')
		expect(wrapper.emitted('update:open')).toEqual([[true]])
	})
})
