<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for organisation account recovery (crypto-organisation-
  account-recovery D2, D5, D7): the policy, the officers, the threshold, and
  the active key's fingerprint to publish internally. Saving asks for the
  administrator's password again.

  @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Account recovery')"
		:description="
			t(
				'keepiq',
				'Let users who forgot their master password get their vault back, approved by recovery officers you name.',
			)
		">
		<div class="account-recovery" data-testid="account-recovery-section">
			<label class="account-recovery__field">
				<span>{{ t('keepiq', 'Policy') }}</span>
				<select v-model="policy" data-testid="account-recovery-policy">
					<option value="off">{{ t('keepiq', 'Off') }}</option>
					<option value="optional">
						{{ t('keepiq', 'Users may enrol') }}
					</option>
					<option value="required">
						{{ t('keepiq', 'Every user is enrolled') }}
					</option>
				</select>
			</label>
			<label class="account-recovery__field">
				<span>{{
					t('keepiq', 'Officers (user IDs, separated by commas)')
				}}</span>
				<input
					v-model="officersText"
					type="text"
					data-testid="account-recovery-officers" />
			</label>
			<label class="account-recovery__field">
				<span>{{ t('keepiq', 'Approvals needed') }}</span>
				<input
					v-model.number="threshold"
					type="number"
					min="1"
					:max="Math.max(1, officerList.length)"
					data-testid="account-recovery-threshold" />
			</label>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="account-recovery-save"
				@click="save">
				{{ t('keepiq', 'Save') }}
			</NcButton>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="activeKey">
				<p data-testid="account-recovery-fingerprint">
					{{
						t('keepiq', 'Recovery key fingerprint: {fingerprint}', {
							fingerprint: activeKey.fingerprint,
						})
					}}
				</p>
				<p class="account-recovery__hint">
					{{
						t(
							'keepiq',
							'Publish this fingerprint internally, so users can check it before they enrol.',
						)
					}}
				</p>
				<NcButton
					variant="secondary"
					:disabled="busy"
					data-testid="account-recovery-retire"
					@click="retire">
					{{ t('keepiq', 'Retire this recovery key') }}
				</NcButton>
			</template>
			<p v-else-if="policy !== 'off'" class="account-recovery__hint">
				{{
					t(
						'keepiq',
						'No recovery key yet. One of the officers creates it in their Keepiq settings.',
					)
				}}
			</p>
			<NcNoteCard
				v-if="removedOfficers.length > 0"
				type="warning"
				data-testid="account-recovery-removed">
				{{
					t(
						'keepiq',
						'Removed officers lose their copy now, but may have opened it before. Have an officer create a new recovery key.',
					)
				}}
			</NcNoteCard>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

const API = '/apps/keepiq/api/v1/recovery/admin'

export default {
	name: 'AccountRecoverySection',
	components: { CnSettingsSection, NcButton, NcNoteCard },

	data() {
		return {
			policy: 'off',
			officersText: '',
			threshold: 1,
			activeKey: null,
			removedOfficers: [],
			busy: false,
			error: null,
		}
	},

	computed: {
		officerList() {
			return this.officersText
				.split(',')
				.map((uid) => uid.trim())
				.filter((uid) => uid !== '')
		},
	},

	/**
	 * Load the current settings.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl(API))
			this.apply(response.data)
		} catch {
			// The section stays on its defaults.
		}
	},

	methods: {
		/**
		 * @param {object} data The settings from the server.
		 * @return {void}
		 */
		apply(data) {
			this.policy = data?.policy ?? 'off'
			this.officersText = (data?.officers ?? []).join(', ')
			this.threshold = data?.threshold ?? 1
			if ('activeKey' in (data ?? {})) {
				this.activeKey = data.activeKey
			}
		},

		/**
		 * Save after a password confirmation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
		 */
		async save() {
			this.busy = true
			this.error = null
			try {
				const { confirmPassword } =
					await import('@nextcloud/password-confirmation')
				await confirmPassword()
				const response = await axios.put(generateUrl(API), {
					policy: this.policy,
					officers: this.officerList,
					threshold: this.threshold,
				})
				this.removedOfficers = response.data?.removed ?? []
				this.apply(response.data)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Retire the active key after a password confirmation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
		 */
		async retire() {
			this.busy = true
			this.error = null
			try {
				const { confirmPassword } =
					await import('@nextcloud/password-confirmation')
				await confirmPassword()
				await axios.post(
					generateUrl(`${API}/keys/${this.activeKey.id}/retire`),
				)
				this.activeKey = null
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.account-recovery {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.account-recovery__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 400px;
}

.account-recovery__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
