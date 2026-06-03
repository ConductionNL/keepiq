<template>
	<div class="compromise-recovery-form">
		<h2>{{ t('doriath', 'Compromise recovery') }}</h2>

		<NcNoteCard type="warning">
			{{ t('doriath', 'This will generate a new encryption key pair and re-encrypt all your secrets. This process may take a moment.') }}
		</NcNoteCard>

		<NcPasswordField
			v-model="oldPassword"
			:label="t('doriath', 'Old (compromised) password')"
			:disabled="loading" />

		<NcPasswordField
			v-model="newPassword"
			:label="t('doriath', 'New password')"
			:disabled="loading" />

		<PasswordStrengthMeter
			v-if="newPassword"
			:password="newPassword"
			@strength-change="onStrengthChange" />

		<NcPasswordField
			v-model="confirmPassword"
			:label="t('doriath', 'Confirm new password')"
			:disabled="loading" />

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			{{ t('doriath', 'Key rotation complete. Your vault is now secured with a new encryption key.') }}
		</NcNoteCard>

		<NcButton
			v-if="!success"
			type="error"
			:disabled="!canSubmit || loading"
			@click="handleSubmit">
			{{ loading ? t('doriath', 'Rotating keys...') : t('doriath', 'Start key rotation') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import PasswordStrengthMeter from './PasswordStrengthMeter.vue'

export default {
	name: 'CompromiseRecoveryForm',
	components: { NcButton, NcNoteCard, NcPasswordField, PasswordStrengthMeter },

	data() {
		return {
			oldPassword: '',
			newPassword: '',
			confirmPassword: '',
			strengthValid: false,
			loading: false,
			error: null,
			success: false,
		}
	},

	computed: {
		/**
		 * Gate the compromise-recovery submit on matching, strength-valid input.
		 *
		 * @return {boolean} True when recovery may be submitted.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		canSubmit() {
			return this.oldPassword
				&& this.newPassword
				&& this.confirmPassword
				&& this.newPassword === this.confirmPassword
				&& this.strengthValid
		},
	},

	methods: {
		/**
		 * Track password-strength validity from the strength meter.
		 *
		 * @param {object} root0 Strength event.
		 * @param {boolean} root0.isValid Whether the new password meets the floor.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},

		/**
		 * Initiate compromise recovery: full key rotation + secret migration
		 * from the old (leaked) master password to the new one.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleSubmit() {
			this.loading = true
			this.error = null

			try {
				const store = useEncryptionSuiteStore()
				await store.initiateCompromiseRecovery(this.oldPassword, this.newPassword)
				this.success = true
				this.oldPassword = ''
				this.newPassword = ''
				this.confirmPassword = ''
			} catch (e) {
				this.error = e.message || t('doriath', 'Failed to start recovery')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
