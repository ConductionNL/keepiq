/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit dialog saves from the version it opened and shows a failed share
 * save as a translated error instead of closing as if all went well
 * (keepiq#1253 bug 4).
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { ShareSaveError } from '../../src/services/shareSave.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { httpError, SAVED_AT, shareSaveServer } from '../fixtures/shareSaveServer.js'
import { RSA4096_PUBLIC_KEY_SPKI_PEM } from '../vitest/fixtures/rsa-fixtures.js'

const BASE = '2026-10-01T10:00:00+00:00'
/** The source version a copy opens on, before any write-back moves it. */
const OPENED_SOURCE = '2026-09-30T08:00:00+00:00'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" :disabled="disabled" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

/**
 * Mount the dialog over a loaded secret with a changed value.
 *
 * @param {object} [options] Mount options.
 * @param {object} [options.stubs] Component stubs to use instead of the defaults.
 * @param {object} [options.context] The write-context the dialog reads on open.
 * @param {() => Promise<object>} [options.writeContext] Answers each write-context read, instead of a fixed context.
 * @param {() => Promise<object>} [options.fetchSecret] Opens the secret, instead of a fixed answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountEdited(options = {}) {
	useShareStore().fetchWriteContext = options.writeContext
		? vi.fn(options.writeContext)
		: vi.fn().mockResolvedValue(
				options.context ?? {
					sourceSecretId: 's1',
					sourceUpdatedAt: BASE,
				},
			)
	useSecretStore().fetchSecret =
		options.fetchSecret
		?? vi.fn().mockResolvedValue({
			id: 's1',
			name: 'Router',
			typeId: 'login',
			key: 'old-value',
			url: null,
			login: '',
			updatedAt: BASE,
		})
	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: 's1' },
		global: { stubs: options.stubs ?? stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
	return wrapper
}

/** The open secret as the store holds it, with a request-filled member to write back. */
const OPEN_WITH_PENDING = {
	id: 's1',
	name: 'Router',
	typeId: 'login',
	key: 'old-value',
	url: null,
	login: '',
	additionalFields: {},
	mergedPending: 1,
	updatedAt: BASE,
}

/**
 * Mount the dialog over a copy whose open starts the merge write-back, like
 * the store's fetchSecret, against a server that refuses a stale copy or
 * source version, through the share save and the plain update alike.
 *
 * @param {object} options The copy.
 * @param {string} options.grade The caller's grade on the source.
 * @param {Promise|null} [options.hold] Holds the write-back's request until it resolves.
 * @param {boolean} [options.openedElsewhere] The sidebar opened the copy before the dialog, starting its own write-back.
 * @return {Promise<{wrapper: object, log: object, server: {copy: string, source: string}, writeBack: () => Promise<void>, elsewhere: () => Promise<void>|null, arrived: Promise<void>}>} `arrived` resolves once the held write-back's request reached the server.
 */
async function mountCopyWithWriteBack({
	grade,
	hold = null,
	openedElsewhere = false,
}) {
	const server = { copy: BASE, source: OPENED_SOURCE }
	const context = {
		sourceSecretId: 'src',
		effectiveGrade: grade,
		ownerCertificate: RSA4096_PUBLIC_KEY_SPKI_PEM,
	}
	useSessionStore().certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
	const stale = () => httpError(409, { error: 'stale', current: { id: 's1' } })
	const log = shareSaveServer({
		id: 's1',
		context,
		onSave: (body) => {
			if (body.baseUpdatedAt !== server.copy) {
				throw stale()
			}
			if (body.sourceBaseUpdatedAt !== server.source) {
				throw httpError(409, { error: 'stale_source' })
			}
			server.copy = SAVED_AT
			server.source = SAVED_AT
		},
	})
	const serve = axios.put.getMockImplementation()
	let arrive
	const arrived = new Promise((resolve) => (arrive = resolve))
	axios.put.mockImplementation(async (url, body) => {
		if (hold && (body.patch ?? body).mergedPending) {
			arrive()
			await hold
		}
		if (!url.endsWith('/share-save')) {
			if (body.baseUpdatedAt !== server.copy) {
				throw stale()
			}
			server.copy = SAVED_AT
		}
		return serve(url, body)
	})
	const store = useSecretStore()
	let elsewhere = null
	if (openedElsewhere) {
		store.currentSecret = { ...OPEN_WITH_PENDING }
		elsewhere = store.persistMergedPending('s1')
	}
	let writeBack
	const wrapper = await mountEdited({
		writeContext: async () => ({ ...context, sourceUpdatedAt: server.source }),
		fetchSecret: async () => {
			store.currentSecret = { ...OPEN_WITH_PENDING }
			writeBack = store.persistMergedPending('s1')
			return store.currentSecret
		},
	})
	return {
		wrapper,
		log,
		server,
		writeBack: () => writeBack,
		elsewhere: () => elsewhere,
		arrived,
	}
}

describe('SecretEditDialog — share save', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('saves from the version it opened', async () => {
		const wrapper = await mountEdited()
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update.mock.calls[0][2]).toEqual({
			baseUpdatedAt: BASE,
			sourceBaseUpdatedAt: null,
			withoutUnreachable: [],
			onWait: expect.any(Function),
		})
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saves a copy from the source version it opened on, not the one at save time', async () => {
		const opened = '2026-09-30T08:00:00+00:00'
		const wrapper = await mountEdited({
			context: { sourceSecretId: 'src', sourceUpdatedAt: opened },
		})
		// The owner changed the source while the dialog was open.
		useShareStore().fetchWriteContext.mockResolvedValue({
			sourceSecretId: 'src',
			sourceUpdatedAt: '2026-10-01T09:00:00+00:00',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update.mock.calls[0][2].sourceBaseUpdatedAt).toBe(opened)
	})

	it('offline, saves a copy from the source version its cached row carries', async () => {
		const offline = useOfflineStore()
		offline.servedFromCache = true
		offline.vault = {
			secrets: [{ id: 's1', updatedAt: BASE, sourceUpdatedAt: OPENED_SOURCE }],
		}
		const wrapper = await mountEdited()
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update.mock.calls[0][2].sourceBaseUpdatedAt).toBe(OPENED_SOURCE)
		expect(useShareStore().fetchWriteContext).not.toHaveBeenCalled()
	})

	it('says so while the save waits on the partner lookup limit', async () => {
		const wrapper = await mountEdited()
		let finish
		vi.spyOn(useSecretStore(), 'updateSecret').mockImplementation(
			(id, diff, options) => {
				options.onWait(60000)
				return new Promise((resolve) => (finish = resolve))
			},
		)
		const waiting = () => wrapper.find('[data-testid="secret-edit-waiting"]')

		const saving = wrapper.vm.submit()
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(waiting().text()).toContain('waiting for the lookup limit')

		finish({ id: 's1' })
		await saving
		await wrapper.vm.$nextTick()
		expect(waiting().exists()).toBe(false)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('says so while the save is held behind a merge write-back that waits on the partner lookup limit', async () => {
		useSessionStore().certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
		const log = shareSaveServer({ id: 's1' })
		const store = useSecretStore()
		const realUpdate = store.updateSecret
		let releaseWriteBack
		const released = new Promise((resolve) => (releaseWriteBack = resolve))
		vi.spyOn(store, 'updateSecret').mockImplementation((id, data, options) => {
			if (!options?.writeBack) {
				return realUpdate.call(store, id, data, options)
			}
			// The write-back met a 429 on a partner lookup and waits; once
			// released it saves, and the open secret moves on as it does.
			options.onWait(60000)
			return released.then(() => {
				store.currentSecret.updatedAt = SAVED_AT
				return { id: 's1', updatedAt: SAVED_AT }
			})
		})
		let writeBack
		const wrapper = await mountEdited({
			fetchSecret: async () => {
				store.currentSecret = { ...OPEN_WITH_PENDING }
				writeBack = store.persistMergedPending('s1')
				return store.currentSecret
			},
		})
		const waiting = () => wrapper.find('[data-testid="secret-edit-waiting"]')

		const saving = wrapper.vm.submit()
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(log.saves).toHaveLength(0)
		expect(waiting().text()).toContain('waiting for the lookup limit')

		releaseWriteBack()
		await writeBack
		await saving
		await wrapper.vm.$nextTick()
		expect(log.saves.map((s) => s.baseUpdatedAt)).toEqual([SAVED_AT])
		expect(waiting().exists()).toBe(false)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saves the merged count of the secret it edited, not of the one the store opened since', async () => {
		useSessionStore().certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
		const log = shareSaveServer({ id: 's1' })
		const store = useSecretStore()
		const wrapper = await mountEdited({
			fetchSecret: async () => ({
				...OPEN_WITH_PENDING,
				additionalFields: { pin: '1234' },
			}),
		})
		// Opened again meanwhile, with a fill the dialog's blob does not hold.
		store.currentSecret = {
			...OPEN_WITH_PENDING,
			additionalFields: { pin: '1234', puk: '5678' },
			mergedPending: 2,
		}
		wrapper.vm.additionalFields = [{ name: 'pin', value: '4321' }]

		await wrapper.vm.submit()

		expect(log.saves).toHaveLength(1)
		expect(log.saves[0].patch.mergedPending).toBe(1)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saves a copy past a merge write-back that finished before the save, from the versions it moved to', async () => {
		const { wrapper, log, writeBack } = await mountCopyWithWriteBack({
			grade: 'write',
		})
		await writeBack()
		expect(wrapper.vm.original.updatedAt).toBe(SAVED_AT)

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(
			log.saves.map((s) => [s.baseUpdatedAt, s.sourceBaseUpdatedAt]),
		).toEqual([
			[BASE, OPENED_SOURCE],
			[SAVED_AT, SAVED_AT],
		])
		expect(wrapper.find('[data-testid="secret-edit-error"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saves a copy held behind a merge write-back on the versions that write-back saved, not read again', async () => {
		let release
		const hold = new Promise((resolve) => (release = resolve))
		const { wrapper, log, writeBack, arrived } = await mountCopyWithWriteBack({
			grade: 'write',
			hold,
		})

		const saving = wrapper.vm.submit()
		await arrived
		release()
		await writeBack()
		await saving
		await wrapper.vm.$nextTick()

		expect(
			log.saves.map((s) => [s.baseUpdatedAt, s.sourceBaseUpdatedAt]),
		).toEqual([
			[BASE, OPENED_SOURCE],
			[SAVED_AT, SAVED_AT],
		])
		// The dialog's one read on open, and one per share save.
		expect(useShareStore().fetchWriteContext).toHaveBeenCalledTimes(3)
		expect(wrapper.find('[data-testid="secret-edit-error"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it("saves a copy opened while the sidebar's merge write-back runs on the versions that write-back saved, without starting a second", async () => {
		let release
		const hold = new Promise((resolve) => (release = resolve))
		const { wrapper, log, writeBack, elsewhere } = await mountCopyWithWriteBack({
			grade: 'write',
			hold,
			openedElsewhere: true,
		})

		release()
		await elsewhere()
		await writeBack()
		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(
			log.saves.map((s) => [s.baseUpdatedAt, s.sourceBaseUpdatedAt]),
		).toEqual([
			[BASE, OPENED_SOURCE],
			[SAVED_AT, SAVED_AT],
		])
		expect(wrapper.vm.original.mergedPending).toBe(0)
		expect(wrapper.find('[data-testid="secret-edit-error"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('refuses a copy held behind a merge write-back that a source change made fail, keeping the versions it opened on', async () => {
		let release
		const hold = new Promise((resolve) => (release = resolve))
		const { wrapper, log, server, writeBack, arrived } =
			await mountCopyWithWriteBack({
				grade: 'write',
				hold,
			})

		const saving = wrapper.vm.submit()
		await arrived
		// The owner changes the source, leaving the copy as it is, while the
		// write-back is held, so the write-back is refused as `stale_source`.
		server.source = '2026-10-01T09:30:00+00:00'
		release()
		await writeBack()
		await saving
		await wrapper.vm.$nextTick()

		expect(
			log.saves.map((s) => [s.baseUpdatedAt, s.sourceBaseUpdatedAt]),
		).toEqual([
			[BASE, OPENED_SOURCE],
			[BASE, OPENED_SOURCE],
		])
		expect(wrapper.find('[data-testid="secret-edit-error"]').text()).toContain(
			'The original of this shared secret was changed after you opened it',
		)
		expect(wrapper.emitted('saved')).toBeFalsy()
	})

	it('saves the own copy without a write grade held behind a merge write-back, not refused as stale against it', async () => {
		let release
		const hold = new Promise((resolve) => (release = resolve))
		const { wrapper, log, writeBack, arrived } = await mountCopyWithWriteBack({
			grade: 'read',
			hold,
		})

		const saving = wrapper.vm.submit()
		await arrived
		release()
		await writeBack()
		await saving
		await wrapper.vm.$nextTick()

		// Both go through the plain update, which moves only the copy.
		expect(log.saves).toHaveLength(0)
		expect(log.plainPuts.map(([, body]) => body.baseUpdatedAt)).toEqual([
			BASE,
			SAVED_AT,
		])
		expect(wrapper.find('[data-testid="secret-edit-error"]').exists()).toBe(
			false,
		)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('shows a stale refusal as translated text and stays open', async () => {
		const wrapper = await mountEdited()
		vi.spyOn(useSecretStore(), 'updateSecret').mockRejectedValue(
			httpError(409, { error: 'stale', message: 'Secret changed' }),
		)

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		const card = wrapper.find('[data-testid="secret-edit-error"]')
		expect(card.text()).toContain('changed elsewhere')
		expect(wrapper.emitted('saved')).toBeFalsy()
		expect(
			wrapper.find('[data-testid="save-without-unreachable"]').exists(),
		).toBe(false)
	})

	it("shows a vault policy refusal with the server's own message", async () => {
		const wrapper = await mountEdited()
		const message =
			'Your organisation requires this type of secret to be kept in a team folder'
		vi.spyOn(useSecretStore(), 'updateSecret').mockRejectedValue(
			Object.assign(
				httpError(428, {
					error: 'org_ownership_required',
					code: 'org_ownership_required',
					message,
				}),
				{ fromShareSave: true },
			),
		)

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="secret-edit-error"]').text()).toBe(
			message,
		)
	})

	it('offers to save without the unreachable partners, and then suspends only the ones it named', async () => {
		const wrapper = await mountEdited()
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockRejectedValueOnce(
				new ShareSaveError('partner_unreachable', [
					{ shareId: 'fs-1', recipientCloudId: 'bob@partner.example' },
					{ shareId: 'fs-2', recipientCloudId: 'carol@partner.example' },
				]),
			)
			.mockResolvedValueOnce({ id: 's1' })

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()
		const error = wrapper.find('[data-testid="secret-edit-error"]').text()
		expect(error).toContain('bob@partner.example, carol@partner.example')
		// Told before confirming: nothing resumes a suspended share.
		expect(error).toContain(
			'stops sharing this secret with them until you share it with them again',
		)

		await wrapper
			.find('[data-testid="save-without-unreachable"]')
			.trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(update.mock.calls[1][2].withoutUnreachable).toEqual(['fs-1', 'fs-2'])
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('cannot be closed while it saves, and can again afterwards', async () => {
		// Like NcModal, which ignores Escape, its close button and an outside click under `noClose`.
		const NcDialog = {
			props: ['name', 'open', 'size', 'noClose'],
			emits: ['update:open'],
			template:
				'<div @keydown.esc="noClose || $emit(\'update:open\', false)"><slot /><slot name="actions" /></div>',
		}
		const wrapper = await mountEdited({ stubs: { ...stubs, NcDialog } })
		let fail
		vi.spyOn(useSecretStore(), 'updateSecret').mockImplementation(
			() => new Promise((resolve, reject) => (fail = reject)),
		)
		const cancel = () => wrapper.find('[data-testid="secret-edit-cancel"]')

		const saving = wrapper.vm.submit()
		await wrapper.vm.$nextTick()
		expect(cancel().attributes('disabled')).toBeDefined()
		await cancel().trigger('click')
		await wrapper.trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toBeFalsy()

		fail(httpError(409, { error: 'stale', message: 'Secret changed' }))
		await saving
		await wrapper.vm.$nextTick()
		expect(cancel().attributes('disabled')).toBeUndefined()
		await wrapper.trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)
		await cancel().trigger('click')
		expect(wrapper.emitted('close').length).toBeGreaterThan(1)
	})
})
