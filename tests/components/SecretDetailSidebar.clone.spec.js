/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Clone in the detail sidebar's More menu (vault-item-clone-preview-and-print,
 * task 1.2): it opens the create dialog prefilled from the decrypted secret,
 * and is not offered for a passkey item, a use-only copy or while offline.
 * Options-object style, like SecretDetailSidebar.dialogs.spec.js.
 *
 * @spec openspec/changes/vault-item-clone-preview-and-print/specs/secret-item-actions/spec.md#requirement-clone-a-secret
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'

const GRAFANA = {
	id: 's1',
	typeId: 'login',
	name: 'Grafana staging',
	url: 'https://grafana.staging.example',
	login: 'ops',
	key: 'Xk9#mQ2$vL7@pR4!zT6&',
	additionalFields: { team: 'platform' },
	folderId: 'f1',
	totp: 'JBSWY3DPEHPK3PXP',
	passkeys: '[{"id":"pk"}]',
	tags: ['ops'],
	shares: [{ userId: 'bob' }],
}

function sidebar(secret) {
	const ctx = { ...SecretDetailSidebar.methods }
	return Object.assign(ctx, {
		secretId: 's1',
		secret,
		cnOpenModal: vi.fn(),
		load: vi.fn(),
		refreshList: vi.fn(),
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('SecretDetailSidebar clone', () => {
	it('opens the create dialog with the fields a clone takes, in the same folder', () => {
		const ctx = sidebar(GRAFANA)
		ctx.openClone()

		const [name, props] = ctx.cnOpenModal.mock.calls[0]
		expect(name).toBe('secret-create')
		expect(props.folderId).toBe('f1')
		// Nothing beyond these: no one-time code, passkeys, tags, shares or ids.
		expect(props.prefill).toEqual({
			typeId: 'login',
			name: 'Grafana staging',
			url: 'https://grafana.staging.example',
			login: 'ops',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			additionalFields: { team: 'platform' },
		})
		props.onSaved()
		expect(ctx.load).toHaveBeenCalled()
	})

	it('does nothing before the secret is loaded', () => {
		const ctx = sidebar(null)
		ctx.openClone()
		expect(ctx.cnOpenModal).not.toHaveBeenCalled()
	})

	it('is offered for a visible secret online, and not for a passkey item, a use-only copy or offline', () => {
		const canClone = SecretDetailSidebar.computed.canClone
		const base = { secret: GRAFANA, offlineReadOnly: false, useOnly: false, isPasskey: false }
		expect(canClone.call(base)).toBe(true)
		expect(canClone.call({ ...base, isPasskey: true })).toBe(false)
		expect(canClone.call({ ...base, useOnly: true })).toBe(false)
		expect(canClone.call({ ...base, offlineReadOnly: true })).toBe(false)
		expect(canClone.call({ ...base, secret: null })).toBe(false)
	})
})
