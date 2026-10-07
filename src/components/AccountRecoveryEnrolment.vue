<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The user's enrolment in organisation account recovery
  (crypto-organisation-account-recovery D1, D5): status, the recovery
  certificate fingerprint, enrol with the master password, withdraw.

  @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
-->
<template>
	<div
		v-if="status && status.policy !== 'off'"
		class="recovery-enrolment"
		data-testid="recovery-enrolment">
		<h4>{{ t('keepiq', 'Account recovery') }}</h4>
		<p v-if="status.enrolled" data-testid="recovery-enrolment-enrolled">
			{{
				t(
					'keepiq',
					'You are enrolled. If you forget your master password, your organisation can help you get your vault back.',
				)
			}}
		</p>
		<p v-else>
			{{
				t(
					'keepiq',
					'Enrol so your organisation can help you get your vault back if you forget your master password.',
				)
			}}
		</p>
		<p
			v-if="fingerprint"
			class="recovery-enrolment__fingerprint"
			data-testid="recovery-enrolment-fingerprint">
			{{
				t('keepiq', 'Recovery key fingerprint: {fingerprint}', {
					fingerprint,
				})
			}}
		</p>
		<template v-if="!status.enrolled || !status.current">
			<NcPasswordField
				v-model="masterPassword"
				:label="t('keepiq', 'Master password')"
				autocomplete="current-password"
				:disabled="busy" />
			<NcButton
				variant="primary"
				:disabled="busy || masterPassword === '' || !status.key"
				data-testid="recovery-enrol"
				@click="enrol">
				{{ t('keepiq', 'Enrol in account recovery') }}
			</NcButton>
		</template>
		<NcButton
			v-if="status.enrolled && status.policy !== 'required'"
			variant="tertiary"
			:disabled="busy"
			data-testid="recovery-withdraw"
			@click="withdraw">
			{{ t('keepiq', 'Withdraw from account recovery') }}
		</NcButton>
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useAccountRecoveryStore } from '../store/modules/accountRecovery.js'

export default {
	name: 'AccountRecoveryEnrolment',
	components: { NcButton, NcNoteCard, NcPasswordField },

	data() {
		return {
			masterPassword: '',
			fingerprint: '',
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		store() {
			return useAccountRecoveryStore()
		},

		/**
		 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		status() {
			return this.store.status
		},
	},

	/**
	 * Load the status and check the certificate before showing its fingerprint.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	async created() {
		try {
			const status = await this.store.fetchStatus()
			if (status?.key) {
				this.fingerprint = await this.store.verifyKey(status.key)
			}
		} catch (e) {
			this.error = e?.message ?? null
		}
	},

	methods: {
		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async enrol() {
			this.busy = true
			this.error = null
			try {
				await this.store.enrol(this.masterPassword)
				this.masterPassword = ''
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * @return {Promise<void>}
		 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async withdraw() {
			this.busy = true
			this.error = null
			try {
				await this.store.withdraw()
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
.recovery-enrolment {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.recovery-enrolment__fingerprint {
	font-family: var(--font-face-mono, monospace);
	font-size: 12px;
	overflow-wrap: anywhere;
}
</style>
