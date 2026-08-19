/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/ApplicationRequestRevokeDialog.vue`.
 *
 * The dialog exists because an administrator revoking an application's request is
 * not the same act as a user revoking their own: the fill link may already be in
 * someone's inbox, and the application may be waiting on the credential. So the
 * things worth pinning are that it warns about the consequence and that it names
 * the requested fields — "revoke request" tells an administrator nothing about
 * what they are interrupting.
 *
 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ApplicationRequestRevokeDialog from '../../src/dialogs/ApplicationRequestRevokeDialog.vue'

function mountDialog (props = {}) {
  return mount(ApplicationRequestRevokeDialog, {
		propsData: { open: true, ...props },
		global: {
			// The shared t() stub returns the key and drops the vars, so `{fields}`
			// would never expand and the assertion below could not prove the field
			// names reach the translator at all. Interpolating here keeps the test
			// about the component instead of about the stub.
			// A per-mount mixin, not `mocks`: the shared setup supplies t() as a
			// global MIXIN method, and a mixin method outranks a mocked global
			// property for `_ctx.t(...)` in a compiled template.
			mixins: [
				{
					methods: {
						t: (_app, key, vars) =>
							Object.entries(vars || {}).reduce(
								(acc, [name, value]) => acc.replace(`{${name}}`, value),
								key,
							),
					},
				},
			],
			stubs: {
				// NcDialog teleports its body; stubbing keeps the assertions on this
				// component's own markup rather than on the library's portal.
				NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				// No explicit $emit('click') here: attribute fallthrough already
				// delivers the parent's handler, and emitting as well fired it twice.
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
}

describe('ApplicationRequestRevokeDialog', () => {
	it('warns that the link dies immediately and the integration may break', () => {
		const wrapper = mountDialog()

		const warning = wrapper.find(
			'[data-testid="application-request-revoke-warning"]',
		)
		expect(warning.exists()).toBe(true)
		expect(warning.text()).toContain('stops working immediately')
		expect(warning.text()).toContain('integration')
	})

	it('names the requested fields, so the admin knows what is interrupted', () => {
		const wrapper = mountDialog({ requestedFields: ['key', 'login'] })

		expect(
			wrapper.find('[data-testid="application-request-revoke-fields"]').text(),
		).toContain('key, login')
	})

	it('omits the field line when there are no fields to name', () => {
		// Rather than rendering an empty "This application asked for:" sentence.
		const wrapper = mountDialog({ requestedFields: [] })

		expect(
			wrapper.find('[data-testid="application-request-revoke-fields"]').exists(),
		).toBe(false)
	})

	it('emits confirm and close from the two actions', async () => {
		const wrapper = mountDialog({ requestedFields: ['key'] })

		await wrapper
			.find('[data-testid="application-request-revoke-confirm"]')
			.trigger('click')
		await wrapper
			.find('[data-testid="application-request-revoke-cancel"]')
			.trigger('click')

		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
