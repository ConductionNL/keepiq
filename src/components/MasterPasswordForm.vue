<template>
	<div class="master-password-form">
		<h2>{{ t('doriath', 'Change master password') }}</h2>

		<NcPasswordField
			v-model="currentPassword"
			:label="t('doriath', 'Current password')"
			:disabled="loading" />

		<NcPasswordField
			v-model="newPassword"
			:label="t('doriath', 'New password')"
			:disabled="loading" />

		<PasswordStrengthMeter
			v-if="newPassword"
			:password="newPassword"
			@strengthChange="onStrengthChange" />

		<NcPasswordField
			v-model="confirmPassword"
			:label="t('doriath', 'Confirm new password')"
			:disabled="loading" />

		<NcNoteCard
			v-if="confirmPassword && newPassword !== confirmPassword"
			type="error">
			{{ t('doriath', 'Passwords do not match') }}
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			{{ t('doriath', 'Master password changed successfully') }}
		</NcNoteCard>

		<NcButton
			variant="primary"
			:disabled="!canSubmit || loading"
			@click="handleSubmit">
			{{
				loading ? t('doriath', 'Changing…') : t('doriath', 'Change password')
			}}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import PasswordStrengthMeter from './PasswordStrengthMeter.vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'

export default {
	name: 'MasterPasswordForm',
	components: { NcButton, NcNoteCard, NcPasswordField, PasswordStrengthMeter },

	data() {
		return {
			currentPassword: '',
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
		 * Gate the routine password-change submit on matching, strength-valid input.
		 *
		 * @return {boolean} True when the change may be submitted.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		canSubmit() {
			return (
				this.currentPassword
				&& this.newPassword
				&& this.confirmPassword
				&& this.newPassword === this.confirmPassword
				&& this.strengthValid
			)
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
		 * Submit a routine master-password change (re-wraps the private key,
		 * no key rotation), surfacing success/error to the UI.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
		 */
		async handleSubmit() {
			this.loading = true
			this.error = null
			this.success = false

			try {
				const store = useEncryptionSuiteStore()
				await store.changePassword(this.currentPassword, this.newPassword)
				this.success = true
				this.currentPassword = ''
				this.newPassword = ''
				this.confirmPassword = ''
			} catch (e) {
				this.error = e.message || t('doriath', 'Failed to change password')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
