<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin encryption-suite management section (admin-suite-revocation §D6).
  Lets an administrator force-revoke any suite by id — a required reason and a
  markCompromised toggle — and reinstate a revoked one. Force-revoke carries
  #[PasswordConfirmationRequired], so the Nextcloud sudo (password-confirmation)
  flow runs before the request; reinstate is admin-guarded with no sudo. Only
  the destroyed-usable emergency-contact count crosses the wire, never contact
  identities.

  @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
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
					     mirroring reinstateSuite()'s own precondition. -->
					<NcButton
						v-if="result.status === 'revoked'"
						variant="secondary"
						:disabled="busy"
						data-testid="admin-suite-reinstate"
						@click="onReinstate">
						{{ t('keepiq', 'Reinstate suite') }}
					</NcButton>
				</div>
			</template>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { useEncryptionSuiteStore } from '../../store/modules/encryptionSuite.js'

/**
 * Admin encryption-suite management section: force-revoke + reinstate.
 *
 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
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
		}
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
		 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
		 */
		async onForceRevoke() {
			this.busy = true
			this.error = null
			try {
				const { suite, emergencyContactsDestroyed, warning } =
					await useEncryptionSuiteStore().forceRevokeSuite({
						id: this.suiteId,
						reason: this.reason,
						markCompromised: this.markCompromised,
					})
				this.result = suite
				this.emergencyContactsDestroyed = emergencyContactsDestroyed
				this.warning = warning
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
		 * Reinstate the last-revoked suite via the admin-only reinstate endpoint
		 * (no sudo). Refreshes the rendered result with the reinstated suite.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-suite-revocation/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
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
