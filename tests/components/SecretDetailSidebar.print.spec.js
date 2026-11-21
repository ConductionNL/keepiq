/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Print and Show as QR code in the detail sidebar's More menu
 * (vault-item-clone-preview-and-print tasks 3.1 and 3.2): offered on a login
 * with a password the user may reveal, they open their modal with only the
 * fields it shows, never the one-time code seed or passkeys. Options-object
 * style, like SecretDetailSidebar.clone.spec.js.
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const GUEST_WIFI = {
	id: 's1',
	typeId: 't-login',
	name: 'Guest Wi-Fi',
	url: 'https://router.example',
	login: 'guest',
	key: 'gast-wifi-2026!',
	totp: 'JBSWY3DPEHPK3PXP',
	passkeys: '[{"id":"pk"}]',
}

function sidebar(secret, extra = {}) {
	const ctx = { ...SecretDetailSidebar.methods }
	return Object.assign(ctx, {
		secret,
		useOnly: false,
		canPrintOrShowQr: true,
		revealGuard: false,
		allowedToReveal: vi.fn(),
		cnOpenModal: vi.fn(),
		...extra,
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	useSecretTypeStore().types = [
		{ id: 't-login', name: 'login' },
		{ id: 't-note', name: 'note' },
	]
})

describe('SecretDetailSidebar print and QR', () => {
	it('opens Print with the name, address, username and password only', () => {
		const ctx = sidebar(GUEST_WIFI)
		ctx.openPrint()

		expect(ctx.cnOpenModal).toHaveBeenCalledWith('secret-print', {
			name: 'Guest Wi-Fi',
			url: 'https://router.example',
			login: 'guest',
			password: 'gast-wifi-2026!',
		})
	})

	it('opens Show as QR code with the name and password only', () => {
		const ctx = sidebar(GUEST_WIFI)
		ctx.openQr()

		expect(ctx.cnOpenModal).toHaveBeenCalledWith('secret-qr', {
			secretName: 'Guest Wi-Fi',
			password: 'gast-wifi-2026!',
		})
	})

	it('answers a flagged item\'s re-prompt first, and opens nothing when it is refused', async () => {
		const refused = sidebar(GUEST_WIFI, { revealGuard: true, allowedToReveal: vi.fn().mockResolvedValue(false) })
		await refused.openQr()
		expect(refused.allowedToReveal).toHaveBeenCalled()
		expect(refused.cnOpenModal).not.toHaveBeenCalled()

		const allowed = sidebar(GUEST_WIFI, { revealGuard: true, allowedToReveal: vi.fn().mockResolvedValue(true) })
		await allowed.openPrint()
		expect(allowed.cnOpenModal).toHaveBeenCalledWith('secret-print', expect.objectContaining({ password: 'gast-wifi-2026!' }))
	})

	it('opens nothing when the actions are not offered', () => {
		const ctx = sidebar(GUEST_WIFI, { canPrintOrShowQr: false })
		ctx.openPrint()
		ctx.openQr()
		expect(ctx.cnOpenModal).not.toHaveBeenCalled()
	})

	it('is offered on a login with a password, and not on a use-only copy, another type or an empty password', async () => {
		const typeStore = useSecretTypeStore()
		const canPrintOrShowQr = SecretDetailSidebar.computed.canPrintOrShowQr
		expect(typeStore.typesById['t-login']).toBeDefined()

		expect(canPrintOrShowQr.call({ secret: GUEST_WIFI, useOnly: false })).toBe(true)
		expect(canPrintOrShowQr.call({ secret: GUEST_WIFI, useOnly: true })).toBe(false)
		expect(canPrintOrShowQr.call({ secret: { ...GUEST_WIFI, typeId: 't-note' }, useOnly: false })).toBe(false)
		expect(canPrintOrShowQr.call({ secret: { ...GUEST_WIFI, key: '' }, useOnly: false })).toBe(false)
		expect(canPrintOrShowQr.call({ secret: null, useOnly: false })).toBe(false)
	})
})
