<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin encryption-suite management section (admin-suite-revocation §D6).
  Lets an administrator force-revoke any suite by id — a required reason and a
  markCompromised toggle — and reinstate a revoked one. Force-revoke carries
  #[PasswordConfirmationRequired], so the Nextcloud sudo (password-confirmation)
  flow runs before the request; reinstate carries the same sudo. Only
  the destroyed-usable emergency-contact count crosses the wire, never contact
  identities.

  @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Encryption suites')"
		:description="
			t(
				'keepiq',
				'Force-revoke a user- or application-owned encryption suite by id when its owner cannot (a forgotten master password, a de-authorised departure, or a compromise), and reinstate a revoked one. Force-revocation asks you to re-confirm your own password and permanently clears the suite\'s emergency access.',
			)
		">
		<div class="admin-suite" data-testid="admin-suite-section">
			<div class="admin-suite__form">
				<NcTextField
					v-model="suiteId"
					:label="t('keepiq', 'Suite ID')"
					:disabled="busy"
					data-testid="admin-suite-id" />

				<NcTextField
					v-model="reason"
					:label="t('keepiq', 'Reason for revocation')"
					:placeholder="
						t('keepiq', 'e.g. Offboarding, device lost, key compromised')
					"
					:disabled="busy"
					data-testid="admin-suite-reason" />

				<NcCheckboxRadioSwitch
					v-model="markCompromised"
					type="switch"
					:disabled="busy"
					data-testid="admin-suite-compromised">
					{{
						t(
							'keepiq',
							"Treat the suite's secrets as compromised (flag for rotation and notify owners)",
						)
					}}
				</NcCheckboxRadioSwitch>

				<NcNoteCard
					v-if="enrolledInRecovery"
					type="warning"
					data-testid="admin-suite-recovery-warning">
					{{
						t(
							'keepiq',
							'This user is enrolled in account recovery. Recovering keeps their secrets; revoking deletes their enrolment.',
						)
					}}
				</NcNoteCard>
				<NcButton
					variant="error"
					:disabled="!suiteId || !reason || busy"
					data-testid="admin-suite-force-revoke"
					@click="onForceRevoke">
					{{
						busy
							? t('keepiq', 'Revoking…')
							: t('keepiq', 'Force-revoke suite')
					}}
				</NcButton>
			</div>

			<NcNoteCard v-if="error" type="error" data-testid="admin-suite-error">
				{{ error }}
			</NcNoteCard>

			<!-- Result of the last force-revoke: the destroyed-usable count is
			     always informational (never a gate), and when compromise was NOT
			     marked the server returns the rotation-may-be-warranted copy. -->
			<template v-if="result">
				<NcNoteCard
					type="warning"
					data-testid="admin-suite-emergency-warning">
					{{
						n(
							'keepiq',
							'Revoking this suite deleted %n emergency-access contact.',
							'Revoking this suite deleted %n emergency-access contacts.',
							emergencyContactsDestroyed,
						)
					}}
				</NcNoteCard>

				<NcNoteCard
					v-if="warning"
					type="warning"
					data-testid="admin-suite-compromise-warning">
					{{ warning }}
				</NcNoteCard>

				<!-- Part of the compromise response failed: the suite is revoked,
				     but some owners may be unwarned or some cleanup undone
				     (keepiq#863). Running the force-revoke again repeats it. -->
				<NcNoteCard
					v-if="cascadeIncomplete"
					type="error"
					data-testid="admin-suite-cascade-incomplete">
					{{
						t(
							'keepiq',
							'Part of the compromise response failed ({failed} step(s)). Check the server log, then force-revoke the suite again to finish it.',
							{ failed: cascadeFailed },
						)
					}}
				</NcNoteCard>

				<!-- A compromise revoke during a key migration also revoked the
				     migration's other suite and ended the migration (keepiq#877). -->
				<NcNoteCard
					v-if="alsoRevokedSuite"
					type="warning"
					data-testid="admin-suite-also-revoked">
					<p>
						{{
							t(
								'keepiq',
								'This also revoked suite {suite} and ended key migration {migration}.',
								{
									suite: alsoRevokedSuite,
									migration: terminatedMigration || '',
								},
							)
						}}
					</p>
					<p>
						{{
							n(
								'keepiq',
								'Revoking the second suite deleted %n emergency-access contact.',
								'Revoking the second suite deleted %n emergency-access contacts.',
								alsoRevokedEmergencyContactsDestroyed,
							)
						}}
					</p>
				</NcNoteCard>

				<div class="admin-suite__result" data-testid="admin-suite-result">
					<p>
						<strong>{{ t('keepiq', 'Suite ID') }}:</strong>
						{{ result.id }}
					</p>
					<p>
						<strong>{{ t('keepiq', 'Owner') }}:</strong>
						{{ result.ownerId }} ({{ result.ownerType }})
					</p>
					<p>
						<strong>{{ t('keepiq', 'Status') }}:</strong>
						{{ result.status }}
					</p>

					<!-- Reinstate is shown only for a suite in `revoked` status,
					     mirroring reinstateSuite()'s own precondition, and never
					     after a compromise revoke: the server refuses that, and
					     one click must not undo a containment (keepiq#865). -->
					<NcButton
						v-if="result.status === 'revoked' && !revokedAsCompromise"
						variant="secondary"
						:disabled="busy"
						data-testid="admin-suite-reinstate"
						@click="onReinstate">
						{{ t('keepiq', 'Reinstate suite') }}
					</NcButton>
					<p
						v-else-if="revokedAsCompromise"
						data-testid="admin-suite-no-reinstate">
						{{
							t(
								'keepiq',
								'A suite revoked as compromised cannot be reinstated.',
							)
						}}
					</p>
				</div>
			</template>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { useEncryptionSuiteStore } from '../../store/modules/encryptionSuite.js'
import { useMemberOverviewStore } from '../../store/modules/memberOverview.js'

/**
 * Admin encryption-suite management section: force-revoke + reinstate.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
export default {
	name: 'AdminSuiteSection',

	components: {
		CnSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	data() {
		return {
			suiteId: '',
			reason: '',
			markCompromised: false,
			busy: false,
			error: null,
			result: null,
			emergencyContactsDestroyed: 0,
			warning: null,
			revokedAsCompromise: false,
			alsoRevokedSuite: null,
			terminatedMigration: null,
			alsoRevokedEmergencyContactsDestroyed: 0,
			cascadeIncomplete: false,
			cascadeFailed: 0,
			/** Whether the suite's owner is enrolled in account recovery. */
			enrolledInRecovery: false,
		}
	},

	computed: {
		/**
		 * The member overview store, which carries the prefill from a row.
		 *
		 * @return {object}
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.2
		 */
		memberStore() {
			return useMemberOverviewStore()
		},
	},

	watch: {
		/**
		 * A Members row chose "Revoke suite": put its active suite id in the
		 * suite id field, so the administrator never types it.
		 *
		 * @param {string} suiteId The suite handed over by the list.
		 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#3.2
		 */
		'memberStore.revokeSuiteId': function (suiteId) {
			if (suiteId) {
				this.suiteId = suiteId
			}
		},

		/**
		 * Look up the account recovery enrolment of the entered suite, so the
		 * warning shows before the force-revoke action
		 * (crypto-organisation-account-recovery D8).
		 *
		 * @param {string} id The suite id typed so far.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-force-revocation-warns-about-enrolled-users
		 */
		async suiteId(id) {
			this.enrolledInRecovery = false
			const trimmed = (id ?? '').trim()
			if (trimmed.length < 8) {
				return
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/recovery/admin/enrolled'),
					{ params: { suiteId: trimmed } },
				)
				if (this.suiteId.trim() === trimmed) {
					this.enrolledInRecovery = response.data?.enrolled === true
				}
			} catch {
				this.enrolledInRecovery = false
			}
		},
	},

	methods: {
		/**
		 * Force-revoke the entered suite. The store runs the Nextcloud sudo
		 * (password-confirmation) flow before the request because the endpoint
		 * carries `#[PasswordConfirmationRequired]`. On success the returned
		 * suite plus the destroyed-usable emergency-contact count and (when
		 * compromise was not marked) the rotation warning are surfaced.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
		 */
		async onForceRevoke() {
			this.busy = true
			this.error = null
			try {
				const outcome = await useEncryptionSuiteStore().forceRevokeSuite({
					id: this.suiteId,
					reason: this.reason,
					markCompromised: this.markCompromised,
				})
				this.result = outcome.suite
				this.emergencyContactsDestroyed = outcome.emergencyContactsDestroyed
				this.warning = outcome.warning
				this.revokedAsCompromise = this.markCompromised
				this.alsoRevokedSuite = outcome.alsoRevokedSuite ?? null
				this.terminatedMigration = outcome.terminatedMigration ?? null
				this.alsoRevokedEmergencyContactsDestroyed =
					outcome.alsoRevokedEmergencyContactsDestroyed ?? 0
				this.cascadeIncomplete = outcome.cascadeIncomplete === true
				this.cascadeFailed = outcome.cascadeFailed ?? 0
			} catch (e) {
				// A cancelled sudo prompt or a server refusal must surface, never
				// be swallowed into a silent success. Contact identities never
				// cross the wire, so nothing sensitive is shown here.
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to force-revoke suite')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reinstate the last-revoked suite via the admin-only reinstate endpoint.
		 * The store runs the sudo flow first (keepiq#865). Refreshes the rendered
		 * result with the reinstated suite.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
		 */
		async onReinstate() {
			this.busy = true
			this.error = null
			try {
				this.result = await useEncryptionSuiteStore().reinstateSuiteAdmin(
					this.result.id,
				)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to reinstate suite')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.admin-suite {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 600px;

	&__form {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	&__result {
		display: flex;
		flex-direction: column;
		gap: 4px;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}
}
</style>
