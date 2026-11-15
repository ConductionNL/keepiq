/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The secret detail sidebar's remaining behaviour, mounted end to end:
 * - a failed type catalogue or secret load shows the error state (refusal,
 *   server message, fallback) instead of a stranded spinner;
 * - the header actions: Share, Delete, Close, the favourite star and
 *   archive / unarchive, with their store calls and failure toasts, and a
 *   failed list refresh after an edit;
 * - the masked card and BSN rows reveal and hide per field;
 * - an unparseable identity falls back to the raw key row;
 * - the access end date of an expiring copy;
 * - the offline notes (read-only vs. offline edits) and which menu actions
 *   each leaves;
 * - the request dialog opening and closing, and a delegation reclaim
 *   reloading the detail;
 * - a passkey delete offline, based on the cached source version, and the
 *   focus fallback to the card when neither list nor Edit remain.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-read-secret
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-sharing-and-membership-actions-stay-online-only
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// The sidebar binds the global `t` on import; fill placeholders so the
// access-end sentence carries its date.
vi.hoisted(() => {
	globalThis.t = (_app, key, vars) =>
		key.replace(/{(\w+)}/g, (match, name) => vars?.[name] ?? match)
})

import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { valueFieldsSupported } from '../../src/utils/valueFields.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/utils/valueFields.js', async (importOriginal) => ({
	...(await importOriginal()),
	valueFieldsSupported: vi.fn(() => true),
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const button = {
	props: ['disabled'],
	emits: ['click'],
	template:
		'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
}
const stubs = {
	NcAppSidebar: {
		emits: ['close'],
		template:
			'<aside><button class="native-close" @click="$emit(\'close\')" /><slot name="description" /><slot /></aside>',
	},
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: button,
	NcActionSeparator: true,
	NcButton: button,
	NcEmptyContent: {
		props: ['name', 'description'],
		template:
			'<div class="empty"><span class="empty-name">{{ name }}</span><span class="empty-desc">{{ description }}</span><slot name="icon" /></div>',
	},
	NcNoteCard: { template: '<div><slot /></div>' },
	CopyButton: { template: '<button />' },
	PasswordField: {
		props: ['label', 'resolve'],
		template: '<input class="key-field" :aria-label="label" />',
	},
	TotpDisplay: true,
	PasskeyList: true,
	ShareList: true,
	GroupShareList: true,
	DelegationManager: {
		emits: ['reclaimed'],
		template:
			'<button class="reclaim" @click="$emit(\'reclaimed\')" />',
	},
	ShareRequestForm: true,
	AdminHandoverPanel: true,
	SecretRequestList: true,
	SecretRequestCreateDialog: {
		props: ['open', 'isReRequest'],
		emits: ['update:open', 'created'],
		template:
			'<div class="request-dialog"><button class="request-dialog-close" @click="$emit(\'update:open\', false)" /></div>',
	},
	SecretActivityTab: true,
	AttachmentPanel: true,
	RotationPanel: true,
	HoneyPanel: true,
	VersionHistoryPanel: true,
	VaultIndicator: true,
}

let openModal

/**
 * Mount the sidebar over a secret Bob owns.
 *
 * @param {object} fields Fields of the secret beyond the defaults.
 * @return {Promise<object>} The wrapper.
 */
async function mountSecret(fields = {}) {
	vi.spyOn(axios, 'get').mockImplementation(async () => ({
		data: { sourceSecretId: 's1', effectiveGrade: 'owner' },
	}))
	useSecretStore().fetchSecret = vi.fn(async (id) => ({
		id,
		name: 'Bank',
		typeId: 'type-login',
		key: 'pw',
		ownerId: 'bob',
		updatedAt: '2026-10-01T10:00:00+00:00',
		...fields,
	}))
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's1' },
		global: { stubs, provide: { cnOpenModal: openModal } },
		attachTo: document.body,
	})
	await flush()
	await flush()
	return wrapper
}

const find = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	window.OC = { currentUser: 'bob' }
	openModal = vi.fn()
	const typeStore = useSecretTypeStore()
	typeStore.types = [
		{ id: 'type-login', name: 'login', label: 'Login' },
		{ id: 'type-card', name: 'card', label: 'Card' },
		{ id: 'type-identity', name: 'identity', label: 'Identity' },
	]
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	vi.mocked(valueFieldsSupported).mockReturnValue(true)
	vi.mocked(showError).mockClear()
	document.body.innerHTML = ''
})

describe('loading failures', () => {
	it('shows the server message when the type catalogue fails, without loading the secret', async () => {
		useSecretTypeStore().fetchTypes = vi
			.fn()
			.mockRejectedValue({ response: { data: { message: 'Types down' } } })
		const wrapper = await mountSecret()

		expect(useSecretStore().fetchSecret).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.get('.empty-name').text()).toBe('Cannot open secret')
		expect(wrapper.get('.empty-desc').text()).toBe('Types down')
		expect(wrapper.find('.secret-detail__card').exists()).toBe(false)
	})

	it('falls back to a generic message when the catalogue error carries none', async () => {
		useSecretTypeStore().fetchTypes = vi.fn().mockRejectedValue(new Error('x'))
		const wrapper = await mountSecret()

		expect(wrapper.vm.error).toBe('Failed to load secret')
	})

	it('says the secret is locked when its load is refused', async () => {
		const wrapper = await mountSecret()
		useSecretStore().fetchSecret = vi
			.fn()
			.mockRejectedValue({ response: { status: 428 } })
		await wrapper.vm.load()

		expect(wrapper.vm.error).toBe(
			'This secret is locked because its encryption suite was revoked.',
		)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows the server message, else the fallback, for any other load failure', async () => {
		const wrapper = await mountSecret()
		useSecretStore().fetchSecret = vi
			.fn()
			.mockRejectedValue({ response: { status: 500, data: { message: 'Boom' } } })
		await wrapper.vm.load()
		expect(wrapper.vm.error).toBe('Boom')

		useSecretStore().fetchSecret = vi.fn().mockRejectedValue(new Error('net'))
		await wrapper.vm.load()
		expect(wrapper.vm.error).toBe('Failed to load secret')
	})
})

describe('header actions', () => {
	it('opens the share dialog for the secret', async () => {
		const wrapper = await mountSecret()
		await find(wrapper, 'secret-detail-share').trigger('click')

		expect(openModal).toHaveBeenCalledWith('secret-share', { secretId: 's1' })
	})

	it('opens the delete dialog and closes once it reports the delete', async () => {
		const wrapper = await mountSecret()
		await find(wrapper, 'secret-detail-delete').trigger('click')

		const [name, props] = openModal.mock.calls[0]
		expect(name).toBe('secret-delete')
		expect(props.secretId).toBe('s1')
		expect(wrapper.emitted('close')).toBeUndefined()
		props.onDeleted()
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('closes from the menu and from the native close', async () => {
		const wrapper = await mountSecret()
		await find(wrapper, 'secret-detail-close').trigger('click')
		await wrapper.get('.native-close').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('stars the secret through the store and reflects it', async () => {
		const wrapper = await mountSecret()
		const setFavourite = vi.fn().mockResolvedValue()
		useSecretStore().setFavourite = setFavourite
		await find(wrapper, 'secret-detail-star').trigger('click')
		await flush()

		expect(setFavourite).toHaveBeenCalledWith('s1', true)
		expect(wrapper.vm.secret.favourite).toBe(true)
		expect(wrapper.vm.isFavourite).toBe(true)
	})

	it('unstars a favourite, and says so when the star fails', async () => {
		const wrapper = await mountSecret({ favourite: true })
		const setFavourite = vi.fn().mockRejectedValue(new Error('no'))
		useSecretStore().setFavourite = setFavourite
		await find(wrapper, 'secret-detail-star').trigger('click')
		await flush()

		expect(setFavourite).toHaveBeenCalledWith('s1', false)
		expect(wrapper.vm.secret.favourite).toBe(true)
		expect(showError).toHaveBeenCalledWith('Could not change the favourite')
	})

	it('archives the secret and closes the sidebar', async () => {
		const wrapper = await mountSecret()
		const change = vi.fn().mockResolvedValue()
		useSecretStore().changeSecretState = change
		expect(find(wrapper, 'secret-detail-archive').text()).toBe('Archive')
		await find(wrapper, 'secret-detail-archive').trigger('click')
		await flush()

		expect(change).toHaveBeenCalledWith('s1', 'archive')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('unarchives an archived secret', async () => {
		const wrapper = await mountSecret({ archivedAt: '2026-10-02T10:00:00Z' })
		const change = vi.fn().mockResolvedValue()
		useSecretStore().changeSecretState = change
		expect(find(wrapper, 'secret-detail-archive').text()).toBe('Unarchive')
		await find(wrapper, 'secret-detail-archive').trigger('click')
		await flush()

		expect(change).toHaveBeenCalledWith('s1', 'unarchive')
	})

	it('keeps the sidebar open and names the action that failed', async () => {
		for (const [fields, message] of [
			[{}, 'Could not archive the secret'],
			[{ archivedAt: '2026-10-02T10:00:00Z' }, 'Could not unarchive the secret'],
		]) {
			vi.mocked(showError).mockClear()
			const wrapper = await mountSecret(fields)
			useSecretStore().changeSecretState = vi.fn().mockRejectedValue(new Error('no'))
			await find(wrapper, 'secret-detail-archive').trigger('click')
			await flush()

			expect(showError).toHaveBeenCalledWith(message)
			expect(wrapper.emitted('close')).toBeUndefined()
			wrapper.unmount()
		}
	})

	it('reloads after an edit and warns when the list behind it cannot refresh', async () => {
		const wrapper = await mountSecret()
		const fetchSecrets = vi.fn().mockRejectedValue(new Error('down'))
		useSecretStore().fetchSecrets = fetchSecrets
		await find(wrapper, 'secret-detail-edit').trigger('click')
		const [name, props] = openModal.mock.calls[0]
		expect(name).toBe('secret-edit')
		props.onSaved()
		await flush()

		expect(useSecretStore().fetchSecret).toHaveBeenCalledTimes(2)
		expect(fetchSecrets).toHaveBeenCalledWith()
		expect(showError).toHaveBeenCalledWith(
			'Could not refresh the list — it may be out of date',
		)
	})
})

describe('masked rows', () => {
	it('reveals and hides each card field on its own', async () => {
		const wrapper = await mountSecret({
			typeId: 'type-card',
			key: JSON.stringify({ number: '4111111111111111', cvv: '123', pin: '9876', cardholder: 'Alice' }),
		})
		const text = (id) => find(wrapper, id).text()
		expect(text('card-cvv-value')).toBe('•••')
		expect(text('card-pin-value')).not.toContain('9876')

		await find(wrapper, 'card-reveal-cvv').trigger('click')
		expect(text('card-cvv-value')).toBe('123')
		expect(text('card-pin-value')).not.toContain('9876')
		expect(wrapper.vm.cardRevealed).toEqual({ number: false, cvv: true, pin: false })

		await find(wrapper, 'card-reveal-pin').trigger('click')
		expect(text('card-pin-value')).toBe('9876')

		await find(wrapper, 'card-reveal-number').trigger('click')
		expect(text('card-number-value')).toBe('4111 1111 1111 1111')

		await find(wrapper, 'card-reveal-cvv').trigger('click')
		expect(text('card-cvv-value')).toBe('•••')
		expect(wrapper.vm.cardRevealed).toEqual({ number: true, cvv: false, pin: true })
		expect(wrapper.find('.key-field').exists()).toBe(false)
	})

	it("reveals and hides an identity's BSN", async () => {
		const wrapper = await mountSecret({
			typeId: 'type-identity',
			key: JSON.stringify({ firstName: 'Alice', lastName: 'Smit', bsn: '111222333', address: 'Main 1' }),
		})
		expect(find(wrapper, 'identity-name').text()).toBe('Alice Smit')
		expect(find(wrapper, 'identity-bsn-value').text()).toBe('•••••••••')

		await find(wrapper, 'identity-reveal-bsn').trigger('click')
		expect(find(wrapper, 'identity-bsn-value').text()).toBe('111222333')

		await find(wrapper, 'identity-reveal-bsn').trigger('click')
		expect(find(wrapper, 'identity-bsn-value').text()).toBe('•••••••••')
	})

	it('falls back to the raw key row for an identity that does not parse', async () => {
		const wrapper = await mountSecret({ typeId: 'type-identity', key: 'plain' })

		expect(find(wrapper, 'secret-detail-identity').exists()).toBe(false)
		expect(wrapper.get('.key-field').attributes('aria-label')).toBe('Key')
		await expect(wrapper.vm.resolveKey()).resolves.toBe('plain')
	})
})

describe('state notes', () => {
	it('names the day access ends on an expiring copy, and nothing for a bad date', async () => {
		const end = '2026-12-31T12:00:00Z'
		const wrapper = await mountSecret({ accessExpiresAt: end })
		const day = new Date(end).toLocaleDateString()

		expect(wrapper.vm.accessEndsOn).toBe(day)
		expect(wrapper.text()).toContain(`Your access ends on ${day}`)

		wrapper.vm.secret = { ...wrapper.vm.secret, accessExpiresAt: 'not-a-date' }
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.accessEndsOn).toBe('')
		expect(wrapper.text()).not.toContain('Your access ends on')
	})

	it('offline with edits queued: says edits sync later, keeps move and delete, drops archive and star', async () => {
		const offline = useOfflineStore()
		offline.servedFromCache = true
		offline.editsEnabled = true
		const wrapper = await mountSecret()

		expect(find(wrapper, 'secret-detail-offline-note').text()).toMatch(
			/^Offline\. Edits, moves and deletes stay on this device/,
		)
		expect(find(wrapper, 'secret-detail-edit').exists()).toBe(true)
		expect(find(wrapper, 'secret-detail-move').exists()).toBe(true)
		expect(find(wrapper, 'secret-detail-delete').exists()).toBe(true)
		expect(find(wrapper, 'secret-detail-archive').exists()).toBe(false)
		expect(find(wrapper, 'secret-detail-star').exists()).toBe(false)
		expect(find(wrapper, 'secret-detail-share').attributes('disabled')).toBeDefined()
	})

	it('offline read-only: says so and leaves only Close in the menu', async () => {
		useOfflineStore().servedFromCache = true
		const wrapper = await mountSecret()

		expect(find(wrapper, 'secret-detail-offline-note').text()).toContain(
			'Read-only while offline',
		)
		expect(find(wrapper, 'secret-detail-edit').exists()).toBe(false)
		expect(find(wrapper, 'secret-detail-move').exists()).toBe(false)
		expect(find(wrapper, 'secret-detail-delete').exists()).toBe(false)
		expect(find(wrapper, 'secret-detail-close').exists()).toBe(true)
	})

	it('classifies nothing while no secret is loaded', async () => {
		useSecretStore().fetchSecret = vi.fn(() => new Promise(() => {}))
		vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
		const wrapper = mount(SecretDetailSidebar, {
			props: { secretId: 's1' },
			global: { stubs },
		})
		await flush()

		expect(wrapper.vm.loading).toBe(true)
		expect(wrapper.vm.typeLabel).toBe('')
		for (const flag of ['isNote', 'isTotp', 'isPasskey', 'isCard', 'isIdentity', 'isOwner']) {
			expect(wrapper.vm[flag]).toBe(false)
		}
		expect(wrapper.vm.identityPayload).toBe(null)
		expect(wrapper.vm.cardPayload).toBe(null)
		await expect(wrapper.vm.resolveKey()).resolves.toBe('')
	})
})

describe('requests and delegation', () => {
	it('opens the re-request dialog for a secret with a value and closes it on request', async () => {
		const wrapper = await mountSecret()
		const create = find(wrapper, 'secret-detail-request-create')
		expect(create.text()).toBe('Ask for new values')
		expect(wrapper.find('.request-dialog').exists()).toBe(false)

		await create.trigger('click')
		const dialog = wrapper.findComponent(stubs.SecretRequestCreateDialog)
		expect(dialog.props()).toEqual({ open: true, isReRequest: true })

		await wrapper.get('.request-dialog-close').trigger('click')
		expect(wrapper.vm.requestDialogOpen).toBe(false)
		expect(wrapper.find('.request-dialog').exists()).toBe(false)
	})

	it('asks to fill in an empty secret', async () => {
		const wrapper = await mountSecret({ key: '' })
		expect(find(wrapper, 'secret-detail-request-create').text()).toBe(
			'Ask someone to fill this in',
		)
	})

	it('reloads the detail after a delegation reclaim', async () => {
		const wrapper = await mountSecret()
		expect(useSecretStore().fetchSecret).toHaveBeenCalledTimes(1)
		await wrapper.get('.reclaim').trigger('click')
		await flush()

		expect(useSecretStore().fetchSecret).toHaveBeenCalledTimes(2)
		expect(useSecretStore().fetchSecret).toHaveBeenLastCalledWith('s1')
	})
})

describe('passkey delete edges', () => {
	it('offline, saves against the source version cached for the secret', async () => {
		const offline = useOfflineStore()
		offline.servedFromCache = true
		offline.editsEnabled = true
		offline.vault = {
			secrets: [
				{ id: 's9', sourceUpdatedAt: 'other' },
				{ id: 's1', sourceUpdatedAt: '2026-09-30T08:00:00+00:00' },
			],
		}
		const wrapper = await mountSecret()
		const store = useSecretStore()
		store.writeBackSettled = vi.fn().mockResolvedValue({ sourceUpdatedAt: 'ignored' })
		store.updateSecret = vi.fn().mockResolvedValue({})
		store.fetchSecrets = vi.fn().mockResolvedValue()
		await wrapper.vm.onDeletePasskey('')

		expect(store.updateSecret).toHaveBeenCalledWith(
			's1',
			{ passkeys: '' },
			{
				baseUpdatedAt: '2026-10-01T10:00:00+00:00',
				sourceBaseUpdatedAt: '2026-09-30T08:00:00+00:00',
			},
		)
		expect(wrapper.vm.passkeySaving).toBe(false)
	})

	it('does nothing while a delete is already saving, or where the value may not change', async () => {
		const wrapper = await mountSecret()
		const store = useSecretStore()
		store.writeBackSettled = vi.fn()
		store.updateSecret = vi.fn()

		wrapper.vm.passkeySaving = true
		await wrapper.vm.onDeletePasskey('')
		wrapper.vm.passkeySaving = false
		wrapper.vm.secret = { ...wrapper.vm.secret, useOnly: true }
		await wrapper.vm.onDeletePasskey('')

		expect(store.writeBackSettled).not.toHaveBeenCalled()
		expect(store.updateSecret).not.toHaveBeenCalled()
	})

	it('focuses the card when neither the passkey list nor Edit remain', async () => {
		const wrapper = await mountSecret({ useOnly: true })
		expect(find(wrapper, 'secret-detail-edit').exists()).toBe(false)
		wrapper.vm.focusAfterPasskeyDelete()

		const card = wrapper.get('.secret-detail__card').element
		expect(card.getAttribute('tabindex')).toBe('-1')
		expect(document.activeElement).toBe(card)
	})
})
