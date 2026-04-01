<template>
	<div class="master-password-form">
		<h2>{{ t('doriath', 'Change master password') }}</h2>

		<NcPasswordField
			:value.sync="currentPassword"
			:label="t('doriath', 'Current password')"
			:disabled="loading" />

		<NcPasswordField
			:value.sync="newPassword"
			:label="t('doriath', 'New password')"
			:disabled="loading" />

		<PasswordStrengthMeter
			v-if="newPassword"
			:password="newPassword"
			@strength-change="onStrengthChange" />

		<NcPasswordField
			:value.sync="confirmPassword"
			:label="t('doriath', 'Confirm new password')"
			:disabled="loading" />

		<NcNoteCard v-if="confirmPassword && newPassword !== confirmPassword" type="error">
			{{ t('doriath', 'Passwords do not match') }}
		</NcNoteCard>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="success" type="success">
			{{ t('doriath', 'Master password changed successfully') }}
		</NcNoteCard>

		<NcButton
			type="primary"
			:disabled="!canSubmit || loading"
			@click="handleSubmit">
			{{ loading ? t('doriath', 'Changing...') : t('doriath', 'Change password') }}
		</NcButton>
	</div>
</template>

<script>
import { NcButton, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useEncryptionSuiteStore } from '../store/modules/encryptionSuite.js'
import PasswordStrengthMeter from './PasswordStrengthMeter.vue'

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
		canSubmit() {
			return this.currentPassword
				&& this.newPassword
				&& this.confirmPassword
				&& this.newPassword === this.confirmPassword
				&& this.strengthValid
		},
	},

	methods: {
		onStrengthChange({ isValid }) {
			this.strengthValid = isValid
		},

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
