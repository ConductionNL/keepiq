<template>
	<CnSettingsSection
		:name="t('doriath', 'Password Policy')"
		:description="t('doriath', 'Configure master password requirements for all users')">
		<div class="password-policy">
			<div class="password-policy__field">
				<label for="min-length">{{ t('doriath', 'Minimum length') }}</label>
				<input
					id="min-length"
					v-model.number="minLength"
					type="number"
					min="12"
					max="20"
					@change="save">
				<span class="password-policy__hint">{{ t('doriath', '12–20 characters') }}</span>
			</div>

			<div class="password-policy__field">
				<label for="min-score">{{ t('doriath', 'Minimum strength score') }}</label>
				<select id="min-score" v-model.number="minScore" @change="save">
					<option :value="3">{{ t('doriath', 'Strong (score 3)') }}</option>
					<option :value="4">{{ t('doriath', 'Very strong (score 4)') }}</option>
				</select>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PasswordPolicySection',
	components: { CnSettingsSection },

	data() {
		return {
			minLength: 12,
			minScore: 3,
		}
	},

	async created() {
		const response = await axios.get(generateUrl('/apps/doriath/api/settings'))
		const settings = response.data
		this.minLength = parseInt(settings.master_password_min_length) || 12
		this.minScore = parseInt(settings.master_password_min_score) || 3
	},

	methods: {
		async save() {
			await axios.post(generateUrl('/apps/doriath/api/settings'), {
				master_password_min_length: String(Math.min(20, Math.max(12, this.minLength))),
				master_password_min_score: String(Math.min(4, Math.max(3, this.minScore))),
			})
		},
	},
}
</script>

<style scoped>
.password-policy__field {
	margin-bottom: 1rem;
}
.password-policy__field label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
.password-policy__hint {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}
</style>
