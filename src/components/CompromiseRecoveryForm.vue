<template>
	<div class="compromise-recovery-form">
		<h2>{{ t('doriath', 'Compromise recovery') }}</h2>

		<NcNoteCard type="warning">
			{{ t('doriath', 'This will generate a new encryption key pair and re-encrypt all your secrets. This process may take a moment.') }}
		</NcNoteCard>

		<NcPasswordField
			:value.sync="oldPassword"
			:label="t('doriath', 'Old (compromised) password')"
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

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcButton
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
		}
	},

	computed: {
		canSubmit() {
			return this.oldPassword
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

			try {
				const store = useEncryptionSuiteStore()
				await store.initiateCompromiseRecovery(this.oldPassword, this.newPassword)
				this.$emit('recovery-started')
			} catch (e) {
				this.error = e.message || t('doriath', 'Failed to start recovery')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
