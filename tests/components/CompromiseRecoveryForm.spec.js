/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/CompromiseRecoveryForm.vue`.
 *
 * This dialog is the only surface a user sees during compromise recovery, and
 * the spec constrains what it is allowed to say. It MUST:
 *  - state, before the user confirms, that every stored value must be changed
 *    at its source, and that rotating the key restores ACCESS rather than
 *    making anything safe
 *  - never claim the vault is secure afterwards
 *  - show live progress across all stores while running, and not report an
 *    outcome until the migration has actually terminated
 *  - name the secrets that would lose access, and only lock the old key from
 *    an explicit click that says how many are affected
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CompromiseRecoveryForm from '../../src/components/CompromiseRecoveryForm.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useSessionStore } from '../../src/store/modules/session.js'

/**
 * Mount the form with the Nextcloud component surface stubbed out, so the test
 * asserts on this component's own behaviour rather than on @nextcloud/vue.
 *
 * @return {object} The mounted wrapper.
 */
function mountForm() {
	return mount(CompromiseRecoveryForm, {
		global: {
			// tests/vitest/setup.js installs t/n stubs that return the key
			// verbatim, which is fine for most specs but hides the counts this
			// dialog is judged on. A mount-level mixin wins over the global one,
			// so interpolate here to assert on what the user actually reads.
			mixins: [
				{
					methods: {
						t: (app, text, vars) => {
							if (!vars) {
								return text
							}
							return Object.keys(vars).reduce(
								(out, key) =>
									out.replace(`{${key}}`, String(vars[key])),
								text,
							)
						},
						n: (app, singular, plural, count) =>
							(count === 1 ? singular : plural).replace(
								'%n',
								String(count),
							),
					},
				},
			],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div class="note-card"><slot /></div>' },
				NcPasswordField: { template: '<input />' },
				NcProgressBar: { template: '<div class="progress-bar" />' },
				PasswordStrengthMeter: true,
				NcCheckboxRadioSwitch: {
					props: ['modelValue'],
					emits: ['update:modelValue'],
					template:
						'<label class="carry"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
				},
			},
		},
	})
}

describe('CompromiseRecoveryForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	// keepiq#800: a compromise recovery runs exactly when someone else may have
	// held the session, and carrying a contact hands them the NEW key. So the
	// owner picks, nothing is preselected, and only the ticked ones go through.
	describe('emergency contacts to carry', () => {
		const contacts = [
			{
				id: 'rel-1',
				granteeUserId: 'bob',
				state: 'granted',
				waitPeriodDays: 7,
			},
			{
				id: 'rel-2',
				granteeUserId: 'carol',
				state: 'granted',
				waitPeriodDays: 1,
			},
		]

		beforeEach(() => {
			useSessionStore().suiteId = 'old-suite'
		})

		it('lists the carriable contacts with none preselected', async () => {
			const store = useEncryptionSuiteStore()
			const list = vi
				.spyOn(store, 'listCarriableEmergencyContacts')
				.mockResolvedValue(contacts)

			const wrapper = mountForm()
			await flushPromises()

			expect(list).toHaveBeenCalledWith('old-suite')
			const items = wrapper.findAll(
				'[data-testid="compromise-recovery-carry-item"]',
			)
			expect(items.map((i) => i.text())).toEqual([
				expect.stringContaining('bob'),
				expect.stringContaining('carol'),
			])
			expect(
				items.every((i) => i.find('input').element.checked === false),
			).toBe(true)
		})

		it('carries only the contacts the owner ticked', async () => {
			const store = useEncryptionSuiteStore()
			vi.spyOn(store, 'listCarriableEmergencyContacts').mockResolvedValue(
				contacts,
			)
			const initiate = vi
				.spyOn(store, 'initiateCompromiseRecovery')
				.mockResolvedValue({
					migrated: 0,
					droppedVersions: 0,
					failures: [],
					residualContacts: [
						{ granteeUserId: 'bob', reason: 'not_confirmed' },
					],
				})

			const wrapper = mountForm()
			await flushPromises()
			const carol = wrapper.findAll(
				'[data-testid="compromise-recovery-carry-item"]',
			)[1]
			await carol.find('input').setValue(true)

			wrapper.vm.oldPassword = 'old'
			wrapper.vm.newPassword = 'new'
			await wrapper.vm.handleSubmit()

			expect(initiate).toHaveBeenCalledWith('old', 'new', ['rel-2'])
		})

		it('shows no list when there is nothing to carry', async () => {
			const store = useEncryptionSuiteStore()
			vi.spyOn(store, 'listCarriableEmergencyContacts').mockResolvedValue([])

			const wrapper = mountForm()
			await flushPromises()

			expect(
				wrapper.find('[data-testid="compromise-recovery-carry"]').exists(),
			).toBe(false)
		})
	})

	it('warns before confirming that values must be changed at their source', () => {
		const text = mountForm().text()

		expect(text).toContain('must be assumed to have been exposed')
		expect(text).toContain('changed at its source')
		// The distinction the spec insists on: access, not safety.
		expect(text).toContain('restores access')
		expect(text).toContain('does not make the old values safe')
	})

	it('never claims the vault is now secure', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = { migrated: 12, droppedVersions: 0, failures: [] }
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		expect(text).toContain('12 secrets were re-encrypted')
		expect(text).toContain('still to be considered exposed')
		// The message this change exists to delete.
		expect(text).not.toContain('now secured with a new encryption key')
		expect(text).not.toContain('vault is now secure')
	})

	it('reports dropped version history rather than losing it quietly', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = { migrated: 3, droppedVersions: 7, failures: [] }
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('7 older versions were dropped')
	})

	it('prompts to re-establish only the contacts that could not be reached', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = {
			migrated: 3,
			droppedVersions: 0,
			failures: [],
			residualContacts: [
				{ granteeUserId: 'bob', reason: 'unreachable' },
				{ granteeUserId: 'carol', reason: 'unreachable' },
			],
		}
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		expect(text).toContain('2 contacts could not be carried across')
		expect(text).toContain('Re-establish')
		expect(text).toContain('bob')
		expect(text).toContain('carol')
	})

	// #804 review, round 4: a resumed rotation carries no contact, and the owner
	// must still be told which ones it removed, neutrally.
	it('names the contacts a resumed rotation removed, without a prompt', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = {
			migrated: 3,
			droppedVersions: 0,
			failures: [],
			residualContacts: [
				{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
				{ granteeUserId: 'mallory', reason: 'break_glass_in_flight' },
			],
		}
		await wrapper.vm.$nextTick()

		const removed = wrapper.find('[data-testid="compromise-recovery-removed"]')
		expect(removed.exists()).toBe(true)
		expect(removed.text()).toContain('bob')
		expect(removed.text()).toContain('Emergency Access')
		expect(
			wrapper.find('[data-testid="compromise-recovery-residual"]').exists(),
		).toBe(false)
		expect(wrapper.text()).not.toContain('Re-establish')
		expect(
			wrapper.find('[data-testid="compromise-recovery-in-flight"]').text(),
		).toContain('mallory')
	})

	// #804 review: an unticked or in-flight contact is the one a planted contact
	// would be, so the owner must not be nudged to re-add it.
	it('never nudges the owner to re-add an unconfirmed or in-flight contact', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = {
			migrated: 3,
			droppedVersions: 0,
			failures: [],
			residualContacts: [
				{ granteeUserId: 'dave', reason: 'not_confirmed' },
				{ granteeUserId: 'mallory', reason: 'break_glass_in_flight' },
			],
		}
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="compromise-recovery-residual"]').exists(),
		).toBe(false)
		expect(wrapper.text()).not.toContain('Re-establish')

		const unconfirmed = wrapper.find(
			'[data-testid="compromise-recovery-unconfirmed"]',
		)
		expect(unconfirmed.text()).toContain('dave')
		expect(unconfirmed.text()).toContain('did not confirm')

		const inFlight = wrapper.find(
			'[data-testid="compromise-recovery-in-flight"]',
		)
		expect(inFlight.text()).toContain('mallory')
		expect(inFlight.text()).toContain('added by someone else')
	})

	it('says nothing about emergency access when every contact migrated', async () => {
		const wrapper = mountForm()
		wrapper.vm.phase = 'terminal'
		wrapper.vm.result = {
			migrated: 3,
			droppedVersions: 0,
			failures: [],
			residualContacts: [],
		}
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="compromise-recovery-residual"]').exists(),
		).toBe(false)
		expect(wrapper.text()).not.toContain('could not be carried across')
	})

	it('shows live progress across all stores while running', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationProgress = { done: 4, total: 10, phase: 'migrating' }

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('4 of 10 records re-encrypted')
		expect(wrapper.vm.progressPercent).toBe(40)
		expect(wrapper.find('.progress-bar').exists()).toBe(true)
	})

	it('does not present an outcome while an acknowledgement is pending', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
		]

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		// The migration is still in progress, so no terminal wording.
		expect(wrapper.vm.phase).not.toBe('terminal')
		expect(wrapper.text()).not.toContain('Key rotation finished')
	})

	it('names the secrets that would lose access and says the data is kept', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
			{
				store: 'secrets',
				id: 'secret-2',
				name: 'nas-backup',
				error: 'secrets: could not decrypt',
			},
		]

		const wrapper = mountForm()
		await wrapper.vm.$nextTick()

		const text = wrapper.text()
		expect(text).toContain('router-admin')
		expect(text).toContain('nas-backup')
		expect(text).toContain('2 secrets could not be decrypted')
		// Locking the key is destructive of access, not of data — say so.
		expect(text).toContain('stored data is kept')
		// The destructive action must state its own cost.
		expect(text).toContain('losing access to 2 secrets')
		// And a non-destructive way out must be offered alongside it.
		expect(text).toContain('Try these again')
	})

	it("sends the SERVER's acknowledgement number, never its own list length", async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationFailures = [
			{
				store: 'secrets',
				id: 'secret-1',
				name: 'router-admin',
				error: 'secrets: could not decrypt',
			},
		]
		// The server said 4 — one head plus three of its versions, say — while
		// the client's own list holds 1 entry. Sending the list length was the
		// third blocker: the server compares with a strict `===`, so every
		// click was refused and the vault stayed write-locked with no way out.
		store.migrationRequiredAcknowledgement = 4

		const accept = vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({})

		const wrapper = mountForm()
		// The retained old password is passed so completion can build its
		// vault-key proof over the old key; the count still comes from the store.
		wrapper.vm.activeOldPassword = 'old-pw'
		await wrapper.vm.handleAcceptLosses()

		// Called with the id and the retained password: the action reads the
		// authoritative count from the store rather than being handed one here.
		expect(accept).toHaveBeenCalledWith('migration-1', 'old-pw')
		expect(wrapper.vm.phase).toBe('terminal')
	})

	// #804 review, round 5: a resumed rotation finished by accepting losses
	// completes here, so the contacts it removed are named here.
	it('names the contacts removed when a resumed rotation is finished by accepting losses', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 1
		vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({
			residualContacts: [
				{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
			],
		})

		const wrapper = mountForm()
		// The live resumed state (keepiq#880 item 3): the form is opened from
		// the banner, so this form started nothing. No result and no retained
		// password; the old password comes in through the re-auth field.
		wrapper.vm.result = null
		wrapper.vm.activeOldPassword = null
		wrapper.vm.oldPassword = 'old-pw'
		await wrapper.vm.handleAcceptLosses()
		store.migrationNeedsAcknowledgement = false
		await wrapper.vm.$nextTick()

		expect(store.acceptMigrationLosses).toHaveBeenCalledWith('migration-1', 'old-pw')
		const removed = wrapper.find('[data-testid="compromise-recovery-removed"]')
		expect(removed.exists()).toBe(true)
		expect(removed.text()).toContain('bob')
	})

	// keepiq#880 item 3: an initiate run whose own list came back empty (the
	// contacts could not be listed) is not a resumed run. Finishing it by
	// accepting a loss must not tell the owner their rotation "was resumed".
	it('does not call an initiate run with an empty list resumed', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 1
		vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({
			residualContacts: [
				{ granteeUserId: 'bob', reason: 'removed_by_rotation' },
			],
		})

		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-pw'
		wrapper.vm.result = {
			migrated: 2,
			droppedVersions: 0,
			failures: [],
			residualContacts: [],
		}
		await wrapper.vm.handleAcceptLosses()
		store.migrationNeedsAcknowledgement = false
		await wrapper.vm.$nextTick()

		const removed = wrapper.find('[data-testid="compromise-recovery-removed"]')
		expect(removed.exists()).toBe(true)
		expect(removed.text()).toContain('bob')
		expect(wrapper.text()).not.toContain('was resumed')
	})

	it('keeps the initiate list when an initiate run is finished by accepting losses', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 1
		vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({
			residualContacts: [
				{ granteeUserId: 'carol', reason: 'removed_by_rotation' },
			],
		})

		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-pw'
		wrapper.vm.result = {
			migrated: 2,
			droppedVersions: 0,
			failures: [],
			residualContacts: [{ granteeUserId: 'carol', reason: 'unreachable' }],
		}
		await wrapper.vm.handleAcceptLosses()
		store.migrationNeedsAcknowledgement = false
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="compromise-recovery-residual"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="compromise-recovery-removed"]').exists(),
		).toBe(false)
	})

	// #804 review, round 5: a retry resumes the run this form started, so the
	// owner's ticks still decide what the completion screen says.
	it('keeps the initiate list when a retry completes the rotation', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'resumeMigration').mockImplementation(async () => {
			store.migrationNeedsAcknowledgement = false
			return {
				migrated: 1,
				failed: 0,
				droppedVersions: 0,
				failures: [],
				residualContacts: [
					{ granteeUserId: 'carol', reason: 'removed_by_rotation' },
				],
			}
		})

		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-pw'
		wrapper.vm.result = {
			migrated: 2,
			failed: 1,
			droppedVersions: 0,
			failures: [],
			residualContacts: [{ granteeUserId: 'carol', reason: 'not_confirmed' }],
		}
		await wrapper.vm.handleRetry()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.phase).toBe('terminal')
		expect(
			wrapper.find('[data-testid="compromise-recovery-unconfirmed"]').text(),
		).toContain('carol')
		expect(
			wrapper.find('[data-testid="compromise-recovery-removed"]').exists(),
		).toBe(false)
	})

	// Pre-push check on the round-5 fixes: a break-glass requested while the
	// loss acknowledgement was pending must not stay labelled "not confirmed".
	it('warns about a contact whose break-glass started while a loss was pending', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 1
		vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({
			residualContacts: [
				{ granteeUserId: 'carol', reason: 'break_glass_in_flight' },
			],
		})

		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-pw'
		wrapper.vm.result = {
			migrated: 2,
			droppedVersions: 0,
			failures: [],
			residualContacts: [{ granteeUserId: 'carol', reason: 'not_confirmed' }],
		}
		await wrapper.vm.handleAcceptLosses()
		store.migrationNeedsAcknowledgement = false
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="compromise-recovery-in-flight"]').text(),
		).toContain('carol')
		expect(
			wrapper.find('[data-testid="compromise-recovery-unconfirmed"]').exists(),
		).toBe(false)
	})

	// An initiate run's screen must not grow resumed-rotation copy for a
	// contact only the read-back knows; the Emergency Access view names it.
	it('adds no read-back-only contact to an initiate run', async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 1
		vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({
			residualContacts: [
				{ granteeUserId: 'carol', reason: 'removed_by_rotation' },
				{ granteeUserId: 'dave', reason: 'removed_by_rotation' },
			],
		})

		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-pw'
		wrapper.vm.result = {
			migrated: 2,
			droppedVersions: 0,
			failures: [],
			residualContacts: [{ granteeUserId: 'carol', reason: 'unreachable' }],
		}
		await wrapper.vm.handleAcceptLosses()
		store.migrationNeedsAcknowledgement = false
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.result.residualContacts).toEqual([
			{ granteeUserId: 'carol', reason: 'unreachable' },
		])
		expect(
			wrapper.find('[data-testid="compromise-recovery-removed"]').exists(),
		).toBe(false)
	})

	it("shows the server's loss count even when the display list is capped", async () => {
		const store = useEncryptionSuiteStore()
		store.migrationStatus = { id: 'migration-1' }
		store.migrationNeedsAcknowledgement = true
		store.migrationRequiredAcknowledgement = 512
		store.migrationFailures = [
			{ store: 'secrets', id: 'secret-1', name: 'router-admin', error: 'x' },
		]

		const wrapper = mountForm()

		// The count the user is asked to accept is the real one, not the
		// length of a list that the server caps for display.
		expect(wrapper.vm.lossCount).toBe(512)
		expect(wrapper.vm.lossListTruncated).toBe(true)
	})

	it('labels a version failure instead of rendering a blank row', () => {
		const wrapper = mountForm()

		// Version and grant failures carry no secret name; the list used to
		// render an empty bullet directly above the "losing access" button.
		expect(
			wrapper.vm.describeRecord({ id: 'v-1', name: null, store: 'versions' }),
		).toContain('v-1')
		expect(
			wrapper.vm.describeRecord({
				id: 's-1',
				name: 'router-admin',
				store: 'secrets',
			}),
		).toBe('router-admin')
	})

	it('surfaces a halted run as an error and returns to the form', async () => {
		const store = useEncryptionSuiteStore()
		vi.spyOn(store, 'initiateCompromiseRecovery').mockRejectedValue(
			new Error('Re-encrypted key did not decrypt back to the original value'),
		)

		const wrapper = mountForm()
		await wrapper.vm.handleSubmit()

		expect(wrapper.vm.error).toContain('did not decrypt back')
		expect(wrapper.vm.phase).toBe('idle')
	})
})
